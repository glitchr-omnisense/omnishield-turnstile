<?php

namespace Omnishield\Turnstile\Tests;

use Omnishield\GatewayFactoryInterface;
use Omnishield\Model\Attempt;
use Omnishield\Testing\GatewayContractTests;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/** The family's contract, on siteverify's answers recorded with Cloudflare's testing secrets (Fixtures/). */
final class TurnstileContractTest extends TestCase
{
    use GatewayContractTests;

    protected function factory(HttpClientInterface $http): GatewayFactoryInterface
    {
        return new TurnstileGatewayFactory($http);
    }

    protected function options(): array
    {
        return ['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA'];
    }

    protected function recordedCases(): iterable
    {
        $token = 'XXXX.DUMMY.TOKEN.XXXX';

        yield 'testing secret: passes' => [new Attempt($token, '192.0.2.10'), true, [self::recorded('siteverify-testing-passes.json')]];
        yield 'production: passes for its action' => [new Attempt($token, '192.0.2.10', 'contact', 'www.example.org'), true, [self::recorded('siteverify-production-passes.json')]];
        yield 'production: another action' => [new Attempt($token, '192.0.2.10', 'login'), false, [self::recorded('siteverify-production-passes.json')]];
        yield 'testing secret: fails' => [new Attempt($token), false, [self::recorded('siteverify-testing-fails.json')]];
        yield 'testing secret: spent' => [new Attempt($token), false, [self::recorded('siteverify-testing-spent.json')]];
    }

    private static function recorded(string $file): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['response_headers' => ['content-type' => 'application/json']]);
    }
}
