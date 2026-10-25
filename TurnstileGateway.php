<?php

namespace Omnishield\Turnstile;

use Omnishield\ChallengeInterface;
use Omnishield\Exception\InvalidKeyException;
use Omnishield\Exception\ProviderException;
use Omnishield\Exception\UnreachableException;
use Omnishield\Http\Answer;
use Omnishield\Model\Attempt;
use Omnishield\Model\Capabilities;
use Omnishield\Model\Verdict;
use Omnishield\Model\Widget;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A Turnstile token, checked by siteverify
 * (https://developers.cloudflare.com/turnstile/get-started/server-side-validation/):
 * POST secret, response, remoteip, idempotency_key; the answer's success,
 * challenge_ts, hostname, action and error-codes. A token lasts 300 seconds,
 * serves once, and is at most 2048 characters long.
 */
final class TurnstileGateway implements ChallengeInterface
{
    public const BASE_URI = 'https://challenges.cloudflare.com/turnstile/v0/';
    public const SCRIPT = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    public const FIELD = 'cf-turnstile-response';
    public const MAX_LENGTH = 2048;

    /** Cloudflare's error codes, in the family's words; the secret's are thrown instead. */
    private const REASONS = [
        'missing-input-response' => Verdict::MISSING,
        'invalid-input-response' => Verdict::INVALID,
        'timeout-or-duplicate' => Verdict::SPENT,
    ];

    /** @param array<string, string|bool> $attributes */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly string $siteKey,
        #[\SensitiveParameter] private readonly string $secret,
        private readonly array $attributes = [],
        private readonly string $field = self::FIELD,
        private readonly bool $idempotency = true,
        private readonly string $baseUri = self::BASE_URI,
    ) {
    }

    public function getName(): string
    {
        return 'turnstile';
    }

    public function getTitle(): string
    {
        return 'Cloudflare Turnstile';
    }

    public function capabilities(): Capabilities
    {
        return new Capabilities(challenge: true, thirdParty: true, cookies: false, scores: false, actions: true);
    }

    public function widget(?string $action = null): Widget
    {
        return new Widget(
            field: $this->field,
            script: self::SCRIPT,
            scriptAttributes: ['async' => true, 'defer' => true],
            tag: 'div',
            attributes: ['class' => 'cf-turnstile', 'data-sitekey' => $this->siteKey, 'data-action' => $action ?? false, 'data-response-field-name' => self::FIELD !== $this->field ? $this->field : false] + $this->attributes,
            siteKey: $this->siteKey,
            action: $action,
            thirdParty: true,
            cookies: false,
            origins: ['https://challenges.cloudflare.com'],
        );
    }

    public function verify(Attempt $attempt): Verdict
    {
        if ($attempt->isEmpty()) {
            return Verdict::fail(Verdict::MISSING);
        }
        if (\strlen($attempt->token) > self::MAX_LENGTH) {
            return Verdict::fail(Verdict::INVALID, ['too-long']);
        }

        $body = array_filter(['secret' => $this->secret, 'response' => $attempt->token, 'remoteip' => $attempt->ip, 'idempotency_key' => $this->idempotency ? self::uuid() : null]);
        try {
            $answer = $this->siteverify($body);
        } catch (UnreachableException $e) {
            if (!$this->idempotency) {
                throw $e;
            }
            // The same key: Cloudflare answers a retry as it answered the first, if it did.
            $answer = $this->siteverify($body);
        }
        if (\in_array($answer->status, [401, 403], true)) {
            throw new InvalidKeyException('turnstile', \sprintf('HTTP %d: the secret was refused.', $answer->status), (string) $answer->status);
        }
        if (400 <= $answer->status && !str_starts_with(trim($answer->body), '{')) {
            throw new ProviderException('turnstile', \sprintf('HTTP %d: %s', $answer->status, mb_substr(trim($answer->body), 0, 200)));
        }
        $data = $answer->json();
        $codes = array_values(array_map('strval', (array) ($data['error-codes'] ?? [])));

        if (array_intersect($codes, ['missing-input-secret', 'invalid-input-secret'])) {
            throw new InvalidKeyException('turnstile', 'The secret was refused: '.implode(', ', $codes).'.', $codes[0]);
        }
        if (\in_array('internal-error', $codes, true)) {
            throw new UnreachableException('turnstile', 'Cloudflare could not check the token: internal-error.', 'internal-error');
        }
        if (\in_array('bad-request', $codes, true)) {
            throw new ProviderException('turnstile', 'The request was not understood: bad-request.', 'bad-request');
        }

        $action = isset($data['action']) && '' !== $data['action'] ? (string) $data['action'] : null;
        $hostname = isset($data['hostname']) && '' !== $data['hostname'] ? (string) $data['hostname'] : null;
        $at = self::date($data['challenge_ts'] ?? null);
        if (true !== ($data['success'] ?? null)) {
            $reasons = array_values(array_unique(array_map(static fn (string $code) => self::REASONS[$code] ?? Verdict::INVALID, $codes ?: ['invalid-input-response'])));

            return new Verdict(false, action: $action, hostname: $hostname, at: $at, reasons: $reasons, codes: $codes);
        }

        // Cloudflare's testing keys pass a dummy token for any host and no action.
        $testing = true === ($data['metadata']['result_with_testing_key'] ?? null);
        $reasons = [];
        if (!$testing && null !== $attempt->action && $attempt->action !== $action) {
            $reasons[] = Verdict::ACTION;
        }
        if (!$testing && null !== $attempt->hostname && strtolower($attempt->hostname) !== strtolower((string) $hostname)) {
            $reasons[] = Verdict::HOSTNAME;
        }

        return new Verdict([] === $reasons, action: $action, hostname: $hostname, at: $at, reasons: $reasons, codes: $testing ? ['result_with_testing_key'] : []);
    }

    /** @param array<string, string> $body */
    private function siteverify(array $body): Answer
    {
        return Answer::send($this->http, 'turnstile', 'POST', rtrim($this->baseUri, '/').'/siteverify', ['body' => $body]);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
