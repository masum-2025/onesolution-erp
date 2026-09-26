<?php

namespace App\Platform\Transfers\Services;

use App\Models\User;
use App\Platform\Audit\AuditLogger;
use App\Platform\Rules\RuleContextFactory;
use App\Platform\Rules\RuleResolver;
use App\Platform\Tenancy\Models\Partner;
use App\Platform\Transfers\Models\TransferCode;

/**
 * One-time codes a partner gives a client that wants to move to it:
 * XXXX-XXXX-XXXX from an alphabet without look-alike characters. Only the
 * hash is stored; the partner sees the code once.
 */
class TransferCodes
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public function __construct(private RuleResolver $rules, private RuleContextFactory $contexts, private AuditLogger $audit) {}

    /**
     * @return array{code: string, record: TransferCode}
     */
    public function create(Partner $partner, ?string $label, User $actor): array
    {
        $code = implode('-', array_map(fn () => $this->chunk(), range(1, 3)));
        $days = (int) $this->rules->get('partners.transfer_code_days', $this->contexts->forPartner($partner));

        $record = new TransferCode;
        $record->forceFill([
            'partner_id' => $partner->getKey(),
            'code_hash' => self::hash($code),
            'label' => $label,
            'hint' => substr($code, -4),
            'expires_at' => now()->addDays($days),
            'created_by' => $actor->getKey(),
        ])->save();

        $this->audit->record(action: 'partner.transfer_code_created', target: $record, new: ['label' => $label, 'hint' => $record->hint, 'expires_at' => $record->expires_at->toIso8601String()], actor: $actor, partnerId: $partner->getKey());

        return ['code' => $code, 'record' => $record];
    }

    public function find(string $code): ?TransferCode
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
        if (strlen($normalized) !== 12) {
            return null;
        }

        return TransferCode::query()->where('code_hash', self::hash(implode('-', str_split($normalized, 4))))->first();
    }

    public function revoke(TransferCode $code, User $actor): void
    {
        $code->forceFill(['revoked_at' => now()])->save();
        $this->audit->record(action: 'partner.transfer_code_revoked', target: $code, new: ['hint' => $code->hint], actor: $actor, partnerId: $code->partner_id);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', $code);
    }

    private function chunk(): string
    {
        $chunk = '';
        for ($i = 0; $i < 4; $i++) {
            $chunk .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $chunk;
    }
}
