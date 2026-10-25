<?php

namespace Glitter\Connect\Services;

use Illuminate\Support\Str;
use RuntimeException;

class OutboundTargetGuard
{
    public function resolvePublicTarget(string $url, ?callable $dnsResolver = null): array
    {
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) throw new RuntimeException('Invalid outbound URL');
        $rawHost = (string) $parts['host'];
        if (str_ends_with($rawHost, '.')) throw new RuntimeException('Outbound hostname must not end with a dot');
        $host = strtolower($rawHost);
        $literalIp = filter_var($host, FILTER_VALIDATE_IP);
        if ($literalIp !== false) {
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) throw new RuntimeException('Outbound host is not public');
            if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new RuntimeException('Outbound host must have a public IPv4 address');
            $ips = [$host];
        } else {
            if ($host === 'localhost' || Str::endsWith($host, '.localhost') || Str::endsWith($host, '.local')) throw new RuntimeException('Outbound host is not public');
            if (preg_match('/\A[0-9.]+\z/', $host) === 1
                || preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\z/D', $host) !== 1
            ) {
                throw new RuntimeException('Outbound hostname is not in canonical ASCII form');
            }

            $records = $dnsResolver !== null
                ? $dnsResolver($host, DNS_A | DNS_AAAA)
                : dns_get_record($host, DNS_A | DNS_AAAA);
            $ips = [];
            foreach ($records ?: [] as $record) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) throw new RuntimeException('Outbound DNS resolved to a non-public address');
                $ips[] = $ip;
            }
        }
        if ($ips === []) throw new RuntimeException('Outbound host could not be resolved');

        $ipv4 = array_values(array_filter($ips, static fn (string $ip): bool => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false));
        if ($ipv4 === []) throw new RuntimeException('Outbound host has no public IPv4 address');

        return ['host' => $host, 'ip' => $ipv4[0], 'port' => (int) ($parts['port'] ?? 443)];
    }
}
