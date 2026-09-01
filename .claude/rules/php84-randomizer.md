---
description: Use \Random\Randomizer instead of lcg_value(), which PHP 8.4 deprecates
globs: Applications/Chat/*.php, Tasks/*.php, Web/*.php, scripts/*.php
---
# `\Random\Randomizer` instead of `lcg_value()`

`lcg_value()` is deprecated in PHP 8.4, and the CI `tests` job runs on 8.4 with
`--display-deprecations` (`.github/workflows/ci.yml`), so every remaining call
is printed by name. All call sites have been converted; do not reintroduce it.

## Rules

- **Hot paths keep one process-local instance.** `Applications/Chat/Events.php`
  holds `self::$randomizer` behind `private static function rng()`
  (`self::$randomizer ??= new \Random\Randomizer()`) — the bot spawn/wander
  timers tick often enough that a fresh Randomizer per call is pure waste. The
  engine CSPRNG-seeds it on construction; do not seed it manually.
- **Cold paths may build one inline.** `Tasks/memcached_queue_task.php` does
  `(new \Random\Randomizer())->getFloat(0.0, 1.0)` for its InnoDB-cluster retry
  jitter — that runs only on a PDO exception.
- **Keep `min + getFloat(0.0, 1.0) * span`; do NOT "tidy" it to
  `getFloat($min, $max)`.** `getFloat()` throws a `ValueError` when
  `$min >= $max`, where the old expression just returned `$min` — the tidier
  form adds a throw path inside a timer callback, and a throwing timer callback
  exits the worker (see the `safeTimerCallback()` note in `CLAUDE.md`).
- Verify with `php vendor/bin/phpunit`; CI re-checks on 8.3 and 8.4.
