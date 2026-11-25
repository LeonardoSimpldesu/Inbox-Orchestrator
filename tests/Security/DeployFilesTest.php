<?php

namespace Tests\Security;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The repository is public: deployment files and env templates must carry
 * placeholders only, never data that identifies or unlocks the real server.
 */
class DeployFilesTest extends TestCase
{
    private const PLACEHOLDER_MAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'your-domain.com'];

    /**
     * @return array<string, array{string}>
     */
    public static function publicFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root.'/deploy/*') ?: [],
            [$root.'/.env.example', $root.'/.env.production.example', $root.'/README.md'],
        );

        $cases = [];
        foreach ($files as $file) {
            $relative = substr($file, strlen($root) + 1);
            $cases[$relative] = [$relative];
        }

        return $cases;
    }

    public function test_deploy_directory_exists(): void
    {
        $this->assertNotEmpty(glob(base_path('deploy/*')), 'deploy/ has no files.');
    }

    #[DataProvider('publicFiles')]
    public function test_files_contain_no_ip_addresses(string $file): void
    {
        preg_match_all('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', $this->read($file), $matches);

        $this->assertSame([], array_values(array_diff($matches[0], ['127.0.0.1', '0.0.0.0'])), "{$file} contains an IP address.");
    }

    #[DataProvider('publicFiles')]
    public function test_files_contain_no_real_email_addresses(string $file): void
    {
        preg_match_all('/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+(?:\.[A-Za-z0-9-]+)+)/', $this->read($file), $matches);

        $real = array_filter($matches[1], fn (string $domain) => ! in_array(strtolower($domain), self::PLACEHOLDER_MAIL_DOMAINS, true)
            && ! str_ends_with(strtolower($domain), '.local'));

        $this->assertSame([], array_values($real), "{$file} contains a real e-mail address.");
    }

    #[DataProvider('publicFiles')]
    public function test_files_contain_no_secrets(string $file): void
    {
        $this->assertDoesNotMatchRegularExpression(
            '/re_[A-Za-z0-9]{8,}|GOCSPX-|base64:[A-Za-z0-9+\/=]{20,}|-----BEGIN |AGE-SECRET-KEY-1|age1[0-9a-z]{50,}|K00[A-Za-z0-9]{20,}|hc-ping\.com\/[0-9a-f-]{20,}/',
            $this->read($file),
            "{$file} contains something that looks like a secret.",
        );
    }

    public function test_backup_env_template_has_no_values(): void
    {
        preg_match_all('/^([A-Z0-9_]+)=(.*)$/m', $this->read('deploy/backup.env.example'), $matches, PREG_SET_ORDER);

        $this->assertNotEmpty($matches);
        foreach ($matches as [, $key, $value]) {
            $expected = $key === 'RCLONE_CONFIG_OFFSITE_TYPE' ? 'b2' : '';
            $this->assertSame($expected, $value, "{$key} must stay empty in the template.");
        }
    }

    public function test_nginx_template_is_hardened(): void
    {
        $nginx = $this->read('deploy/nginx.conf.template');

        foreach ([
            'server_tokens off;',
            'fastcgi_param HTTPS on;',
            'root __APP_DIR__/public;',
            'X-Frame-Options',
            'X-Content-Type-Options',
            'client_max_body_size',
        ] as $directive) {
            $this->assertStringContainsString($directive, $nginx);
        }

        $this->assertMatchesRegularExpression('/location ~ \/\\\\\.\(\?!well-known\)\.\* \{\s*deny all;/', $nginx, 'Dotfiles must be denied.');
    }

    private function read(string $file): string
    {
        $path = base_path($file);
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
