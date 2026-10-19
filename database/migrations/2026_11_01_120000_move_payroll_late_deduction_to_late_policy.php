<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
 * POL-1: rule payroll.late_deduction (true = cut each minute late) became
 * payroll.late_policy (none / per_minute / count). Every value set for the old
 * rule, at any level and in any state, is copied to the new rule (true ->
 * per_minute, false -> none), with a history entry, so nobody's pay changes.
 * The old rows stay (the old rule is marked deprecated by rules:sync).
 * Down removes only the copies this made.
 */
return new class extends Migration
{
    private const REASON = 'Moved from payroll.late_deduction (POL-1)';

    public function up(): void
    {
        $now = now();
        $rows = DB::table('rule_values')->where('rule_key', 'payroll.late_deduction')->orderBy('id')->get();

        foreach ($rows as $row) {
            $value = $this->convert(json_decode((string) $row->value, true));
            $id = (string) Str::ulid();
            DB::table('rule_values')->insert([
                'id' => $id,
                'rule_key' => 'payroll.late_policy',
                'scope_type' => $row->scope_type,
                'scope_id' => $row->scope_id,
                'country_code' => $row->country_code,
                'mode' => $row->mode,
                'value' => json_encode($value),
                'effective_from' => $row->effective_from,
                'effective_to' => $row->effective_to,
                'version' => $row->version,
                'status' => $row->status,
                'created_by' => $row->created_by,
                'approved_by' => $row->approved_by,
                'reviewed_at' => $row->reviewed_at,
                'reason' => self::REASON,
                'review_reason' => $row->review_reason,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('rule_value_history')->insert([
                'id' => (string) Str::ulid(),
                'rule_value_id' => $id,
                'rule_key' => 'payroll.late_policy',
                'scope_type' => $row->scope_type,
                'scope_id' => $row->scope_id,
                'action' => 'created',
                'old_status' => null,
                'new_status' => $row->status,
                'snapshot' => json_encode(['mode' => $row->mode, 'value' => $value, 'country_code' => $row->country_code, 'version' => $row->version, 'from' => $row->id]),
                'actor_user_id' => null,
                'reason' => self::REASON,
                'created_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('rule_values')->where('rule_key', 'payroll.late_policy')->where('reason', self::REASON)->pluck('id');
        DB::table('rule_value_history')->whereIn('rule_value_id', $ids)->delete();
        DB::table('rule_values')->whereIn('id', $ids)->delete();
    }

    /** true -> per_minute, false -> none; an allowed list is converted item by item. */
    private function convert(mixed $value): mixed
    {
        if (is_array($value) && array_key_exists('allowed', $value)) {
            return ['allowed' => array_values(array_unique(array_map(fn ($item) => $item ? 'per_minute' : 'none', (array) $value['allowed'])))];
        }

        return $value ? 'per_minute' : 'none';
    }
};
