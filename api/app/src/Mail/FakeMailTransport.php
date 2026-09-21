<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

/**
 * Test-only transport — NEVER used in a real deployment (see
 * MailTransportFactory: only selected when MAIL_TRANSPORT=fake, a key
 * that is deliberately absent from config.example.php/the README so a
 * real cPanel operator never stumbles onto it).
 *
 * "Sending" here means appending one JSON line to a log file, so the
 * automated test harness — a SEPARATE PHP process from the one running
 * this code (the disposable php -S / php-fpm server under test) — can
 * still observe exactly what would have been emailed: recipient,
 * subject, and the full rendered body (to assert real driver name/
 * shipment number/DO/totals/token-gated link appear correctly), without
 * ever opening a real network connection.
 *
 * One test-only failure hook: a recipient address containing the
 * substring "simulate-smtp-failure" makes send() return
 * MailSendResult::failure() instead of writing to the log — this is how
 * MAIL-08/09/10 (an email failure must never roll back a shipment/stock
 * write) exercise the failure path without needing a real unreachable
 * SMTP host. Never checked by SmtpMailTransport, so it has zero effect
 * on a real send.
 */
final class FakeMailTransport implements MailTransport
{
    private const SIMULATED_FAILURE_MARKER = 'simulate-smtp-failure';

    public function __construct(private readonly string $logPath)
    {
    }

    public function send(MailMessage $message): MailSendResult
    {
        if (str_contains($message->toEmail, self::SIMULATED_FAILURE_MARKER)) {
            return MailSendResult::failure('Simulated SMTP failure (test-only)');
        }

        $dir = dirname($this->logPath);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            return MailSendResult::failure('Fake mail log directory could not be created: ' . $dir);
        }
        $line = json_encode([
            'to' => $message->toEmail,
            'toName' => $message->toName,
            'from' => $message->fromEmail,
            'fromName' => $message->fromName,
            'subject' => $message->subject,
            'htmlBody' => $message->htmlBody,
            'sentAt' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        // Never silently reports success when nothing was actually
        // written (e.g. a permission problem writing into $dir) — a
        // fake transport that lies about having sent something would
        // mask a real bug from every test that trusts this log.
        if (@file_put_contents($this->logPath, $line . "\n", FILE_APPEND | LOCK_EX) === false) {
            return MailSendResult::failure('Fake mail log could not be written: ' . $this->logPath);
        }

        return MailSendResult::success();
    }
}
