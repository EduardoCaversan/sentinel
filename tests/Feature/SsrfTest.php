<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\UnsafeTarget;
use App\Services\DnsResolver;
use App\Services\PublicTarget;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SsrfTest extends TestCase
{
    #[DataProvider('unsafeUrls')]
    public function test_unsafe_targets_are_rejected(string $url): void
    {
        $this->expectException(UnsafeTarget::class);
        app(PublicTarget::class)->resolve($url);
    }

    public static function unsafeUrls(): array
    {
        return array_map(fn ($url) => [$url], [
            'http://localhost', 'http://localhost.localdomain', 'http://127.0.0.1', 'http://127.1.2.3',
            'http://10.0.0.1', 'http://172.16.0.1', 'http://192.168.1.1', 'http://169.254.169.254',
            'http://0.0.0.0', 'http://100.64.0.1', 'http://224.0.0.1', 'http://198.18.0.1',
            'http://[::1]', 'http://[::]', 'http://[fc00::1]', 'http://[fe80::1]',
            'http://[::ffff:127.0.0.1]', 'http://[64:ff9b::a00:1]', 'http://[2002:7f00:1::]',
            'http://[2001:db8::1]', 'http://[ff02::1]', 'http://2130706433', 'http://0177.0.0.1',
            'http://0x7f000001', 'http://127.1', 'http://redis', 'http://service.internal',
            'file:///etc/passwd', 'ftp://example.com', 'https://example.com:8443',
            'https://user:pass@example.com', 'https://example.com/#fragment', 'https://example.com\\@127.0.0.1',
        ]);
    }

    public function test_all_dns_answers_must_be_public(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->with('example.com')->andReturn(['93.184.215.14', '10.0.0.1']);
        $this->expectException(UnsafeTarget::class);
        app(PublicTarget::class)->resolve('https://example.com');
    }

    public function test_public_ipv4_ipv6_and_https_are_accepted(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->with('example.com')->andReturn(['93.184.215.14', '2606:4700:4700::1111']);
        $this->assertSame('93.184.215.14', app(PublicTarget::class)->resolve('https://example.com/health')['ip']);
        $this->assertSame('2606:4700:4700::1111', app(PublicTarget::class)->resolve('https://[2606:4700:4700::1111]')['ip']);
    }

    public function test_failed_dns_is_rejected(): void
    {
        $this->mock(DnsResolver::class)->shouldReceive('addresses')->andReturn([]);
        $this->expectException(UnsafeTarget::class);
        app(PublicTarget::class)->resolve('https://example.com');
    }
}
