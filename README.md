# omnishield/turnstile

**Cloudflare Turnstile** for [glitchr/omnishield](https://github.com/glitchr-studio/omnishield): the
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
[privacy](https://github.com/glitchr-studio/omnishield/blob/1.x/docs/privacy.md). No cookie, by
Cloudflare's Turnstile privacy addendum.

[Documentation](docs/index.md): the options, the answers, the testing keys, what was verified -
the three testing secrets against Cloudflare, on 2026-10-07.

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

Formerly `omniguard/turnstile`, renamed on 2026-10-10 with its family (`glitchr/omnishield`).
