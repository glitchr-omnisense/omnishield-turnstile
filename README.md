# omnishield/turnstile

**Cloudflare Turnstile** for [omnisense/omnishield](https://github.com/glitchr-omnisense/omnishield): the
widget from Cloudflare, its token checked by Cloudflare's `siteverify` - the visitor's address
sent with it, the action and the host checked back, a request that got no answer sent once more
under the same idempotency key.

```php
use Omnishield\Model\Attempt;
use Omnishield\Turnstile\TurnstileGatewayFactory;

$gateway = (new TurnstileGatewayFactory($httpClient))->create(['site_key' => $siteKey, 'secret' => $secret]);

$widget = $gateway->widget('contact');
echo $widget->html();         // <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer> <div class="cf-turnstile" ...>

$verdict = $gateway->verify(Attempt::fromPost($_POST, $widget, $ip, 'www.example.org'));
$verdict->passed;             // false: reasons missing, invalid, spent, action, hostname
```

```yaml
omnishield:
    gateways:
        cloudflare:
            factory: turnstile
            options: { site_key: '%env(TURNSTILE_SITE_KEY)%', secret: '%env(TURNSTILE_SECRET)%', theme: auto }
```

The visitor's browser talks to Cloudflare (`Widget::$origins`: `https://challenges.cloudflare.com`):
name it in the privacy policy, and see what the CNIL says of captchas in omnishield's
[privacy](https://github.com/glitchr-omnisense/omnishield/blob/1.x/docs/privacy.md). No cookie, by
Cloudflare's Turnstile privacy addendum.

[Documentation](docs/index.md): what it calls, the credentials, the capabilities, an example run offline, the errors, the options, the answers, the testing keys, what was verified -
the three testing secrets against Cloudflare, on 2026-10-07.

## Usage policy

[`policy.json`](policy.json) (omnisense/contracts' schema, read at the provider's terms on 2026-11-10; its `provenance.notes` says what was read where): No rate limit on the Free plan ("Unlimited challenges"); commercial use allowed; Cloudflare's branding stays on the widget. A 429 and its `Retry-After` hold the next calls (`UnreachableException::retryAfter()`). See the core's [usage policies](https://github.com/glitchr-omnisense/omnishield/blob/1.x/docs/policies.md).

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
charge, make no commercial use of it, and accept no liability for how it is used (see
[LICENSE](LICENSE)).

License: MIT since 2026-10-09; earlier versions remain published under LGPL-3.0-or-later.

Formerly `omniguard/turnstile`, renamed on 2026-10-10 with its family (`glitchr/omniguard`, now `omnisense/omnishield`).
