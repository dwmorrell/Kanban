<?php
/**
 * cron/reset_password.php — sets a new password for a login, for when
 * nobody can sign in. CLI only.
 *
 * Usage: php cron/reset_password.php someone@yourvenue.com NewPassword123
 *
 * Ports scripts/reset-password.js (archive/node-event-board), swapping its
 * hand-rolled scrypt for password_hash()/PASSWORD_DEFAULT — the same change
 * lib/auth.php already made for every other password path (/api/setup,
 * /api/login, /api/password, /api/users). Also revokes the account's
 * existing sessions, same as the Node version, so a stolen/forgotten
 * session can't outlive the reset.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}

require_once __DIR__ . '/../lib/auth.php';

[, $emailArg, $passwordArg] = array_pad($argv, 3, null);

if (!is_string($emailArg) || !is_string($passwordArg) || strlen($passwordArg) < 8) {
    fwrite(STDERR, "Usage: php cron/reset_password.php email@example.com NewPassword (at least 8 characters)\n");
    exit(1);
}

$email = clean_email($emailArg);
if (!valid_email($email)) {
    fwrite(STDERR, "That doesn't look like a valid email address.\n");
    exit(1);
}
try {
    check_new_password($passwordArg);
} catch (ApiError $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$user = Q::userByEmail($email);
if ($user === null) {
    fwrite(STDERR, "No login found for $email.\n");
    exit(1);
}

Q::setUserPass(hash_password($passwordArg), $user['id']);
Q::delUserSessions($user['id']);

echo "Password updated for $email. They can sign in with it now.\n";
