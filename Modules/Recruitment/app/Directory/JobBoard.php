<?php

namespace Modules\Recruitment\Directory;

use App\Platform\Modules\ModuleResolver;
use App\Platform\Notifications\Services\LinkBuilder;
use App\Platform\Tenancy\Models\Organization;
use Modules\Recruitment\Models\Opening;
use Modules\Recruitment\Services\Careers;
use Modules\Recruitment\Services\Openings;

/**
 * Recruitment's public service for other modules (a website's "jobs"
 * block): the jobs the company's careers page lists, each with its link
 * there. Nothing when Recruitment is off, the company has no careers
 * address yet, or public applying is off.
 */
class JobBoard
{
    public function __construct(private ModuleResolver $modules, private Careers $careers, private Openings $openings, private LinkBuilder $links) {}

    /**
     * @return array{url: string|null, jobs: list<array{id: string, title: array<string, string>, closes_on: string|null, url: string}>}
     */
    public function forCompany(Organization $company, int $limit = 10): array
    {
        $slug = $this->modules->isEnabled('hrm', $company) && $this->modules->isEnabled(Careers::MODULE, $company) ? $this->careers->slug($company) : null;
        if ($slug === null) {
            return ['url' => null, 'jobs' => []];
        }
        $base = $this->links->to("/careers/{$slug}", $company->partner, $company);

        return [
            'url' => $base,
            'jobs' => $this->openings->public($company)->take($limit)->map(fn (Opening $opening) => [
                'id' => $opening->getKey(), 'title' => $opening->texts('title'), 'closes_on' => $opening->closes_on?->toDateString(),
                'url' => "{$base}/jobs/{$opening->getKey()}",
            ])->values()->all(),
        ];
    }
}
