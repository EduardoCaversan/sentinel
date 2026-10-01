<?php

declare(strict_types=1);

namespace App\Services;

class DnsResolver
{
    /** @return list<string> */
    public function addresses(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);

        return array_values(array_filter(array_map(
            fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records ?: [],
        )));
    }
}
