<?php

namespace App\Platform\Security;

/**
 * The remediation tracker (Phase 11): docs/security/findings.json. Each
 * finding from an authorized security test: id, severity, status, a short
 * neutral title (no exploit detail while it is open; the full report stays
 * with the testers), and once fixed the commit and the regression test.
 */
class Findings
{
    public const FILE = 'docs/security/findings.json';

    public const SEVERITIES = ['critical', 'high', 'medium', 'low', 'info'];

    public const STATUSES = ['open', 'fixed', 'accepted', 'false_positive'];

    /** Severities that block a release while open. */
    public const BLOCKING = ['critical', 'high'];

    public function __construct(private ?string $path = null) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $data = json_decode((string) @file_get_contents($this->path ?? base_path(self::FILE)), true);

        return is_array($data['findings'] ?? null) ? array_values($data['findings']) : [];
    }

    /**
     * Problems with the tracker itself (malformed entries, fixes without a
     * regression test that exists).
     *
     * @return list<string>
     */
    public function problems(): array
    {
        $data = json_decode((string) @file_get_contents($this->path ?? base_path(self::FILE)), true);
        if (! is_array($data) || ! is_array($data['findings'] ?? null)) {
            return [self::FILE.' is missing or is not {"findings": [...]}.'];
        }

        $problems = [];
        $seen = [];
        foreach ($this->all() as $index => $finding) {
            $id = (string) ($finding['id'] ?? "#{$index}");

            if (preg_match('/^PT-\d{4}-\d{3}$/', $id) !== 1) {
                $problems[] = "{$id}: the id must look like PT-2026-001.";
            }
            if (isset($seen[$id])) {
                $problems[] = "{$id}: used twice.";
            }
            $seen[$id] = true;

            if (! in_array($finding['severity'] ?? null, self::SEVERITIES, true)) {
                $problems[] = "{$id}: severity must be one of ".implode(', ', self::SEVERITIES).'.';
            }
            if (! in_array($finding['status'] ?? null, self::STATUSES, true)) {
                $problems[] = "{$id}: status must be one of ".implode(', ', self::STATUSES).'.';
            }
            if (trim((string) ($finding['title'] ?? '')) === '') {
                $problems[] = "{$id}: needs a short title.";
            }
            if (($finding['status'] ?? null) === 'fixed') {
                if (trim((string) ($finding['fixed_in'] ?? '')) === '') {
                    $problems[] = "{$id}: a fixed finding names the commit that fixed it (fixed_in).";
                }
                if (($error = $this->regressionTestProblem((string) ($finding['regression_test'] ?? ''))) !== null) {
                    $problems[] = "{$id}: {$error}";
                }
            }
            if (($finding['status'] ?? null) === 'accepted' && trim((string) ($finding['accepted_reason'] ?? '')) === '') {
                $problems[] = "{$id}: an accepted risk needs accepted_reason (who decided and why).";
            }
        }

        return $problems;
    }

    /**
     * @return list<array<string, mixed>> Open findings that block a release.
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $finding) => ($finding['status'] ?? null) === 'open' && in_array($finding['severity'] ?? null, self::BLOCKING, true),
        ));
    }

    /**
     * "tests/Feature/X/YTest.php::it does something": the file exists and
     * holds a test with that description.
     */
    private function regressionTestProblem(string $reference): ?string
    {
        if (! str_contains($reference, '::')) {
            return 'regression_test must be "tests/...Test.php::<test description>".';
        }

        [$file, $name] = explode('::', $reference, 2);
        $source = (string) @file_get_contents(base_path($file));

        if (! str_starts_with($file, 'tests/') || $source === '') {
            return "the regression test file {$file} does not exist.";
        }

        $description = preg_replace('/^it /', '', trim($name));
        if (! str_contains($source, "'".str_replace("'", "\\'", $description)."'") && ! str_contains($source, '"'.$description.'"')) {
            return "no test \"{$name}\" in {$file}.";
        }

        return null;
    }
}
