<?php

namespace Modules\Accounting\Ledger;

use Modules\Accounting\Models\Journal;

/**
 * Ledger's answer: the journal, its number once posted, and whether it is
 * posted or waiting for approval (amounts above the company's approval rule).
 */
final readonly class PostedJournal
{
    public function __construct(
        public string $id,
        public ?string $number,
        public string $status,
    ) {}

    public static function of(Journal $journal): self
    {
        return new self($journal->getKey(), $journal->number, $journal->status->value);
    }

    public function isPosted(): bool
    {
        return $this->status === 'posted';
    }
}
