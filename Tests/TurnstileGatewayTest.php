<?php

namespace Omniguard\Turnstile\Tests;

use Omniguard\ChallengeInterface;
use Omniguard\Exception\InvalidConfigException;
use Omniguard\Exception\InvalidKeyException;
use Omniguard\Exception\UnreachableException;
use Omniguard\Model\Attempt;
use Omniguard\Model\Verdict;
use Omniguard\Turnstile\TurnstileGatewayFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers recorded from siteverify on 2026-10-07 with Cloudflare's testing
 * secrets (siteverify-testing-*.json: 1x passes, 2x fails, 3x spent;
 * siteverify-missing-response.json, siteverify-invalid-secret.json: an empty
 * response, a made-up secret). siteverify-production-passes.json is written
 * from the documentation's fields for a real key, with an action: no real key was
 * used.
 */
final class TurnstileGatewayTest extends TestCase
{
    private const TOKEN = 'XXXX.DUMMY.TOKEN.XXXX';

    /** @var list<array{string, string, array<string, string>}> */
    private array $calls = [];

    /** @param list<MockResponse|string> $answers fixtures' names, or responses */
    private function gateway(array $answers, array $options = []): ChallengeInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$answers): MockResponse {
            parse_str((string) $options['body'], $body);
            $this->calls[] = [$method, $url, $body];
            $answer = array_shift($answers) ?? throw new \LogicException('No answer left.');

            return $answer instanceof MockResponse ? $answer : new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$answer), ['response_headers' => ['content-type' => 'application/json']]);
        });

        return (new TurnstileGatewayFactory($http))->create($options + ['site_key' => '1x00000000000000000000AA', 'secret' => '1x0000000000000000000000000000000AA']);
    }

    public function testATokenPassesAndTheVisitorsAddressIsSentWithAnIdempotencyKey(): void
    {
        $verdict = $this->gateway(['siteverify-testing-passes.json'])->verify(new Attempt(self::TOKEN, '192.0.2.10', 'contact', 'www.example.org'));

        self::assertTrue($verdict->passed, implode(', ', $verdict->reasons));
        self::assertSame('example.com', $verdict->hostname, 'what the testing key answers, whatever the host');
        self::assertSame('2026-10-07T00:26:39+00:00', $verdict->at?->format(\DATE_ATOM));
        self::assertSame(['result_with_testing_key'], $verdict->codes);
        [$method, $url, $body] = $this->calls[0];
        self::assertSame(['POST', 'https://challenges.cloudflare.com/turnstile/v0/siteverify'], [$method, $url]);
        self::assertSame(['secret', 'response', 'remoteip', 'idempotency_key'], array_keys($body));
        self::assertSame([self::TOKEN, '192.0.2.10'], [$body['response'], $body['remoteip']]);
        self::assertMatchesRegularExpression('~^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~', $body['idempotency_key']);
    }

    public function testATokenThatFailsOrWasSpentIsRefused(): void
    {
        $failed = $this->gateway(['siteverify-testing-fails.json'])->verify(new Attempt(self::TOKEN));
        self::assertSame([[Verdict::INVALID], ['invalid-input-response']], [$failed->reasons, $failed->codes]);

        $spent = $this->gateway(['siteverify-testing-spent.json'])->verify(new Attempt(self::TOKEN));
        self::assertSame([[Verdict::SPENT], ['timeout-or-duplicate']], [$spent->reasons, $spent->codes]);
        self::assertFalse($spent->passed);
    }

    public function testNothingIsAskedOfCloudflareForAnEmptyOrOverlongToken(): void
    {
        $gateway = $this->gateway([]);

        self::assertSame([Verdict::MISSING], $gateway->verify(new Attempt(''))->reasons);
        self::assertSame(['too-long'], $gateway->verify(new Attempt(str_repeat('x', 2049)))->codes);
        self::assertSame([], $this->calls);
        self::assertSame([Verdict::MISSING], $this->gateway(['siteverify-missing-response.json'])->verify(new Attempt(' '))->reasons, 'Cloudflare\'s own word for it, when asked');
    }

    public function testTheActionAndTheHostOfARealKeyAreCheckedBack(): void
    {
        $options = ['site_key' => '0x4AAAAAAAexample', 'secret' => '0x4AAAAAAAexample-secret'];

        self::assertTrue($this->gateway(['siteverify-production-passes.json'], $options)->verify(new Attempt(self::TOKEN, action: 'contact', hostname: 'WWW.example.org'))->passed);
        self::assertSame([Verdict::ACTION], $this->gateway(['siteverify-production-passes.json'], $options)->verify(new Attempt(self::TOKEN, action: 'signup'))->reasons);
        self::assertSame([Verdict::ACTION, Verdict::HOSTNAME], $this->gateway(['siteverify-production-passes.json'], $options)->verify(new Attempt(self::TOKEN, action: 'signup', hostname: 'evil.example'))->reasons);
        self::assertTrue($this->gateway(['siteverify-production-passes.json'], $options)->verify(new Attempt(self::TOKEN))->passed, 'nothing expected: nothing checked');
    }

    public function testARefusedSecretIsTheSitesErrorNotTheVisitors(): void
    {
        $this->expectException(InvalidKeyException::class);
        $this->expectExceptionMessage('[turnstile] The secret was refused: invalid-input-secret.');
        $this->gateway(['siteverify-invalid-secret.json'])->verify(new Attempt(self::TOKEN));
    }

    public function testARequestWithoutAnAnswerIsSentOnceMoreUnderTheSameKey(): void
    {
        $verdict = $this->gateway([new MockResponse('', ['error' => 'Connection reset']), 'siteverify-testing-passes.json'])->verify(new Attempt(self::TOKEN));

        self::assertTrue($verdict->passed);
        self::assertCount(2, $this->calls);
        self::assertSame($this->calls[0][2]['idempotency_key'], $this->calls[1][2]['idempotency_key']);
    }

    public function testCloudflareSilentTwiceOrInErrorIsUnreachable(): void
    {
        foreach ([
            [new MockResponse('', ['error' => 'timeout']), new MockResponse('', ['http_code' => 502])],
            [new MockResponse('{"success":false,"error-codes":["internal-error"]}')],
        ] as $answers) {
            try {
                $this->gateway($answers)->verify(new Attempt(self::TOKEN));
                self::fail();
            } catch (UnreachableException $e) {
                self::assertSame('turnstile', $e->provider);
            }
        }
        $this->calls = [];
        $this->expectException(UnreachableException::class);
        try {
            $this->gateway([new MockResponse('', ['error' => 'timeout'])], ['idempotency' => false])->verify(new Attempt(self::TOKEN));
        } finally {
            self::assertCount(1, $this->calls, 'no key, no second try');
            self::assertArrayNotHasKey('idempotency_key', $this->calls[0][2]);
        }
    }

    public function testTheWidgetIsCloudflaresAndSaysSo(): void
    {
        $widget = $this->gateway([], ['theme' => 'dark', 'language' => 'fr', 'attributes' => ['data-cdata' => 'c1']])->widget('contact');

        self::assertSame('<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-sitekey="1x00000000000000000000AA" data-action="contact" data-theme="dark" data-language="fr" data-cdata="c1"></div>', $widget->html());
        self::assertSame(['cf-turnstile-response', true, false, ['https://challenges.cloudflare.com']], [$widget->field, $widget->thirdParty, $widget->cookies, $widget->origins]);
        self::assertSame('captcha', $this->gateway([], ['field' => 'captcha'])->widget()->attributes['data-response-field-name']);
    }

    public function testItNeedsBothKeys(): void
    {
        $this->expectException(InvalidConfigException::class);
        $this->expectExceptionMessage('The "turnstile" gateway needs: secret.');
        (new TurnstileGatewayFactory(new MockHttpClient()))->create(['site_key' => 'k']);
    }
}
