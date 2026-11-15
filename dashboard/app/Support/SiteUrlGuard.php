<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Keeps the dashboard from being used to reach internal services (SSRF).
 *
 * The dashboard sends requests carrying a site's API key to whatever URL an operator registers,
 * so targets must be http(s) and, unless explicitly allowed, resolve only to public addresses.
 * Checked when a site is saved and again before every request (DNS can change in between).
 */
class SiteUrlGuard
{
    /** @return string|null Reason the URL is not allowed, or null when it is. */
    public static function problem(string $url): ?string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'The website URL must be an absolute http:// or https:// address.';
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return 'The website URL must not contain credentials.';
        }
        if (config('services.wordpress.allow_private_hosts')) {
            return null;
        }

        $host = trim($host, '[]');
        $addresses = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : self::resolve($host);
        if ($addresses === []) {
            return 'The website host could not be resolved.';
        }
        foreach ($addresses as $address) {
            if (! filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return 'The website URL resolves to a private or reserved network address. Set WP_ALLOW_PRIVATE_HOSTS=true only if the dashboard must manage internal sites.';
            }
        }

        return null;
    }

    public static function assertAllowed(string $url): void
    {
        if ($problem = self::problem($url)) {
            throw new InvalidArgumentException($problem);
        }
    }

    /** @return list<string> */
    private static function resolve(string $host): array
    {
        $addresses = gethostbynamel($host) ?: [];
        foreach (@dns_get_record($host, DNS_AAAA) ?: [] as $record) {
            if (! empty($record['ipv6'])) {
                $addresses[] = $record['ipv6'];
            }
        }

        return array_values(array_unique($addresses));
    }
}
