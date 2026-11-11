<?php

namespace Omnishield\Turnstile;

use Omnishield\Config;
use Omnishield\GatewayFactory;
use Omnishield\GatewayInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Cloudflare Turnstile: the widget from Cloudflare, its token checked by
 * Cloudflare's siteverify.
 *
 *   options:
 *     site_key: '%env(TURNSTILE_SITE_KEY)%'   # required: the widget's
 *     secret: '%env(TURNSTILE_SECRET)%'       # required: siteverify's
 *     theme: auto                             # auto, light, dark
 *     size: normal                            # normal, flexible, compact
 *     appearance: always                      # always, execute, interaction-only
 *     language: ~                             # auto, or a language: fr, de-ch
 *     attributes: {}                          # any other data-* attribute of the widget
 *     field: cf-turnstile-response            # another name sets data-response-field-name
 *     idempotency: true                       # a request that got no answer is sent once more, under the same idempotency_key
 *     base_uri: https://challenges.cloudflare.com/turnstile/v0/
 *
 * Cloudflare's testing keys - site key 1x00000000000000000000AA, secret
 * 1x0000000000000000000000000000000AA (passes), 2x... (fails), 3x... (a
 * token already spent) - answer for any host and carry no action: their
 * answers are not checked against the host and action expected.
 */
final class TurnstileGatewayFactory extends GatewayFactory
{
    public function __construct(private readonly ?HttpClientInterface $http = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnishield.factory_name' => 'turnstile',
            'omnishield.factory_title' => 'Cloudflare Turnstile',
            'omnishield.required_options' => ['site_key', 'secret'],
            'theme' => null,
            'size' => null,
            'appearance' => null,
            'language' => null,
            'attributes' => [],
            'field' => TurnstileGateway::FIELD,
            'idempotency' => true,
            'base_uri' => TurnstileGateway::BASE_URI,
        ]);
    }

    protected function build(Config $c): GatewayInterface
    {
        $attributes = array_filter(['data-theme' => $c->string('theme'), 'data-size' => $c->string('size'), 'data-appearance' => $c->string('appearance'), 'data-language' => $c->string('language')]);

        return new TurnstileGateway(
            $this->http ?? HttpClient::create(),
            self::text($c['site_key']),
            self::text($c['secret']),
            $attributes + self::attributes($c['attributes']),
            $c->string('field') ?? TurnstileGateway::FIELD,
            $c->bool('idempotency'),
            $c->string('base_uri') ?? TurnstileGateway::BASE_URI,
            $c->backoff(),
        );
    }

    /** An option as the (string) cast read it: a scalar as text, anything else empty. */
    private static function text(mixed $value): string
    {
        return \is_scalar($value) || $value instanceof \Stringable ? (string) $value : '';
    }

    /**
     * The widget's attributes as the (array) cast gave them, printed the same: a text or a boolean as
     * it is, a number as its text; what the widget never prints (null, a nested value) left out.
     *
     * @return array<string, string|bool>
     */
    private static function attributes(mixed $value): array
    {
        $attributes = [];
        foreach ((array) $value as $name => $one) {
            if (\is_string($one) || \is_bool($one)) {
                $attributes[(string) $name] = $one;
            } elseif (\is_int($one) || \is_float($one)) {
                $attributes[(string) $name] = (string) $one;
            }
        }

        return $attributes;
    }
}
