<?php

namespace App\Controller;

use App\Service\ChatService;
use Hyperf\Contract\OnCloseInterface;
use Hyperf\Contract\OnMessageInterface;
use Hyperf\Logger\LoggerFactory;
use Hyperf\WebSocketServer\Sender;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Response;
use Swoole\WebSocket\Server;
use Swoole\WebSocket\Frame;

class WebSocketController implements OnMessageInterface, OnCloseInterface
{

    /**
     * 聊天服务
     * @Inject
     * @var ChatService
     */
    private ChatService $chatService;

    /**
     * 服务容器
     * @var ContainerInterface
     */
    protected ContainerInterface $container;

    /**
     * 日志类
     * @var LoggerInterface
     */
    protected LoggerInterface $logger;

    public function __construct(ContainerInterface $container, LoggerFactory $loggerFactory)
    {
        $this->container = $container;
        $this->chatService = $container->get(ChatService::class);
        $this->logger = $loggerFactory->get('log', 'default');
    }

    /**
     * 消息推送监听
     *
     * @param Response|Server $server
     * @param Frame $frame
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function onMessage($server, $frame): void
    {
        $data = json_decode($frame->data, true);
        $action = $data['action'] ?? null;

        if ($action === 'staffOnline') {
            // 客服上线
            $this->handleStaffOnline($frame->fd, $data['payload']['name'] ?? '客服');
        } elseif ($action === 'customerConnect') {
            // 客户请求建立连接
            $this->handleCustomerConnect($frame->fd);
        } elseif ($action === 'message') {
            // 消息互发
            $message = $data['payload']['message'] ?? '';
            $this->handleMessage($frame->fd, $message);
        }
    }

    /**
     * 对象断开连接回调
     *
     * @param Response|Server $server
     * @param int $fd
     * @param int $reactorId
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function onClose($server, int $fd, int $reactorId): void
    {
        // 处理客服或者客户断开连接的逻辑
        $this->logger->info('断开连接触发:'. $fd. "|" . $reactorId);
        $customer = $this->chatService->getCustomer($fd);
        if ($customer) {
            $this->chatService->removeCustomer($fd);
        }

        $staff = $this->chatService->getStaff($fd);
        if ($staff) {
            $this->chatService->staffOffline($fd);
        }
    }

    /**
     * 客户连接逻辑处理
     *
     * @param int $fd
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function handleCustomerConnect(int $fd): void
    {
        // 在此可自行增加参数实现客户身份校验
        $this->chatService->addCustomer($fd);
        $availableData = $this->chatService->findAvailableStaff();
        $this->logger->info('匹配结果:'. json_encode($availableData));

        // selectedStaffFd 为null则说明无在线客服
        if ($availableData['selectedStaffFd'] === null) {
            $this->container->get(Sender::class)->push($fd, json_encode([
                'action' => 'message',
                'payload' => [
                    'message' => '当前暂无客服上班',
                ],
            ]));
            $this->chatService->removeCustomer($fd);
            return;
        }

        // minQueue 不为null则说明需要排队
        if ($availableData['minQueue'] !== null) {
            $this->chatService->setCustomerQueueUp($fd, $availableData['selectedStaffFd']);
            $this->container->get(Sender::class)->push($fd, json_encode([
                'action' => 'message',
                'payload' => [
                    'message' => '当前暂无空闲客服，已为您自动分配排队较少客服，请等待排队',
                    'queue' => $availableData['minQueue'] + 1
                ],
            ]));
            return;
        }

        $this->chatService->setStaffForCustomer($fd, $availableData['selectedStaffFd']);
        $this->chatService->resetCustomerTimer($fd, 60000); // 设置超时为1分钟（60000毫秒）

        $this->container->get(Sender::class)->push($fd, json_encode([
            'action' => 'message',
            'payload' => [
                'message' => '您已连接到客服',
            ],
        ]));
    }

    /**
     * 客服断开连接
     *
     * @param int $fd
     * @param string $name
     * @return void
     */
    private function handleStaffOnline(int $fd, string $name): void
    {
        // 若是数据库的话，可将name替换成客服登录token之类的，实现登录校验
        $this->chatService->staffOnline($fd, $name);
    }

    /**
     * 消息处理逻辑
     *
     * @param int $fd
     * @param string $message
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    private function handleMessage(int $fd, string $message): void
    {
        $customer = $this->chatService->getCustomer($fd);
        if ($customer && $customer['staff_fd']) {
            $staff = $this->chatService->getStaff($customer['staff_fd']);
            // 判断是否还在排队
            if ($staff['customer_fd'] != $fd) {
                $this->container->get(Sender::class)->push($fd, json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => '当前正在排队中',
                    ],
                ]));
            } else {
                // 如果是客户发来的消息，则转发给客服
                $this->container->get(Sender::class)->push($customer['staff_fd'], json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => $message,
                        'from' => 'customer',
                    ],
                ]));

                // 重置客户的超时计时器
                $this->chatService->resetCustomerTimer($fd, 60000);
            }
            // 在此可实现聊天记录留存
            // ...
        } else {
            $staff = $this->chatService->getStaff($fd);
            if ($staff && $staff['customer_fd']) {
                // 如果是客服发来的消息，则转发给客户
                $this->container->get(Sender::class)->push($staff['customer_fd'], json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => $message,
                        'from' => 'staff',
                    ],
                ]));
            }
            // 在此可实现聊天记录留存
            // ...
        }
    }

}
