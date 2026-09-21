<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

use Amor\Api\Config;

/**
 * Picks the send mechanism. MAIL_TRANSPORT is a TEST-ONLY escape hatch —
 * deliberately undocumented in config.example.php/the README, so a real
 * cPanel operator never sees or sets it; the automated test harness sets
 * it explicitly in its own disposable config.php (task's own "do not send
 * real email from automated tests by default").
 */
final class MailTransportFactory
{
    public static function create(): MailTransport
    {
        if (Config::get('MAIL_TRANSPORT', 'smtp') === 'fake') {
            return new FakeMailTransport((string) Config::get('MAIL_FAKE_LOG_PATH', sys_get_temp_dir() . '/amor-mail-fake.jsonl'));
        }

        return new SmtpMailTransport(
            (string) Config::get('MAIL_HOST', ''),
            (int) Config::get('MAIL_PORT', 587),
            (string) Config::get('MAIL_USERNAME', ''),
            (string) Config::get('MAIL_PASSWORD', ''),
            (string) Config::get('MAIL_ENCRYPTION', 'tls'),
        );
    }
}
