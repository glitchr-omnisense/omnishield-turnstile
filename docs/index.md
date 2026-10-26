# omnishield/turnstile

Cloudflare Turnstile as an Omnishield captcha: Cloudflare's widget
(`https://challenges.cloudflare.com/turnstile/v0/api.js`) in the page, its token checked by
`POST https://challenges.cloudflare.com/turnstile/v0/siteverify`. The visitor's browser talks to
Cloudflare; Turnstile sets no cookie. Cloudflare publishes testing keys that answer for any host,
which the gateway recognises.

## Installation

```sh
composer require omnishield/turnstile
```

PHP 8.2 or later, `omnisense/omnishield` and `symfony/http-client` (a library; give the factory your
application's client, a `MockHttpClient` in a test).

## Credentials

A **site key** (the widget's) and a **secret** (`siteverify`'s) of a Turnstile widget made in
Cloudflare's dashboard for the site's hosts. For development, Cloudflare's testing keys (below).

## The sources

Written from Cloudflare's documentation as it read on 2026-10-07:
[server-side validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
(`siteverify`, its parameters, its answer, its error codes, a token's length and life),
[client-side rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/)
(the script, `.cf-turnstile`, its `data-*` attributes, the `cf-turnstile-response` field),
[testing](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) (the dummy keys),
and the [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/).

## Options

| Option | Type | Default | Required | |
|---|---|---|---|---|
| `site_key` | string | - | yes | the widget's key |
| `secret` | string | - | yes | `siteverify`'s |
| `theme` | string | `null` | no | `auto`, `light`, `dark` (`data-theme`) |
| `size` | string | `null` | no | `normal`, `flexible`, `compact` (`data-size`) |
| `appearance` | string | `null` | no | `always`, `execute`, `interaction-only` (`data-appearance`) |
| `language` | string | `null` | no | `auto`, or a language: `fr`, `de-ch` (`data-language`) |
| `attributes` | map | `{}` | no | any other attribute of the widget: `data-cdata`, `data-callback` |
| `field` | string | `cf-turnstile-response` | no | another name is given to the widget as `data-response-field-name` |
| `idempotency` | bool | `true` | no | each check carries an `idempotency_key` (a UUID v4); a check that got no answer is sent once more under it |
| `base_uri` | string | `https://challenges.cloudflare.com/turnstile/v0/` | no | where `siteverify` is asked; the widget's script stays Cloudflare's |

`new TurnstileGatewayFactory(?HttpClientInterface $http = null)`: the application's HTTP client.
Constants: `TurnstileGateway::SCRIPT`, `FIELD`, `MAX_LENGTH` (2048), `BASE_URI`.

## Capabilities

| | |
|---|---|
| is this token valid (`ChallengeInterface`) | yes |
| the action checked | yes, when the attempt names one (not with the testing keys) |
| the host checked | yes, when the attempt names one (not with the testing keys) |
| a score | no |
| is this content spam, is this identity known | no |
| third party, cookies | Cloudflare (`https://challenges.cloudflare.com`), no cookie |

## The check

`POST https://challenges.cloudflare.com/turnstile/v0/siteverify`: `secret`, `response`,
`remoteip` (the attempt's address), `idempotency_key`. A token lasts 300 seconds, serves once and
is at most 2048 characters long; a longer one, or an empty one, is refused without asking.

| Cloudflare answers | Verdict / exception |
|---|---|
| `success: true` | passed, unless the action or the host differ from those expected (`action`, `hostname`) |
| `missing-input-response` | `missing` |
| `invalid-input-response` | `invalid` |
| `timeout-or-duplicate` | `spent`: lapsed or used already, Cloudflare does not say which |
| `missing-input-secret`, `invalid-input-secret` | `InvalidKeyException`: the site's configuration |
| `bad-request` | `ProviderException` |
| `internal-error`, no answer twice, HTTP 5xx | `UnreachableException` |

`Verdict::$at` is `challenge_ts`, `$hostname` and `$action` as Cloudflare read them.

## Testing keys

| Site key | Secret | |
|---|---|---|
| `1x00000000000000000000AA` (visible), `1x00000000000000000000BB` (invisible) | `1x0000000000000000000000000000000AA` | passes |
| `2x00000000000000000000AB` (visible), `2x00000000000000000000BB` (invisible) | `2x0000000000000000000000000000000AA` | fails |
| `3x00000000000000000000FF` (forces an interactive challenge) | `3x0000000000000000000000000000000AA` | "token already spent" |

The testing site keys produce the token `XXXX.DUMMY.TOKEN.XXXX`, which production secrets refuse.
Cloudflare answers them with `metadata.result_with_testing_key: true`, the host `example.com` and
no action: with them the action and the host are not checked, and the verdict's codes say
`result_with_testing_key`.

## Example

The widget, then a token checked four ways and two failures, Cloudflare's answers given by a
`MockHttpClient` (run in omnishield's workspace on 2026-10-26, offline):

```php
<?php

require 'vendor/autoload.php';

use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\UnreachableException;
use Omnishield\Model\Attempt;
use Omnishield\Turnstile\TurnstileGatewayFactory;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

$json = static fn (array $answer) => new MockResponse(json_encode($answer));
$http = new MockHttpClient([
    $json(['success' => true, 'challenge_ts' => '2026-10-25T08:00:00.000Z', 'hostname' => 'www.example.org', 'action' => 'contact', 'error-codes' => []]),
    $json(['success' => true, 'challenge_ts' => '2026-10-25T08:00:00.000Z', 'hostname' => 'www.example.org', 'action' => 'signup', 'error-codes' => []]),
    $json(['success' => false, 'error-codes' => ['timeout-or-duplicate']]),
    $json(['success' => false, 'error-codes' => ['invalid-input-secret']]),
    new MockResponse('', ['http_code' => 503]), new MockResponse('', ['http_code' => 503]),   // twice: the retry under the same idempotency key
]);
$turnstile = (new TurnstileGatewayFactory($http))->create(['site_key' => '0x4AAAAAAA-example', 'secret' => '0x4AAAAAAA-secret']);

$widget = $turnstile->widget('contact');
echo $widget->html(), "\n";
foreach (['contact', 'contact', 'contact'] as $action) {
    $verdict = $turnstile->verify(new Attempt('0.token-from-the-widget', '192.0.2.10', $action, 'www.example.org'));
    echo $verdict->passed ? 'passed' : 'refused: '.implode(', ', $verdict->reasons).' ('.implode(', ', $verdict->codes).')', "\n";
}
foreach ([InvalidKeyException::class, UnreachableException::class] as $expected) {
    try {
        $turnstile->verify(new Attempt('0.token-from-the-widget'));
    } catch (InvalidKeyException|UnreachableException $e) {
        echo $e::class, ': ', $e->getMessage(), "\n";
    }
}
echo $turnstile->verify(new Attempt(''))->reasons[0], ' (nothing sent); requests: ', $http->getRequestsCount(), "\n";
```

```
<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><div class="cf-turnstile" data-sitekey="0x4AAAAAAA-example" data-action="contact"></div>
passed
refused: action ()
refused: spent (timeout-or-duplicate)
Omnishield\Exception\InvalidKeyException: [turnstile] The secret was refused: invalid-input-secret.
Omnishield\Exception\UnreachableException: [turnstile] HTTP 503: 
missing (nothing sent); requests: 6
```

The two 503s are one check and its retry under the same idempotency key; the empty token is refused
without a request.

In Symfony:

```yaml
omnishield:
    gateways:
        cloudflare:
            factory: turnstile
            options: { site_key: '%env(TURNSTILE_SITE_KEY)%', secret: '%env(TURNSTILE_SECRET)%', appearance: interaction-only }
```

## Errors

| Exception | When |
|---|---|
| `InvalidKeyException` | HTTP 401 or 403; `missing-input-secret`, `invalid-input-secret` |
| `UnreachableException` | no answer, HTTP 5xx or 429 - twice with `idempotency` (the check and its retry) -; `internal-error` |
| `ProviderException` | `bad-request`; an HTTP 4xx whose body is not JSON; an answer that is not a JSON object |
| `InvalidConfigException` | `site_key` or `secret` missing at `create()` |

## Verified, and not

| | |
|---|---|
| The family's conformance suite (`Omnishield\Testing\GatewayContractTests`) | **green on 2026-10-25**, offline: `Tests/TurnstileContractTest.php` on the recorded answers; HTTP 401/403 now an `InvalidKeyException` |
| The three testing secrets against `siteverify` | **done on 2026-10-07** (`docker compose run --rm omnishield bare --live`, the tests' recorded answers): 1x passes (host `example.com`), 2x `invalid-input-response`, 3x `timeout-or-duplicate`; an empty response `missing-input-response`; a made-up secret `invalid-input-secret`; the JSON body with an `idempotency_key` passes as the form body does |
| A real site key, a real token from a browser | **not verified**: no Cloudflare account. The answer with an action (`siteverify-production-passes.json`) is written from the documentation's fields |
| The retry under the same idempotency key | by the tests; Cloudflare's own handling of a repeated key not observed |
| The widget in a browser | not done: its markup follows the documentation |

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Cloudflare, Inc. It reaches their service on behalf of whoever runs it, and it does
not redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with Turnstile's terms
([Cloudflare service-specific
terms](https://www.cloudflare.com/service-specific-terms-application-services/)), for obtaining
whatever they require (an account, a key, a licence or subscription, an attribution or a citation, a
rate limit to respect), and for any use made of what it returns. The authors publish it free of
charge, make no commercial use of it, and accept no liability for how it is used (see the package's LICENSE).
