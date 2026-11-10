<?php

/**
 * The legacy username/password admin login of msgLogin (the chat UI's
 * `login` message from Web/index.html + Web/js/groups.js).
 *
 * MyAdmin's plan_hash.md (§6.9, Q16, R2-5) moves account passwords from
 * unsalted md5 to Argon2id in a second column, account_password_hash. The old
 * branch compared `account_passwd = md5(:password)` in SQL, which:
 *  - stops working for an admin who changes their password once MyAdmin writes
 *    native hashes (R3), and for every admin once md5 is blanked (R4b);
 *  - sent an md5 of the password into the SQL text;
 *  - had no rate limit, so it was an unthrottled oracle for admin passwords.
 *
 * Now: the row is read by login only, and the password is verified in PHP by
 * MyAdmin's \MyAdmin\Security\PasswordHasher (autoloaded from /home/my, which
 * start.php loads) against both columns: account_password_hash first, then
 * account_passwd while MyAdmin still accepts raw md5. PasswordHasher reads
 * /home/my/include/config/password_hashing.php itself, once per process, so a
 * flag flip needs a hub restart (every R-step deploy restarts it).
 *
 * If PasswordHasher is not there (the hub deployed before the MyAdmin side),
 * it falls back to exactly today's check, md5 of the raw password against
 * account_passwd, compared in PHP with hash_equals.
 *
 * Failures are rate limited in Redis (SharedState, dc:state:), with MyAdmin's
 * login limiter thresholds: 5 per login+IP, 30 per IP, 20 per login, per hour.
 * Over a limit the attempt is refused before any hashing (an Argon2 verify is
 * tens of milliseconds of the single-threaded event loop). Only the login and
 * the IP are logged, never the password.
 */
final class AdminPasswordLogin
{
    public const WINDOW = 3600;
    public const LIMIT_LOGIN_IP = 5;
    public const LIMIT_IP = 30;
    public const LIMIT_LOGIN = 20;
    public const KEY_PREFIX = SharedState::PREFIX_STATE.'admin_login_fail:';

    public const COL_LEGACY = 'account_passwd';
    public const COL_HASH = 'account_password_hash';

    /** @var object|null|false test seam: a PasswordHasher-like object; false = none */
    private static $hasher = null;

    /** The failure counter hash for the window that contains $now. */
    public static function key(int $now): string
    {
        return self::KEY_PREFIX.intdiv($now, self::WINDOW);
    }

    /**
     * @return array<string,int> counter field => limit
     */
    public static function limits(string $login, string $ip): array
    {
        $login = strtolower(trim($login));
        return [
            'login_ip:'.$login.'|'.$ip => self::LIMIT_LOGIN_IP,
            'ip:'.$ip => self::LIMIT_IP,
            'login:'.$login => self::LIMIT_LOGIN,
        ];
    }

    /** false once any counter for this login / IP has reached its limit. */
    public static function allowed(string $login, string $ip, ?int $now = null): bool
    {
        $key = self::key($now ?? time());
        foreach (self::limits($login, $ip) as $field => $limit) {
            if ((int) SharedState::hGet($key, $field) >= $limit) {
                return false;
            }
        }
        return true;
    }

    public static function recordFailure(string $login, string $ip, ?int $now = null): void
    {
        $key = self::key($now ?? time());
        foreach (self::limits($login, $ip) as $field => $limit) {
            SharedState::hIncr($key, $field);
        }
        SharedState::expire($key, 2 * self::WINDOW);
    }

    /**
     * Does $password match the admin row? $row null = no such admin (costs a
     * dummy verify when MyAdmin's dummy_verify flag is on, as MyAdmin's own
     * login does).
     *
     * @param array<string,mixed>|null $row the accounts row (both password columns)
     */
    public static function verifyRow(?array $row, string $password): bool
    {
        if (trim($password) === '') {
            return false;
        }
        $hasher = self::hasher();
        if ($row === null) {
            if ($hasher !== null && class_exists('\MyAdmin\Security\PasswordHashFlags') && \MyAdmin\Security\PasswordHashFlags::dummyVerify()) {
                $hasher->dummyVerify($password);
            }
            return false;
        }
        $hash = self::column($row, self::COL_HASH);
        $legacy = self::column($row, self::COL_LEGACY);
        if ($hasher === null) {
            // today's check, in PHP: md5 of the raw password against account_passwd
            return $legacy !== '' && hash_equals(strtolower($legacy), md5($password));
        }
        $stored = [];
        if ($hash !== '') {
            $stored[] = $hash;
        }
        if ($legacy !== '' && $hasher->acceptsRawLegacy()) {
            $stored[] = $legacy;
        }
        foreach ($stored as $value) {
            if ($hasher->verify($password, $value)) {
                return true;
            }
        }
        return false;
    }

    /** Test seam: a PasswordHasher-like object, false for "no hasher", null to auto-detect. */
    public static function useHasher($hasher): void
    {
        self::$hasher = $hasher;
    }

    /** @return object|null */
    private static function hasher()
    {
        if (self::$hasher === false) {
            return null;
        }
        if (self::$hasher !== null) {
            return self::$hasher;
        }
        if (!class_exists('\MyAdmin\Security\PasswordHasher')) {
            return null;
        }
        return \MyAdmin\Security\PasswordHasher::fromConfig();
    }

    /** @param array<string,mixed> $row */
    private static function column(array $row, string $name): string
    {
        $v = $row[$name] ?? '';
        return is_string($v) ? $v : (is_scalar($v) ? (string) $v : '');
    }
}
