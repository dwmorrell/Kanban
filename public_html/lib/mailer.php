<?php
/**
 * Email alerts, via PHP's built-in mail() rather than configuring SMTP by
 * hand (see docs/PHP_REWRITE_PLAN.md — cPanel wires mail() up out of the
 * box, and this keeps the port at zero third-party dependencies). Off
 * unless MAIL_ENABLED is defined truthy in config.php (item 8).
 *
 * Ports server.js's mailer()/sendMail()/skipMail()/notifyChanges() 1:1.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function mail_enabled(): bool
{
    return defined('MAIL_ENABLED') && MAIL_ENABLED === true;
}

function mail_from(): string
{
    return defined('MAIL_FROM') ? MAIL_FROM : 'Event Board <no-reply@localhost>';
}

/** @throws RuntimeException if mail() itself reports failure. */
function send_mail(string $to, string $subject, string $text): bool
{
    if (!mail_enabled()) {
        return false;
    }
    $headers = 'From: ' . mail_from() . "\r\n" . "Content-Type: text/plain; charset=utf-8\r\n";
    // mail()'s return value only means "handed off to the local MTA", same
    // caveat server.js's nodemailer call has (success here isn't a delivery
    // guarantee) — good enough to report "the request didn't fail outright".
    if (!mail($to, $subject, $text, $headers)) {
        throw new RuntimeException('mail() reported failure.');
    }
    return true;
}

function skip_mail(string $email, array $actor): bool
{
    return !valid_email($email) || str_ends_with($email, '@example.com') || $email === $actor['email'];
}

/**
 * Fire-and-forget email alerts after a doc write: @ mentions in "waiting"
 * that weren't already there before, and a new task assignee. Never throws
 * past this function — callers should still wrap it, since mail() itself
 * can throw (see send_mail()), and a mail failure must never fail the
 * request that triggered it (mirrors server.js's
 * `.catch((e) => console.error('Email failed:', e.message))`).
 *
 * $before/$after are assoc-decoded doc bodies (or null for $before, on a
 * brand new doc), $actor is the acting user's row from Q::userById()/
 * current_user().
 */
function notify_changes(string $col, string $id, ?array $before, array $after, array $actor): void
{
    if (!mail_enabled()) {
        return;
    }
    $kind = $col === 'tasks' ? 'task' : 'event';
    $title = (string) ($after['title'] ?? '');
    $title = $title !== '' ? $title : "Untitled $kind";
    $who = ($actor['name'] ?? '') !== '' ? $actor['name'] : $actor['email'];
    $link = (defined('BASE_URL') && BASE_URL !== '')
        ? "\n\nOpen the card: " . rtrim(BASE_URL, '/') . "/#card-$id"
        : '';

    $known = [];
    foreach ((array) ($before['waiting'] ?? []) as $w) {
        if (is_array($w) && isset($w['id'])) {
            $known[(string) $w['id']] = true;
        }
    }
    foreach ((array) ($after['waiting'] ?? []) as $w) {
        if (!is_array($w) || !empty($w['done']) || isset($known[(string) ($w['id'] ?? '')])) {
            continue;
        }
        $to = clean_email($w['email'] ?? '');
        if (skip_mail($to, $actor)) {
            continue;
        }
        try {
            send_mail(
                $to,
                "$who is waiting on you: $title",
                "$who is waiting on you for the $kind \"$title\" on Event Board.\n\n"
                    . 'What they need: ' . (($w['what'] ?? '') !== '' ? $w['what'] : 'Your input') . $link
                    . "\n\nWhen it's handled, tick it off on the card so they know."
            );
        } catch (Throwable $e) {
            error_log('Email failed: ' . $e->getMessage());
        }
    }

    if ($col === 'tasks') {
        $to = clean_email($after['assignee'] ?? '');
        $prevTo = clean_email($before['assignee'] ?? '');
        if ($to !== '' && $to !== $prevTo && !skip_mail($to, $actor)) {
            $due = !empty($after['due']) ? "\nDue: " . $after['due'] : '';
            $notes = !empty($after['notes']) ? "\n\nNotes:\n" . substr((string) $after['notes'], 0, 1000) : '';
            try {
                send_mail($to, "$who assigned you a task: $title", "$who assigned you \"$title\" on Event Board.$due$notes$link");
            } catch (Throwable $e) {
                error_log('Email failed: ' . $e->getMessage());
            }
        }
    }
}
