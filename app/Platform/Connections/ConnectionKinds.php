<?php

namespace App\Platform\Connections;

use App\Platform\Connections\Contracts\ConnectionMessageHandler;
use App\Platform\Connections\Models\ConnectionMessage;
use App\Platform\Modules\ModuleRegistry;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * The kinds of message business connections carry, from module manifests
 * ("connection_kinds" => [HandlerClass::class, ...]). A kind is usable only
 * while its module is on at the receiving side (checked at delivery) and at
 * the sending side (checked when sending).
 */
class ConnectionKinds
{
    /** @var array<string, array{module: string, handler: class-string<ConnectionMessageHandler>}> */
    private array $kinds = [];

    private bool $loaded = false;

    public function __construct(private Container $container, private ModuleRegistry $modules) {}

    /**
     * @param  class-string<ConnectionMessageHandler>  $handler
     */
    public function register(string $moduleKey, string $handler): void
    {
        $instance = $this->container->make($handler);
        $key = $instance->key();
        if (! preg_match('/^[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*$/', $key)) {
            throw new LogicException("Connection message kind [{$key}] must look like module.kind.");
        }
        if (! in_array($instance->direction(), [ConnectionMessage::TO_CLIENT, ConnectionMessage::TO_PROVIDER], true)) {
            throw new LogicException("Connection message kind [{$key}] must go to_client or to_provider.");
        }

        $this->kinds[$key] = ['module' => $moduleKey, 'handler' => $handler];
    }

    public function has(string $key): bool
    {
        $this->load();

        return isset($this->kinds[$key]);
    }

    public function module(string $key): string
    {
        $this->load();

        return $this->kinds[$key]['module'] ?? throw new LogicException("Unknown connection message kind [{$key}].");
    }

    public function handler(string $key): ConnectionMessageHandler
    {
        $this->load();

        return $this->container->make($this->kinds[$key]['handler'] ?? throw new LogicException("Unknown connection message kind [{$key}]."));
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }
        $this->loaded = true;

        foreach ($this->modules->all() as $module) {
            foreach ($module->connectionKinds as $handler) {
                $this->register($module->key, $handler);
            }
        }
    }
}
