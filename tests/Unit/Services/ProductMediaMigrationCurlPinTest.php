<?php

namespace Tests\Unit\Services;

use App\Services\LegacyCloudinaryCurlPin;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductMediaMigrationCurlPinTest extends TestCase
{
    public function test_formats_ipv4_resolve_entry(): void
    {
        $this->assertSame(
            'res.cloudinary.com:443:93.184.216.34',
            (new LegacyCloudinaryCurlPin)->resolveEntry('res.cloudinary.com', '93.184.216.34'),
        );
    }

    public function test_formats_ipv6_resolve_entry_in_curl_brackets(): void
    {
        $this->assertSame(
            'res.cloudinary.com:443:[2606:4700:4700::1111]',
            (new LegacyCloudinaryCurlPin)->resolveEntry('res.cloudinary.com', '2606:4700:4700::1111'),
        );
    }

    public function test_rejects_malformed_hosts_addresses_and_ports(): void
    {
        $pin = new LegacyCloudinaryCurlPin;

        foreach ([
            ['bad:host', '93.184.216.34', 443],
            ['res.cloudinary.com', 'not-an-ip', 443],
            ['res.cloudinary.com', '127.0.0.1', 443],
            ['res.cloudinary.com', '93.184.216.34', 0],
        ] as [$host, $address, $port]) {
            try {
                $pin->resolveEntry($host, $address, $port);
                $this->fail('Malformed resolve configuration was accepted.');
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
    }

    public function test_pinned_request_options_construct_without_network_access(): void
    {
        if (! defined('CURLOPT_RESOLVE')) {
            define('CURLOPT_RESOLVE', 10065);
        }

        $options = (new LegacyCloudinaryCurlPin)->options('res.cloudinary.com', '93.184.216.34');
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, [], 'ok'),
        ]))]);

        $response = $client->get('https://res.cloudinary.com/demo/image.png', [
            'allow_redirects' => false,
            'verify' => true,
            'curl' => $options,
        ]);

        $this->assertSame(['res.cloudinary.com:443:93.184.216.34'], $options[constant('CURLOPT_RESOLVE')]);
        $this->assertSame(200, $response->getStatusCode());
    }
}
