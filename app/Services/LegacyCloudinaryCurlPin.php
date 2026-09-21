<?php

namespace App\Services;

use InvalidArgumentException;

final class LegacyCloudinaryCurlPin
{
    /** @return array<int, list<string>> */
    public function options(string $host, string $address, int $port = 443): array
    {
        if (! defined('CURLOPT_RESOLVE')) {
            throw new InvalidArgumentException('Secure DNS pinning is unavailable.');
        }

        return [constant('CURLOPT_RESOLVE') => [$this->resolveEntry($host, $address, $port)]];
    }

    public function resolveEntry(string $host, string $address, int $port = 443): string
    {
        if (! $this->isValidHost($host)
            || $port < 1
            || $port > 65535
            || filter_var(
                $address,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) === false) {
            throw new InvalidArgumentException('Secure DNS pin configuration is invalid.');
        }

        $formattedAddress = str_contains($address, ':') ? '['.$address.']' : $address;

        return "{$host}:{$port}:{$formattedAddress}";
    }

    private function isValidHost(string $host): bool
    {
        if ($host === '' || strlen($host) > 253 || str_ends_with($host, '.')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if ($label === '' || strlen($label) > 63
                || preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }
}
