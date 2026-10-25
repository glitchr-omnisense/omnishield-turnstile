---
title: omnishield/turnstile
order: 1
---

# omnishield/turnstile

## Installation

```sh
composer require omnishield/turnstile
```

PHP 8.2 or later, `omniscient/omnishield` and `symfony/http-client` (a library; give the factory your
application's client, a `MockHttpClient` in a test).

## The sources

Written from Cloudflare's documentation as it read on 2026-10-07:
[server-side validation](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/)
(`siteverify`, its parameters, its answer, its error codes, a token's length and life),
[client-side rendering](https://developers.cloudflare.com/turnstile/get-started/client-side-rendering/)
(the script, `.cf-turnstile`, its `data-*` attributes, the `cf-turnstile-response` field),
[testing](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) (the dummy keys),
and the [Turnstile privacy addendum](https://www.cloudflare.com/turnstile-privacy-policy/).

## Options

| Option | Default | |
|---|---|---|
| `site_key`, `secret` | required | the widget's keys, from Cloudflare's dashboard |
| `theme` | | `auto`, `light`, `dark` (`data-theme`) |
| `size` | | `normal`, `flexible`, `compact` (`data-size`) |
| `appearance` | | `always`, `execute`, `interaction-only` (`data-appearance`) |
| `language` | | `auto`, or a language: `fr`, `de-ch` (`data-language`) |
| `attributes` | `{}` | any other attribute of the widget: `data-cdata`, `data-callback` |
| `field` | `cf-turnstile-response` | another name is given to the widget as `data-response-field-name` |
| `idempotency` | `true` | each check carries an `idempotency_key` (a UUID); a check that got no answer is sent once more under it |
| `base_uri` | `https://challenges.cloudflare.com/turnstile/v0/` | |

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

## Verified, and not

| | |
|---|---|
| The family's conformance suite (`Omnishield\Testing\GatewayContractTests`) | **green on 2026-10-25**, offline: `Tests/TurnstileContractTest.php` on the recorded answers; HTTP 401/403 now an `InvalidKeyException` |
| The three testing secrets against `siteverify` | **done on 2026-10-07** (`docker compose run --rm omnishield bare --live`, the tests' recorded answers): 1x passes (host `example.com`), 2x `invalid-input-response`, 3x `timeout-or-duplicate`; an empty response `missing-input-response`; a made-up secret `invalid-input-secret`; the JSON body with an `idempotency_key` passes as the form body does |
| A real site key, a real token from a browser | **not verified**: no Cloudflare account. The answer with an action (`siteverify-production-passes.json`) is written from the documentation's fields |
| The retry under the same idempotency key | by the tests; Cloudflare's own handling of a repeated key not observed |
| The widget in a browser | not done: its markup follows the documentation |
