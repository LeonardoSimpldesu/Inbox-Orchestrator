<?php

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Guards the development and production environment templates against
 * regressions that would expose the app (database, DevTools, debug pages).
 */
class EnvironmentHardeningTest extends TestCase
{
    public function test_docker_published_ports_are_bound_to_loopback_only(): void
    {
        preg_match_all('/^\s*-\s*["\']?([^"\'\s]+:\d+)["\']?\s*$/m', $this->portsSections(), $matches);

        $this->assertNotEmpty($matches[1], 'No published ports found in docker-compose.yml.');

        foreach ($matches[1] as $mapping) {
            $this->assertStringStartsWith(
                '127.0.0.1:',
                $mapping,
                "Port mapping [{$mapping}] is reachable from the network. Bind it to 127.0.0.1 (Vite and Inertia DevTools serve unauthenticated data)."
            );
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function productionSettings(): array
    {
        return [
            'environment' => ['APP_ENV', 'production'],
            'debug disabled' => ['APP_DEBUG', 'false'],
            'secure session cookie' => ['SESSION_SECURE_COOKIE', 'true'],
            'encrypted session' => ['SESSION_ENCRYPT', 'true'],
            'Inertia DevTools disabled' => ['INERTIA_DEVTOOLS_ENABLED', 'false'],
            'no debug logging' => ['LOG_LEVEL', 'warning'],
        ];
    }

    #[DataProvider('productionSettings')]
    public function test_production_env_template_is_hardened(string $key, string $expected): void
    {
        $this->assertSame($expected, $this->envValue('.env.production.example', $key), "{$key} in .env.production.example");
    }

    public function test_session_payloads_are_encrypted_by_default(): void
    {
        $this->assertSame('true', $this->envValue('.env.example', 'SESSION_ENCRYPT'));
    }

    private function portsSections(): string
    {
        $compose = file_get_contents(base_path('docker-compose.yml'));

        // A ports block is a run of list items, possibly interleaved with comments.
        preg_match_all('/^\s*ports:\s*\n((?:[ \t]*(?:-|#).*\n?)+)/m', $compose, $sections);

        return implode("\n", $sections[1]);
    }

    private function envValue(string $file, string $key): ?string
    {
        $path = base_path($file);
        $this->assertFileExists($path);

        preg_match('/^'.preg_quote($key, '/').'=(.*)$/m', file_get_contents($path), $match);

        return isset($match[1]) ? trim($match[1], " \t\"'") : null;
    }
}
