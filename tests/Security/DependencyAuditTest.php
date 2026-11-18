<?php

namespace Tests\Security;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Security gate: fails when `composer audit` or `npm audit` report known
 * vulnerabilities that are not explicitly (and temporarily) allowlisted
 * in audit-allowlist.json.
 *
 * Requires network access to the Packagist / npm advisory databases and
 * fails closed if they cannot be reached. Run offline suites with
 * `php artisan test --exclude-group=audit`.
 */
#[Group('audit')]
class DependencyAuditTest extends TestCase
{
    private const ALLOWLIST = 'audit-allowlist.json';

    /** @var array<string, array<string, mixed>>|null */
    private static ?array $npmFullTreeFindings = null;

    public function test_composer_dependencies_have_no_unapproved_vulnerabilities(): void
    {
        $findings = $this->composerFindings();
        $unapproved = array_diff_key($findings, $this->allowlist('composer'));

        $this->assertSame([], $unapproved, $this->report('composer audit', $unapproved));
    }

    public function test_composer_dependencies_are_not_abandoned(): void
    {
        $abandoned = $this->composerAudit()['abandoned'] ?? [];

        $this->assertSame([], $abandoned, 'Abandoned Composer packages: '.json_encode($abandoned, JSON_PRETTY_PRINT));
    }

    public function test_npm_production_dependencies_have_no_vulnerabilities(): void
    {
        // Production (shipped) dependencies are never allowed to be allowlisted.
        $findings = $this->npmFindings(['--omit=dev']);

        $this->assertSame([], $findings, $this->report('npm audit --omit=dev', $findings));
    }

    public function test_npm_dependencies_have_no_unapproved_vulnerabilities(): void
    {
        $findings = $this->npmFullTree();
        $unapproved = array_diff_key($findings, $this->allowlist('npm'));

        $this->assertSame([], $unapproved, $this->report('npm audit', $unapproved));
    }

    public function test_allowlist_entries_are_justified_and_not_expired(): void
    {
        $file = json_decode(file_get_contents(base_path(self::ALLOWLIST)), true);
        $this->assertTrue(is_array($file['composer'] ?? null) && array_is_list($file['composer']) && is_array($file['npm'] ?? null) && array_is_list($file['npm']), self::ALLOWLIST.' must declare "composer" and "npm" lists.');

        foreach (['composer', 'npm'] as $ecosystem) {
            foreach ($this->allowlist($ecosystem) as $id => $entry) {
                $this->assertNotEmpty($entry['reason'] ?? null, "[{$ecosystem}] {$id} must state a reason.");
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $entry['expires'] ?? '', "[{$ecosystem}] {$id} must have an expiry date (YYYY-MM-DD).");
                $this->assertTrue(
                    Carbon::parse($entry['expires'])->endOfDay()->isFuture(),
                    "[{$ecosystem}] allowlist entry {$id} expired on {$entry['expires']}. Re-check for a patched release and update or remove it."
                );
            }
        }
    }

    public function test_allowlist_has_no_stale_entries(): void
    {
        $stale = [
            'composer' => array_keys(array_diff_key($this->allowlist('composer'), $this->composerFindings())),
            'npm' => array_keys(array_diff_key($this->allowlist('npm'), $this->npmFullTree())),
        ];

        $this->assertSame(['composer' => [], 'npm' => []], $stale, 'These advisories are fixed; remove them from '.self::ALLOWLIST.'.');
    }

    public function test_composer_blocks_installation_of_insecure_packages(): void
    {
        $config = json_decode(file_get_contents(base_path('composer.json')), true)['config'] ?? [];

        $this->assertTrue($config['audit']['block-insecure'] ?? true, 'composer.json must not disable config.audit.block-insecure.');
        $this->assertNotFalse($config['policy']['advisories']['block'] ?? null, 'composer.json must not disable config.policy.advisories.block.');
    }

    /**
     * @return array<string, mixed>
     */
    private function composerAudit(): array
    {
        static $result = null;

        return $result ??= $this->runJson(
            [env('COMPOSER_BINARY', 'composer'), 'audit', '--locked', '--format=json', '--no-interaction'],
            'composer audit'
        );
    }

    /**
     * @return array<string, array<string, mixed>> keyed by advisory id
     */
    private function composerFindings(): array
    {
        $findings = [];

        foreach ($this->composerAudit()['advisories'] ?? [] as $package => $advisories) {
            foreach ($advisories as $advisory) {
                $id = $advisory['advisoryId'] ?? $advisory['cve'] ?? $package;
                $findings[$id] = [
                    'package' => $advisory['packageName'] ?? $package,
                    'title' => $advisory['title'] ?? null,
                    'cve' => $advisory['cve'] ?? null,
                    'link' => $advisory['link'] ?? null,
                ];
            }
        }

        return $findings;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function npmFullTree(): array
    {
        return self::$npmFullTreeFindings ??= $this->npmFindings();
    }

    /**
     * @param  list<string>  $extraArgs
     * @return array<string, array<string, mixed>> keyed by GHSA id
     */
    private function npmFindings(array $extraArgs = []): array
    {
        $report = $this->runJson(
            [env('NPM_BINARY', 'npm'), 'audit', '--json', '--package-lock-only', ...$extraArgs],
            'npm audit'
        );

        $this->assertArrayNotHasKey('error', $report, 'npm audit failed: '.json_encode($report['error'] ?? null));

        $findings = [];

        foreach ($report['vulnerabilities'] ?? [] as $vulnerability) {
            foreach ($vulnerability['via'] ?? [] as $via) {
                // String entries only point at another vulnerable package; the advisory itself is listed there.
                if (! is_array($via)) {
                    continue;
                }

                $id = basename((string) ($via['url'] ?? $via['source'] ?? $via['name']));
                $findings[$id] = [
                    'package' => $via['name'] ?? null,
                    'title' => $via['title'] ?? null,
                    'severity' => $via['severity'] ?? null,
                    'range' => $via['range'] ?? null,
                    'link' => $via['url'] ?? null,
                ];
            }
        }

        return $findings;
    }

    /**
     * @param  list<string>  $command
     * @return array<string, mixed>
     */
    private function runJson(array $command, string $label): array
    {
        // Audit tools exit non-zero when they find issues, so rely on the JSON payload instead.
        $result = Process::path(base_path())->timeout(180)->run($command);
        $decoded = json_decode($result->output(), true);

        $this->assertIsArray(
            $decoded,
            "{$label} did not return JSON (exit {$result->exitCode()}). Is the tool installed and the advisory DB reachable?\n".$result->errorOutput()
        );

        return $decoded;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by advisory id
     */
    private function allowlist(string $ecosystem): array
    {
        $entries = json_decode(file_get_contents(base_path(self::ALLOWLIST)), true)[$ecosystem] ?? [];

        return collect($entries)->keyBy('id')->all();
    }

    /**
     * @param  array<string, array<string, mixed>>  $findings
     */
    private function report(string $label, array $findings): string
    {
        return "{$label} reported vulnerabilities. Upgrade the affected packages, or (dev-only, no fix available) add a justified, expiring entry to "
            .self::ALLOWLIST.":\n".json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }
}
