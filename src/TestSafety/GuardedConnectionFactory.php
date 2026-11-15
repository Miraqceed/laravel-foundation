<?php

namespace Miraqceed\Foundation\TestSafety;

use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;

/**
 * db.factory を包み、テストの中では接続を作る前に設定を確かめる（TestDatabaseGuard::checkConfig）。
 * テストの外では元の factory にそのまま渡す。
 *
 * DatabaseManager は接続を作るとき（connection・reconnect・connectUsing・build、`::read`・`::write` も）いつも
 * factory の make を呼ぶ。知らせ（ConnectionEstablished）は、reconnect では古い設定の接続で出し、
 * Event::fake() の後は聞き手に届かず、`::read` では PDO を開いた後に出るので、ここを主な口にする。
 */
class GuardedConnectionFactory extends ConnectionFactory
{
    public function __construct(Container $container, private readonly ConnectionFactory $inner)
    {
        parent::__construct($container);
    }

    public function make(array $config, $name = null)
    {
        if (TestDatabaseGuard::runningTests()) {
            TestDatabaseGuard::checkConfig($config, (string) ($name ?? $config['name'] ?? ''));
        }

        return $this->inner->make($config, $name);
    }

    public function createConnector(array $config)
    {
        return $this->inner->createConnector($config);
    }
}
