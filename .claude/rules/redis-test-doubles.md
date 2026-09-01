---
description: Signature rules for \Redis subclass test doubles in the PHPUnit suite
globs: tests/**/*.php
---
# `\Redis` test doubles (phpredis 5.x vs 6.x)

`tests/SharedStateTest.php` defines real `\Redis` subclasses (`LiveHandleRedis`,
`DeadHandleRedis`) because `Applications/Chat/SharedState.php` prefers a handle
via `instanceof \Redis`. phpredis 5.x declares those methods untyped; 6.x
declares real (non-tentative) types — `Redis::get(string $key): mixed`,
`Redis::set()`/`Redis::ping(): Redis|string|bool`. CI installs 6.x
(`.github/workflows/ci.yml` `extensions: curl, redis, ...`) while dev hosts are
still on 5.3.7, so an untyped override is a hard fatal on CI, not a deprecation.

## Rules

- **Params: a lone variadic.** `public function get(...$args)` is compatible
  with any parent parameter list on both versions. Read positionally
  (`$key = $args[0];`).
- **Return: the narrowest type the double really produces** — e.g.
  `: string|false` for `get()`, `: bool` for `set()`/`ping()`. Those stay
  covariant with `mixed`, with `Redis|string|bool`, and with no parent return
  type at all.
- **Never "simplify" back to `get($key)` / `set($key, $value, $opts = null)`** —
  that is exactly what broke CI.
- Verify locally with `php vendor/bin/phpunit`; the suite is offline (doubles
  from `tests/TestBootstrap.php`), so no Redis server is required. CI adds
  `--display-deprecations` so PHP 8.4-only deprecations are printed, not just
  counted.
