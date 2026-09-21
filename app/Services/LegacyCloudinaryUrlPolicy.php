<?php

namespace App\Services;

use InvalidArgumentException;

final class LegacyCloudinaryUrlPolicy
{
    /** @param (callable(string): array<int, string>|false)|null $resolver */
    public function __construct(private readonly mixed $resolver = null) {}

    /** @return array{host: string, addresses: list<string>} */
    public function validate(string $url): array
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Malformed legacy media URL.');
        }

        $parts = parse_url($url);
        if (! is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])
            || (isset($parts['port']) && (int) $parts['port'] !== 443)) {
            throw new InvalidArgumentException('Legacy media URL must use an approved HTTPS endpoint.');
        }

        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $allowedHosts = array_map(
            static fn (string $approved): string => strtolower(rtrim($approved, '.')),
            (array) config('media.legacy_migration.cloudinary_hosts', []),
        );
        if ($host === '' || ! in_array($host, $allowedHosts, true)) {
            throw new InvalidArgumentException('Legacy media URL host is not approved.');
        }

        $addresses = $this->resolver === null
            ? self::resolve($host)
            : ($this->resolver)($host);
        if (! is_array($addresses) || $addresses === []) {
            throw new InvalidArgumentException('Legacy media URL host did not resolve safely.');
        }

        foreach ($addresses as $address) {
            if (! is_string($address)
                || filter_var(
                    $address,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
                ) === false) {
                throw new InvalidArgumentException('Legacy media URL resolves to a private or reserved address.');
            }
        }

        return ['host' => $host, 'addresses' => array_values($addresses)];
    }

    /** @return list<string>|false */
    private static function resolve(string $host): array|false
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records)) {
            return false;
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address)) {
                $addresses[] = $address;
            }
        }

        return $addresses;
    }
}
