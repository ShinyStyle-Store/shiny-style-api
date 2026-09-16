<?php

namespace Tests\Unit\Http\Middleware;

use App\Http\Middleware\SetApiLocale;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class SetApiLocaleTest extends TestCase
{
    public function test_a_truly_headerless_request_defaults_to_arabic(): void
    {
        app()->setLocale('en');
        $request = Request::create('/api/v1/products', 'GET');
        $request->headers->remove('Accept-Language');
        $request->server->remove('HTTP_ACCEPT_LANGUAGE');

        $this->assertFalse($request->headers->has('Accept-Language'));

        $response = (new SetApiLocale)->handle(
            $request,
            fn (Request $request): Response => new Response('ok'),
        );

        $this->assertSame('ar', app()->getLocale());
        $this->assertSame('ar', $response->headers->get('Content-Language'));
        $this->assertStringContainsString('Accept-Language', (string) $response->headers->get('Vary'));
    }
}
