<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class E2ESmokeHttpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--force' => true]);
    }

    /**
     * Basic HTTP client using PHP streams to hit the running Nginx container.
     */
    private function httpGet(string $url): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'ignore_errors' => true,
                'timeout' => 10,
                'header' => [
                    'User-Agent: E2E-Smoke-Test',
                    'Accept: text/html,*/*;q=0.9',
                ],
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $headers = $http_response_header ?? [];
        $statusLine = $headers[0] ?? 'HTTP/1.1 000 Unknown';
        preg_match('#\s(\d{3})\s#', $statusLine, $m);
        $status = isset($m[1]) ? (int)$m[1] : 0;

        return [$status, $headers, (string)$body];
    }

    public function test_homepage_is_accessible_via_nginx(): void
    {
        [$status, $headers, $body] = $this->httpGet('http://web');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('<!DOCTYPE html>', $body);
    }

    public function test_admin_login_page_is_accessible_via_nginx(): void
    {
        [$status, $headers, $body] = $this->httpGet('http://web/admin/login');
        $this->assertSame(200, $status);
        $this->assertStringContainsString('Sign in', $body);
    }

    public function test_admin_crud_redirects_guest_to_login(): void
    {
        [$status, $headers, $body] = $this->httpGet('http://web/admin/crud');
        $this->assertSame(302, $status);
        $locationHeader = collect($headers)->first(fn($h) => str_starts_with(strtolower($h), 'location:'));
        $this->assertNotEmpty($locationHeader);
        $this->assertStringContainsString('/admin/login', strtolower($locationHeader));
    }
}


