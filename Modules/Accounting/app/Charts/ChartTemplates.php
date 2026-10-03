<?php

namespace Modules\Accounting\Charts;

use Modules\Accounting\Enums\AccountType;
use RuntimeException;

/**
 * Chart of accounts templates (database/data/charts/{key}.php). A template
 * may extend another: its accounts are added, "remove" drops codes of the
 * parent. Read once per process; a broken file fails loudly (and in tests).
 */
class ChartTemplates
{
    /** @var array<string, array<string, mixed>>|null */
    private ?array $files = null;

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_keys($this->files());
        sort($keys);

        return $keys;
    }

    public function has(string $key): bool
    {
        return isset($this->files()[$key]);
    }

    /**
     * The template's accounts in creation order (groups before what they hold),
     * each with its type resolved from its group.
     *
     * @return list<array{code: string, name: array<string, string>, type: AccountType, group: bool, parent: string|null}>
     */
    public function accounts(string $key): array
    {
        $rows = [];
        foreach ($this->chain($key) as $file) {
            foreach ($file['remove'] ?? [] as $code) {
                unset($rows[(string) $code]);
            }
            foreach ($file['accounts'] as $account) {
                $rows[(string) $account['code']] = $account;
            }
        }

        $resolved = [];
        foreach ($rows as $code => $account) {
            $parent = isset($account['parent']) ? (string) $account['parent'] : null;
            if ($parent !== null && ! isset($resolved[$parent])) {
                throw new RuntimeException("Chart template [{$key}]: account {$code} comes before or without its group {$parent}.");
            }

            $type = $parent === null ? AccountType::from($account['type']) : $resolved[$parent]['type'];
            $resolved[$code] = [
                'code' => (string) $code,
                'name' => $account['name'],
                'type' => $type,
                'group' => (bool) ($account['group'] ?? false),
                'parent' => $parent,
            ];
        }

        // Groups come before what they hold (checked above), so they can be created in this order.
        return array_values($resolved);
    }

    /**
     * @return array<string, string> Posting key => account code.
     */
    public function postings(string $key): array
    {
        $postings = [];
        foreach ($this->chain($key) as $file) {
            $postings = [...$postings, ...($file['postings'] ?? [])];
        }

        return $postings;
    }

    /**
     * The template and the ones it extends, the most general first.
     *
     * @return list<array<string, mixed>>
     */
    private function chain(string $key): array
    {
        $chain = [];
        $seen = [];
        while ($key !== null) {
            if (! $this->has($key) || isset($seen[$key])) {
                throw new RuntimeException("Unknown or circular chart template [{$key}].");
            }
            $seen[$key] = true;
            $file = $this->files()[$key];
            array_unshift($chain, $file);
            $key = $file['extends'] ?? null;
        }

        return $chain;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function files(): array
    {
        if ($this->files === null) {
            $this->files = [];
            foreach (glob(dirname(__DIR__, 2).'/database/data/charts/*.php') ?: [] as $path) {
                $file = require $path;
                $this->files[$file['key']] = $file;
            }
        }

        return $this->files;
    }
}
