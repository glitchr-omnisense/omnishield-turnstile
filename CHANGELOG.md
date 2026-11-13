# Changelog

## [Unreleased]

## [1.0.0] - 2026-11-13

The first stable release. Omnishield Turnstile: Cloudflare's captcha - the widget, and its token checked by Cloudflare's siteverify (the visitor's address, the action and the host checked back, a request retried under its idempotency key).

### Changed

- Requires `omnisense/contracts: ^1.0`, `omnisense/omnishield: ^1.0`: stable versions, so `composer require omnishield/turnstile` installs in a project at the default minimum-stability. `extra.branch-alias` names 1.x (`1.x-dev`); the CI (`.github/workflows/ci.yml`, glitchr-studio/ci's shared workflow) and the release on a tag (`release.yml`).
- `phpstan-baseline.neon`, empty: the shared workflow requires the file; nothing is accepted.
