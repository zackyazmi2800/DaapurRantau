<?php

namespace App\Support;

/**
 * OS account (real UID) running an Artisan command, instead of anything typed on the command line
 * or set in the environment. This identifies an account, not a person: `sudo -u`, `su` and root can
 * run as any account, and anyone who can write the database or bootstrap the application can bypass
 * it. Two different UIDs are therefore not proof of two different people.
 */
class OperatorIdentity
{
    /** @return array{uid: int, name: string}|null null when no independent identity can be proven */
    public function current(): ?array
    {
        if (PHP_SAPI !== 'cli' || ! function_exists('posix_getuid') || ! function_exists('posix_getpwuid')) {
            return null;
        }
        $uid = posix_getuid();
        // Refuse root and setuid-style processes (real UID differs from effective UID). This does NOT
        // detect `sudo -u <account>` or `su`, which switch both UIDs to the target account.
        // SUDO_UID and similar variables are not trusted because any user can set them.
        if ($uid === 0 || $uid !== posix_geteuid()) {
            return null;
        }
        $entry = posix_getpwuid($uid);
        $name = is_array($entry) ? ($entry['name'] ?? null) : null;

        return is_string($name) && preg_match('/\A[A-Za-z0-9._-]{1,64}\z/', $name) ? ['uid' => $uid, 'name' => $name] : null;
    }
}
