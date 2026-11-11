<?php

namespace Modules\Pharmacy\Services;

use App\Platform\Tenancy\Models\Organization;
use Modules\Pharmacy\Contracts\MedicineCatalogue;

/** Medicines for other modules' pickers (bound to MedicineCatalogue). */
class Catalogue implements MedicineCatalogue
{
    public function __construct(private Medicines $medicines) {}

    public function search(Organization $company, string $term, int $limit = 15): array
    {
        return $this->medicines->search($company, $term)->filter(fn (array $row) => $row['medicine']->is_active)
            ->sortBy(fn (array $row) => mb_strtolower((string) ($row['item']['name'] ?? '')))->take(max(1, min($limit, 50)))
            ->map(fn (array $row) => $this->row($row))->values()->all();
    }

    public function labels(Organization $company, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return $this->medicines->details($company, array_values(array_unique($ids)), activeOnly: true)
            ->map(fn (array $row) => $this->label($this->row($row)))->all();
    }

    /** @return array{id: string, name: string, generic: string, strength: ?string, form: string} */
    private function row(array $row): array
    {
        return [
            'id' => (string) $row['medicine']->getKey(),
            'name' => (string) ($row['item']['name'] ?? ''),
            'generic' => $row['generic']?->textIn('name') ?? '',
            'strength' => $row['medicine']->strength,
            'form' => (string) $row['medicine']->form,
        ];
    }

    private function label(array $row): string
    {
        return trim($row['name'].' '.($row['strength'] ?? ''));
    }
}
