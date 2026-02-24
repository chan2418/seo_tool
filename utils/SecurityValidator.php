<?php

class SecurityValidator
{
    public static function sanitizeDomain(string $domain): string
    {
        $candidate = strtolower(trim($domain));
        $candidate = preg_replace('/^https?:\/\//i', '', $candidate);
        $candidate = preg_replace('/\/.*$/', '', $candidate);
        $candidate = preg_replace('/:\\d+$/', '', $candidate);
        $candidate = trim((string) $candidate);

        return $candidate;
    }

    public static function isValidDomain(string $domain): bool
    {
        if ($domain === '' || strlen($domain) > 253) {
            return false;
        }

        if (!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain)) {
            return false;
        }

        return true;
    }

    public static function normalizeUrl(string $input): ?string
    {
        $candidate = trim($input);
        if ($candidate === '') {
            return null;
        }

        if (!preg_match('~^https?://~i', $candidate)) {
            $candidate = 'https://' . $candidate;
        }

        if (!filter_var($candidate, FILTER_VALIDATE_URL)) {
            return null;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        $normalized = $scheme . '://' . strtolower((string) $parts['host']);
        if (!empty($parts['port'])) {
            $normalized .= ':' . (int) $parts['port'];
        }
        $normalized .= $path;

        return $normalized;
    }

    public static function isSafeHost(string $host): bool
    {
        $host = strtolower(trim($host));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.local')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isSafeIp($host);
        }

        $resolvedIps = [];
        $dnsA = @dns_get_record($host, DNS_A);
        if (is_array($dnsA)) {
            foreach ($dnsA as $record) {
                if (!empty($record['ip'])) {
                    $resolvedIps[] = $record['ip'];
                }
            }
        }

        $dnsAaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($dnsAaaa)) {
            foreach ($dnsAaaa as $record) {
                if (!empty($record['ipv6'])) {
                    $resolvedIps[] = $record['ipv6'];
                }
            }
        }

        if (empty($resolvedIps)) {
            $fallbackIp = gethostbyname($host);
            if ($fallbackIp !== $host) {
                $resolvedIps[] = $fallbackIp;
            }
        }

        foreach ($resolvedIps as $ip) {
            if (!self::isSafeIp($ip)) {
                return false;
            }
        }

        return !empty($resolvedIps);
    }

    public static function isSafeUrlForCrawl(string $url): bool
    {
        $normalizedUrl = self::normalizeUrl($url);
        if ($normalizedUrl === null) {
            return false;
        }

        $parts = parse_url($normalizedUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return false;
        }

        return self::isSafeHost((string) $parts['host']);
    }

    private static function isSafeIp(string $ip): bool
    {
        return (bool) filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }
}
