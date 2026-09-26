<?php

namespace App\Platform\Billing\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of an invoice or credit note. The description is frozen in every
 * supported language when the document is issued.
 */
#[Table('invoice_lines')]
class InvoiceLine extends Model
{
    use HasUlids;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'description' => 'array',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'amount_minor' => 'integer',
        ];
    }

    public function text(?string $locale = null): string
    {
        $locale ??= app()->getLocale();

        return (string) ($this->description[$locale] ?? $this->description['en'] ?? '');
    }
}
