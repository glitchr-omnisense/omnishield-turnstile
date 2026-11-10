<?php

namespace Omnishield\Turnstile\Tests;

use Omnisense\Contracts\Policy\UsagePolicy;
use Omnishield\Exception\UnreachableException;
use Omnishield\Model\Attempt;
use Omnishield\Registry;
use Omnishield\Turnstile\TurnstileGateway;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/** Its usage policy: declared as data, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePackageDeclaresItsPolicy(): void
    {
        $policy = UsagePolicy::fromFile(TurnstileGateway::POLICY);
        $gateway = (new TurnstileGatewayFactory(new MockHttpClient()))->create(['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']);

        self::assertSame('turnstile', $policy->provider, 'named after the factory');
        self::assertSame('2026-11-10', $policy->provenance->verifiedAt);
        self::assertSame('allowed', $policy->rights->commercial->value);
        self::assertSame([], $policy->rateLimits, 'no limit');
        self::assertEquals($policy, $gateway->capabilities()->policy());
        self::assertMatchesRegularExpression('~"omnisense": \{\s*"policy": "policy\.json"~', (string) file_get_contents(__DIR__.'/../composer.json'), 'composer.json names it');
    }

    public function testA429AndItsRetryAfterHoldTheNextCallThroughTheRegistry(): void
    {
        $requests = 0;
        $http = new MockHttpClient(static function () use (&$requests) {
            ++$requests;

            return new MockResponse('Too Many Requests', ['http_code' => 429, 'response_headers' => ['retry-after' => '60']]);
        });
        $registry = new Registry([new TurnstileGatewayFactory($http)], ['site' => ['factory' => 'turnstile', 'options' => ['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']]]);
        foreach ([60, null] as $i => $wait) {
            try {
                $registry->challenge('site')->verify(new Attempt('XXXX.DUMMY.TOKEN.XXXX'));
                self::fail('refused');
            } catch (UnreachableException $e) {
                null === $wait ? self::assertGreaterThan(0, $e->retryAfter()) : self::assertSame($wait, $e->retryAfter());
            }
        }
        self::assertSame(1, $requests, 'the second call was held, nothing sent');
    }
}
