<?php

namespace Modules\Zkteco\Protocol;

use Modules\Zkteco\Contracts\Terminal;

/** A real terminal through ZkClient (the ZK protocol on port 4370). */
class ZkTerminal implements Terminal
{
    public function __construct(private ZkClient $client) {}

    public function connect(): void
    {
        $this->client->connect();
    }

    public function facts(): array
    {
        $sizes = $this->client->sizes();

        return [
            'serial' => $this->client->option('~SerialNumber'),
            'model' => $this->client->option('~DeviceName'),
            'users' => $sizes['users'],
            'logs' => $sizes['logs'],
        ];
    }

    public function users(): array
    {
        return array_map(fn (array $user) => ['uid' => $user['uid'], 'user_id' => $user['user_id'], 'name' => $user['name']], $this->client->users());
    }

    public function attendance(): array
    {
        return array_map(fn (array $log) => ['user_id' => $log['user_id'], 'at' => $log['at'], 'status' => $log['status'], 'punch' => $log['punch']], $this->client->attendance());
    }

    public function disconnect(): void
    {
        $this->client->disconnect();
    }
}
