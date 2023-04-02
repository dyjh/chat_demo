#!/usr/bin/env php
<?php

use Hyperf\Contract\StdoutLoggerInterface;
use Swoole\Table;

/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
ini_set('display_errors', 'on');
ini_set('display_startup_errors', 'on');
ini_set('memory_limit', '1G');

error_reporting(E_ALL);

! defined('BASE_PATH') && define('BASE_PATH', dirname(__DIR__, 1));
! defined('SWOOLE_HOOK_FLAGS') && define('SWOOLE_HOOK_FLAGS', SWOOLE_HOOK_ALL);

require BASE_PATH . '/vendor/autoload.php';

// Self-called anonymous function that creates its own scope and keep the global namespace clean.
(function () {
    Hyperf\Di\ClassLoader::init();
    /** @var Psr\Container\ContainerInterface $container */
    $container = require BASE_PATH . '/config/container.php';

    // 创建共享内存实例
    $table = new Table(2048);
    $table->column('onlineStaff', Table::TYPE_STRING, 1024);
    $table->column('customers', Table::TYPE_STRING, 1024);
    $table->create();

    // 将 Table 实例绑定到容器中，以便在 WebSocket 服务中使用
    $container->set(Table::class, $table);

    $application = $container->get(Hyperf\Contract\ApplicationInterface::class);
    $application->run();
})();

