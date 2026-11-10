<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__.'/TestBootstrap.php';
require_once __DIR__.'/../Applications/Chat/SharedState.php';
require_once __DIR__.'/../Applications/Chat/AdminPasswordLogin.php';

/**
 * A PasswordHasher-shaped double: counts verifies, matches "<password>" against
 * the stored value "ok:<password>", and accepts raw md5 like MyAdmin's hasher.
 */
class AdminLoginFakeHasher
{
    public $verifies = 0;
    public $dummies = 0;
    public $acceptRaw = true;

    public function verify(string $password, string $stored): bool
    {
        $this->verifies++;
        if (preg_match('/^[a-f0-9]{32}$/i', $stored) === 1) {
            return $this->acceptRaw && hash_equals(strtolower($stored), md5($password));
        }
        return hash_equals($stored, 'ok:'.$password);
    }

    public function acceptsRawLegacy(): bool
    {
        return $this->acceptRaw;
    }

    public function dummyVerify(string $password): void
    {
        $this->dummies++;
    }
}

/**
 * The username/password admin login of msgLogin (MyAdmin plan_hash.md §6.9,
 * Q16, R2-5): both password columns, the hasher when MyAdmin has it and
 * today's md5 check when it does not, and the Redis failure limit.
 */
class AdminPasswordLoginTest extends TestCase
{
    /** @var InMemoryRedis */
    private $redis;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        SharedState::setClient($this->redis);
        AdminPasswordLogin::useHasher(null);
    }

    protected function tearDown(): void
    {
        AdminPasswordLogin::useHasher(null);
        SharedState::setClient(null);
    }

    public function testWithoutMyAdminsHasherItIsTodaysMd5Check(): void
    {
        AdminPasswordLogin::useHasher(false);
        $row = ['account_passwd' => md5('S3cret&x'), 'account_password_hash' => ''];
        $this->assertTrue(AdminPasswordLogin::verifyRow($row, 'S3cret&x'));
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => strtoupper(md5('S3cret&x'))] + $row, 'S3cret&x'), 'the column compare was case-insensitive');
        $this->assertFalse(AdminPasswordLogin::verifyRow($row, 'S3cret&amp;x'));
        $this->assertFalse(AdminPasswordLogin::verifyRow($row, ''));
        $this->assertFalse(AdminPasswordLogin::verifyRow(['account_passwd' => '', 'account_password_hash' => ''], 'x'));
        $this->assertFalse(AdminPasswordLogin::verifyRow(null, 'S3cret&x'));
    }

    public function testBothColumnsNewestFirst(): void
    {
        $hasher = new AdminLoginFakeHasher();
        AdminPasswordLogin::useHasher($hasher);
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => '', 'account_password_hash' => 'ok:pw1'], 'pw1'), 'native only (after R3 / R4b)');
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw2'), 'account_password_hash' => ''], 'pw2'), 'md5 only (today)');
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw3'), 'account_password_hash' => 'ok:pw3'], 'pw3'), 'both (R4a)');
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw4')], 'pw4'), 'a row from before R1 has no hash column');
        $this->assertFalse(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw5'), 'account_password_hash' => 'ok:other'], 'nope'));
        $hasher->acceptRaw = false;
        $this->assertFalse(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw6'), 'account_password_hash' => ''], 'pw6'), 'raw md5 refused once MyAdmin stops accepting it (R6)');
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => md5('pw7'), 'account_password_hash' => 'ok:pw7'], 'pw7'));
    }

    public function testAnUnknownAdminMatchesNothing(): void
    {
        $hasher = new AdminLoginFakeHasher();
        AdminPasswordLogin::useHasher($hasher);
        $this->assertFalse(AdminPasswordLogin::verifyRow(null, 'whatever'));
        $this->assertSame(0, $hasher->verifies);
    }

    public function testFailuresAreLimitedPerLoginAndIp(): void
    {
        $now = 1_800_000_000;
        for ($i = 0; $i < AdminPasswordLogin::LIMIT_LOGIN_IP; $i++) {
            $this->assertTrue(AdminPasswordLogin::allowed('Admin@Example.com', '203.0.113.5', $now));
            AdminPasswordLogin::recordFailure('Admin@Example.com', '203.0.113.5', $now);
        }
        $this->assertFalse(AdminPasswordLogin::allowed('admin@example.com ', '203.0.113.5', $now), 'the login is case and space insensitive');
        $this->assertTrue(AdminPasswordLogin::allowed('admin@example.com', '203.0.113.6', $now), 'another IP still has its own budget');
        $this->assertTrue(AdminPasswordLogin::allowed('admin@example.com', '203.0.113.5', $now + AdminPasswordLogin::WINDOW), 'the next window starts fresh');
    }

    public function testFailuresAreLimitedPerIpAcrossLogins(): void
    {
        $now = 1_800_000_000;
        for ($i = 0; $i < AdminPasswordLogin::LIMIT_IP; $i++) {
            AdminPasswordLogin::recordFailure('user'.$i.'@example.com', '198.51.100.7', $now);
        }
        $this->assertFalse(AdminPasswordLogin::allowed('fresh@example.com', '198.51.100.7', $now));
    }

    public function testFailuresAreLimitedPerLoginAcrossIps(): void
    {
        $now = 1_800_000_000;
        for ($i = 0; $i < AdminPasswordLogin::LIMIT_LOGIN; $i++) {
            AdminPasswordLogin::recordFailure('target@example.com', '192.0.2.'.$i, $now);
        }
        $this->assertFalse(AdminPasswordLogin::allowed('target@example.com', '192.0.2.250', $now));
    }

    public function testCountersExpireAndHoldNoPassword(): void
    {
        $now = 1_800_000_000;
        AdminPasswordLogin::recordFailure('a@example.com', '203.0.113.9', $now);
        $key = AdminPasswordLogin::key($now);
        $this->assertStringStartsWith('dc:state:admin_login_fail:', $key);
        $this->assertSame(1, (int) SharedState::hGet($key, 'login:a@example.com'));
        $this->assertSame(1, (int) SharedState::hGet($key, 'ip:203.0.113.9'));
        $this->assertSame(1, (int) SharedState::hGet($key, 'login_ip:a@example.com|203.0.113.9'));
    }

    public function testTheLegacyBranchNoLongerComparesAPasswordInSql(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../Applications/Chat/Events.php');
        $this->assertStringNotContainsString('account_passwd= :password', $src);
        $this->assertStringNotContainsString("md5(\$message_data['password'])", $src);
        $this->assertStringContainsString('AdminPasswordLogin::verifyRow(', $src);
        $this->assertStringContainsString('AdminPasswordLogin::allowed(', $src);
        $this->assertStringContainsString('AdminPasswordLogin::recordFailure(', $src);
    }

    public function testTheChatUiNoLongerLogsTheLoginMessage(): void
    {
        $js = (string) file_get_contents(__DIR__.'/../Web/js/groups.js');
        $this->assertStringNotContainsString('console.log(login_data)', $js);
        $this->assertStringNotContainsString('send login data: "+JSON.stringify(login_data)', $js);
    }

    /**
     * With MyAdmin's real PasswordHasher (when it is autoloadable): md5, a
     * wrapped md5 and a native hash all verify.
     */
    public function testWithTheRealMyAdminHasher(): void
    {
        if (!class_exists('\MyAdmin\Security\PasswordHasher')) {
            $this->markTestSkipped('MyAdmin PasswordHasher not autoloadable here');
        }
        $hasher = new \MyAdmin\Security\PasswordHasher(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1]);
        AdminPasswordLogin::useHasher($hasher);
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => md5('Adm1n!x'), 'account_password_hash' => ''], 'Adm1n!x'));
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => '', 'account_password_hash' => $hasher->wrapLegacy(md5('Adm1n!x'))], 'Adm1n!x'));
        $this->assertTrue(AdminPasswordLogin::verifyRow(['account_passwd' => '', 'account_password_hash' => $hasher->hash('Adm1n!x')], 'Adm1n!x'));
        $this->assertFalse(AdminPasswordLogin::verifyRow(['account_passwd' => '', 'account_password_hash' => $hasher->hash('Adm1n!x')], 'adm1n!x'));
    }
}
