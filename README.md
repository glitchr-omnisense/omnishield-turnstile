# omniguard/turnstile

**Cloudflare Turnstile** for [glitchr/omniguard](https://github.com/glitchr-studio/omniguard): the
widget from Cloudflare, its token checked by Cloudflare's `siteverify` - the visitor's address
sent with it, the action and the host checked back, a request that got no answer sent once more
under the same idempotency key.

```php
use Omniguard\Model\Attempt;
use Omniguard\Turnstile\TurnstileGatewayFactory;

$gateway = (new TurnstileGatewayFactory($httpClient))->create(['site_key' => $siteKey, 'secret' => $secret]);

$widget = $gateway->widget('contact');
echo $widget->html();         // <script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer> <div class="cf-turnstile" ...>

$verdict = $gateway->verify(Attempt::fromPost($_POST, $widget, $ip, 'www.example.org'));
$verdict->passed;             // false: reasons missing, invalid, spent, action, hostname
```

```yaml
omniguard:
    gateways:
        cloudflare:
            factory: turnstile
            options: { site_key: '%env(TURNSTILE_SITE_KEY)%', secret: '%env(TURNSTILE_SECRET)%', theme: auto }
```

The visitor's browser talks to Cloudflare (`Widget::$origins`: `https://challenges.cloudflare.com`):
name it in the privacy policy, and see what the CNIL says of captchas in omniguard's
[privacy](https://github.com/glitchr-studio/omniguard/blob/1.x/docs/privacy.md). No cookie, by
Cloudflare's Turnstile privacy addendum.

[Documentation](docs/index.md): the options, the answers, the testing keys, what was verified -
the three testing secrets against Cloudflare, on 2026-10-07.

License: LGPL-3.0-or-later.
