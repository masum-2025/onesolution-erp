<?php

namespace Modules\Zkteco\Contracts;

/**
 * A terminal as the sync sees it: open, say what it is, list its users and
 * its attendance logs, close. Reading only. The real one speaks the ZK
 * protocol (ZkTerminal); tests give a fake through TerminalFactory.
 */
interface Terminal
{
    /** @throws \RuntimeException when it cannot be reached or refuses */
    public function connect(): void;

    /** @return array{serial: ?string, model: ?string, users: int, logs: int} */
    public function facts(): array;

    /** @return list<array{uid: int, user_id: string, name: string}> */
    public function users(): array;

    /** @return list<array{user_id: string, at: string, status: int, punch: int}> "at" as the terminal's clock wrote it (Y-m-d H:i:s) */
    public function attendance(): array;

    public function disconnect(): void;
}
