<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\UnsafeTarget;
use Symfony\Component\HttpFoundation\IpUtils;

class PublicTarget
{
    public function __construct(private DnsResolver $dns) {}

    /** Resolve again for every execution. The caller MUST pin this address with cURL. */
    public function resolve(string $url): array
    {
        if (preg_match('/[\x00-\x20\x7f\\\\]/', $url) || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new UnsafeTarget('Use a valid public HTTP or HTTPS URL.');
        }
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower(trim($parts['host'] ?? '', '[]'));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, ['http', 'https'], true) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || $port !== ($scheme === 'https' ? 443 : 80)) {
            throw new UnsafeTarget('Only HTTP:80 and HTTPS:443 without credentials or fragments are allowed.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $addresses = [$host];
        } else {
            // A dotted ASCII DNS name with an alphabetic TLD excludes shorthand,
            // decimal, octal and hexadecimal IP representations understood by cURL.
            if (strlen($host) > 253 || ! preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/D', $host)
                || preg_match('/\.(localhost|localdomain|local|internal|test|invalid)$/D', $host)) {
                throw new UnsafeTarget('Use a public DNS name or globally routable IP address.');
            }
            $addresses = $this->dns->addresses($host);
        }
        if ($addresses === []) {
            throw new UnsafeTarget('The target has no usable DNS addresses.');
        }
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                throw new UnsafeTarget('Every target address must be globally routable.');
            }
        }

        return ['host' => $host, 'port' => $port, 'ip' => $addresses[0]];
    }

    private function isPublic(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return false;
        }
        if (str_contains($ip, ':')) {
            return IpUtils::checkIp($ip, '2000::/3')
                && ! IpUtils::checkIp($ip, ['2001::/23', '2001:db8::/32', '2002::/16', '3fff::/20']);
        }

        return ! IpUtils::checkIp($ip, ['0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15', '224.0.0.0/4', '240.0.0.0/4']);
    }
}
