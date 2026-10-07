<?php

namespace Omniguard\Turnstile;

use Omniguard\Config;
use Omniguard\GatewayFactory;
use Omniguard\GatewayInterface;
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
            'omniguard.factory_name' => 'turnstile',
            'omniguard.factory_title' => 'Cloudflare Turnstile',
            'omniguard.required_options' => ['site_key', 'secret'],
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
            (string) $c['site_key'],
            (string) $c['secret'],
            $attributes + (array) $c['attributes'],
            $c->string('field') ?? TurnstileGateway::FIELD,
            $c->bool('idempotency'),
            $c->string('base_uri') ?? TurnstileGateway::BASE_URI,
        );
    }
}
