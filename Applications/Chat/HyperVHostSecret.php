<?php

/**
 * The Hyper-V host Administrator password (vps_master_details.vps_root of a
 * get_service_master() row), opened right before a SOAP call (MyAdmin
 * plan_2way.md §5.11, Q40).
 *
 * Through MyAdmin core's ServiceSecrets::readColumn(): plaintext comes back
 * exactly as stored, a SecretBox envelope is opened. One that will not open
 * (missing keyring, wrong row, bad value) is alerted, never with its value, and
 * gives false, so the task sends nothing to that host. A /home/my tree without
 * the class gets the stored value, as before.
 */
final class HyperVHostSecret
{
    /**
     * @param array<string,mixed> $master
     * @param string $where what is about to use it, for the alert
     * @return mixed the password, or false when it will not open
     */
    public static function password(array $master, string $where)
    {
        $stored = $master['vps_root'] ?? null;
        if (!class_exists('MyAdmin\\Security\\ServiceSecrets')) {
            return $stored;
        }
        try {
            return \MyAdmin\Security\ServiceSecrets::readColumn('vps_master_details', 'vps_root', $master['vps_id'] ?? '', $stored === null ? null : (string) $stored);
        } catch (\MyAdmin\Security\SecretBoxException | \InvalidArgumentException $e) {
            $msg = "{$where}: the host password of vps master ".(int) ($master['vps_id'] ?? 0).' did not open';
            if (class_exists('MyAdmin\\Security\\SecretBoxAlert')) {
                \MyAdmin\Security\SecretBoxAlert::report('vps', $msg, $e);
            } else {
                echo 'secretbox '.$msg.': '.get_class($e).PHP_EOL;
            }
            return false;
        }
    }
}
