<?php

namespace App\Service;

use Hyperf\Logger\LoggerFactory;
use Hyperf\WebSocketServer\Sender;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Psr\Log\LoggerInterface;
use Swoole\Table;

class ChatService
{

    /**
     * 日志类
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * swoole 共享内存
     * @var Table
     */
    private Table $table;

    /**
     * 服务容器
     * @var ContainerInterface
     */
    private ContainerInterface $container;

    private \Swoole\Lock $lock;

    public function __construct(ContainerInterface $container, LoggerFactory $loggerFactory, Table $table)
    {
        // 设置共享锁，用于保护多线程的共享资源读写安全
        $this->lock = new \Swoole\Lock(SWOOLE_MUTEX);
        $this->logger = $loggerFactory->get('log', 'default');
        $this->table = $table;
        $this->container = $container;
    }

    /**
     * 客服上线
     *
     * @param int $fd
     * @param string $name
     * @return void
     */
    public function staffOnline(int $fd, string $name)
    {
        $this->saveData('onlineStaff', $fd, [
            'name' => $name,
            'customer_fd' => null,
            'queue' => []
        ]);
    }

    /**
     * 客服下线
     *
     * @param int $fd
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function staffOffline(int $fd): void
    {
        $staff = $this->findData('onlineStaff', $fd);
        if ($staff) {
            $this->removeAllCustomer($staff['customer_fd'], $staff['queue']);
            $this->del('onlineStaff', $fd);
        }
    }

    /**
     * 新建客户
     *
     * @param int $fd
     * @return void
     */
    public function addCustomer(int $fd): void
    {
        $this->saveData('customers', $fd, [
            'staff_fd' => null,
            'timeout_timer' => null,
        ]);
        $this->logger->info(json_encode($this->findData('customers')));
    }

    /**
     * 移除客户
     *
     * @param int $fd
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function removeCustomer(int $fd): void
    {
        $customer = $this->findData('customers', $fd);
        if ($customer && $customer['staff_fd']) {
            $this->freeStaff($customer['staff_fd'], $fd);
        }
        if ($customer && $customer['timeout_timer']) {
            // 清除计时器
            swoole_timer_clear($customer['timeout_timer']);
        }
        $this->del('customers', $fd);
    }

    /**
     * 移除所有跟下线客服关联的客户
     *
     * @param int $fd
     * @param array $queue
     * @return void
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function removeAllCustomer(int $fd, array $queue): void
    {
        $this->container->get(Sender::class)->push($fd, json_encode([
            'action' => 'chat_close',
            'payload' => [
                'message' => '当前客服已下线，聊天结束',
            ],
        ]));
        $this->del('customers', $fd);
        foreach ($queue as $q_fd) {
            $this->container->get(Sender::class)->push($q_fd, json_encode([
                'action' => 'queue_close',
                'payload' => [
                    'message' => '当前客服已下线，是否连接新的客服？',
                ],
            ]));
            $this->del('customers', $q_fd);
        }
    }

    /**
     * 建立聊天通道
     *
     * @param int $customerFd
     * @param int $staffFd
     * @return void
     */
    public function setStaffForCustomer(int $customerFd, int $staffFd): void
    {
        $this->bindStaffToCustomer($customerFd, $staffFd);

        $staff = $this->findData('onlineStaff', $staffFd);
        $staff['customer_fd'] = $customerFd;
        $this->saveData('onlineStaff', $staffFd, $staff);
    }

    /**
     * 客户加入排队
     *
     * @param int $customerFd
     * @param int $staffFd
     * @return void
     */
    public function setCustomerQueueUp(int $customerFd, int $staffFd): void
    {
        $this->bindStaffToCustomer($customerFd, $staffFd);

        $staff = $this->findData('onlineStaff', $staffFd);
        $staff['queue'][] = $customerFd;
        $this->saveData('onlineStaff', $staffFd, $staff);
    }

    /**
     * 聊天结束释放客服，并且更新排队信息
     *
     * @param int $staffFd
     * @param int $fd
     * @return void
     * @throws ContainerExceptionInterface
     * @throws NotFoundExceptionInterface
     */
    public function freeStaff(int $staffFd, int $fd): void
    {
        $staff = $this->findData('onlineStaff', $staffFd);
        if ($fd === $staff['customer_fd']) {
            if (count($staff['queue']) > 0) {
                $staff['customer_fd'] = $staff['queue'][0];

                $this->container->get(Sender::class)->push($staff['customer_fd'], json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => '排队结束',
                    ],
                ]));
                $this->resetCustomerTimer($staff['customer_fd'], 60000); // 设置超时为1分钟（60000毫秒）

                $this->container->get(Sender::class)->push($staffFd, json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => '新客户已接入，可以开始聊天了',
                    ],
                ]));

                unset($staff['queue'][0]);
                $staff['queue'] = array_values($staff['queue']);
                foreach ($staff['queue'] as $key => $customerFd) {
                    $this->container->get(Sender::class)->push($customerFd, json_encode([
                        'action' => 'message',
                        'payload' => [
                            'message' => '剩余排队人数: ' . ((int)$key + 1),
                        ],
                    ]));
                }
            } else {
                $staff['customer_fd'] = null;
            }
        } else {
            unset($staff['queue'][array_search($fd, $staff['queue'])]);
            $staff['queue'] = array_values($staff['queue']);
            foreach ($staff['queue'] as $key => $customerFd) {
                $this->container->get(Sender::class)->push($customerFd, json_encode([
                    'action' => 'message',
                    'payload' => [
                        'message' => '剩余排队人数: ' . ((int)$key + 1),
                    ],
                ]));
            }
        }


        $this->saveData('onlineStaff', $staffFd, $staff);
    }

    /**
     * 重置计时器
     *
     * @param int $fd
     * @param int $timeout
     * @return void
     */
    public function resetCustomerTimer(int $fd, int $timeout): void
    {
        $customer = $this->findData('customers', $fd);
        if ($customer) {
            if ($customer['timeout_timer']) {
                swoole_timer_clear($customer['timeout_timer']);
            }
            $customer['timeout_timer'] = swoole_timer_after($timeout, function () use ($fd) {
                $this->removeCustomer($fd);
            });
            $this->saveData('customers', $fd, $customer);
        }
    }

    /**
     * 获取可聊天/可排队的客服
     *
     * @return array
     */
    public function findAvailableStaff(): array
    {
        $minQueue = null;
        $selectedStaffFd = null;

        $onlineStaff = $this->findData('onlineStaff');
        $freeStaff = [];
        $queueStaff = [];
        foreach ($onlineStaff as $fd => $staff) {
            $staff['fd'] = $fd;
            // 若有空闲客服直接进入聊天
            if ($staff['customer_fd'] === null && count($staff['queue']) === 0) {
                $freeStaff[] = $staff;
            } else {
                $queueStaff[] = $staff;
            }
        }
        if (count($freeStaff) > 0) {
            $selectedStaffFd = $freeStaff[0]['fd'];
        }

        if (count($freeStaff) === 0 && count($queueStaff) > 0) {
            array_multisort(array_column($queueStaff, 'queue'), SORT_ASC, $queueStaff);
            $selectedStaffFd = $queueStaff[0]['fd'];
            $minQueue = count($queueStaff[0]['queue']);
        }
        return compact('minQueue', 'selectedStaffFd');
    }

    /**
     * 获取客户信息
     *
     * @param int $fd
     * @return array|null
     */
    public function getCustomer(int $fd): ?array
    {
        return $this->findData('customers', $fd);
    }

    /**
     * 获取客服信息
     *
     * @param int $fd
     * @return array|null
     */
    public function getStaff(int $fd): ?array
    {
        return $this->findData('onlineStaff', $fd);
    }

    /**
     * 给客户分配客服
     *
     * @param int $customerFd
     * @param int $staffFd
     * @return void
     */
    private function bindStaffToCustomer (int $customerFd, int $staffFd): void
    {
        $customer = $this->findData('customers', $customerFd);
        $this->logger->info('查询客户数据'. json_encode($customer));
        $customer['staff_fd'] = $staffFd;
        $this->logger->info('修改客户数据'. json_encode($customer));
        $this->saveData('customers', $customerFd, $customer);
    }

    /**
     * 从共享内存查询数据
     *
     * @param string $key
     * @param int|null $id
     * @return mixed|null
     */
    private function findData(string $key, int $id = null): mixed
    {
        $this->lock->lock();
        $data = json_decode($this->table->get('user', $key), true);
        $this->lock->unlock();
        if ($id) {
            return $data[$id] ?? null;
        } else {
            return $data ?? [];
        }
    }

    /**
     * 更新数据到共享内存
     *
     * @param string $key
     * @param int $id
     * @param array $body
     * @return void
     */
    private function saveData(string $key, int $id, array $body): void
    {
        $this->lock->lock();
        $data = json_decode($this->table->get('user', $key), true);
        $data[$id] = $body;
        $this->table->set('user', [$key => json_encode($data)]);
        $this->lock->unlock();
    }

    /**
     * 从共享内存中删除数据
     *
     * @param string $key
     * @param int $id
     * @return void
     */
    private function del(string $key, int $id): void
    {
        $this->lock->lock();
        $data = json_decode($this->table->get('user', $key), true);
        unset($data[$id]);
        $this->table->set('user', [$key => json_encode($data)]);
        $this->lock->unlock();
    }
}
