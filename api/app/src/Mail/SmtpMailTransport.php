<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

/**
 * A small, from-scratch SMTP client — audited first (this project has no
 * Composer/vendor dependency at all, see autoload.php's own docblock, and
 * a large mail library would be the first one; task's own explicit
 * caution against "casually introducing a large dependency"). PHP's
 * native mail() was deliberately NOT used either — it depends on the
 * host's own sendmail/MTA configuration, which real cPanel shared hosting
 * frequently has locked down or misconfigured in ways this project has no
 * visibility into, whereas a direct SMTP connection to a known mailbox
 * (Gmail, a domain's own SMTP, etc.) behaves identically everywhere.
 *
 * Deliberately supports exactly what real-world sending needs and no
 * more: plain / STARTTLS (587) / implicit TLS (465), AUTH LOGIN (works
 * against Gmail App Passwords and virtually every other provider), one
 * recipient, one HTML part. No connection pooling, no queueing — each
 * send() opens a fresh connection and closes it (matches Part O's "no
 * background worker" constraint; a few hundred milliseconds to a few
 * seconds of SMTP round-trip per departure is an accepted, documented
 * cost — see ShipmentEmailService's own docblock).
 *
 * Every failure path returns MailSendResult::failure() with a FRIENDLY,
 * credential-free message — never the raw socket error text verbatim
 * (which could echo the configured host/port), and the password is never
 * written to any log, response, or exception message anywhere in this
 * class.
 */
final class SmtpMailTransport implements MailTransport
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $encryption, // 'tls' | 'ssl' | 'none'
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    public function send(MailMessage $message): MailSendResult
    {
        $stream = null;
        try {
            $stream = $this->connect();
            $this->expect($stream, 220);
            $this->command($stream, 'EHLO amorfactory.local', 250);

            if ($this->encryption === 'tls') {
                $this->command($stream, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('Gagal mengaktifkan TLS ke server email');
                }
                $this->command($stream, 'EHLO amorfactory.local', 250);
            }

            if ($this->username !== '') {
                $this->command($stream, 'AUTH LOGIN', 334);
                $this->command($stream, base64_encode($this->username), 334);
                $this->command($stream, base64_encode($this->password), 235);
            }

            $this->command($stream, 'MAIL FROM:<' . $message->fromEmail . '>', 250);
            $this->command($stream, 'RCPT TO:<' . $message->toEmail . '>', [250, 251]);
            $this->command($stream, 'DATA', 354);

            fwrite($stream, $this->buildRawMessage($message));
            fwrite($stream, "\r\n.\r\n");
            $this->readResponse($stream, 250);

            $this->command($stream, 'QUIT', 221);
            fclose($stream);

            return MailSendResult::success();
        } catch (\Throwable $e) {
            if (is_resource($stream)) {
                @fclose($stream);
            }
            return MailSendResult::failure($this->friendlyError($e));
        }
    }

    /** @return resource */
    private function connect()
    {
        $prefix = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create();
        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $prefix . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($stream === false) {
            throw new \RuntimeException('Tidak dapat terhubung ke server email');
        }
        stream_set_timeout($stream, $this->timeoutSeconds);
        return $stream;
    }

    /** @param resource $stream */
    private function command($stream, string $line, int|array $expectedCode)
    {
        fwrite($stream, $line . "\r\n");
        return $this->readResponse($stream, $expectedCode);
    }

    /** @param resource $stream */
    private function expect($stream, int|array $expectedCode)
    {
        return $this->readResponse($stream, $expectedCode);
    }

    /** @param resource $stream */
    private function readResponse($stream, int|array $expectedCode): string
    {
        $expected = is_array($expectedCode) ? $expectedCode : [$expectedCode];
        $full = '';
        do {
            $line = fgets($stream, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($stream);
                if ($meta['timed_out'] ?? false) {
                    throw new \RuntimeException('Waktu tunggu server email habis (timeout)');
                }
                throw new \RuntimeException('Koneksi ke server email terputus');
            }
            $full .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int) substr($full, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new \RuntimeException('Server email menolak permintaan (kode ' . $code . ')');
        }
        return $full;
    }

    private function buildRawMessage(MailMessage $message): string
    {
        $date = gmdate('D, d M Y H:i:s O');
        $messageId = '<' . bin2hex(random_bytes(16)) . '@amorfactory>';
        $encodedSubject = '=?UTF-8?B?' . base64_encode($message->subject) . '?=';
        $fromHeader = $this->encodeAddressHeader($message->fromName, $message->fromEmail);
        $toHeader = $this->encodeAddressHeader($message->toName ?? '', $message->toEmail);

        // Dot-stuffing per RFC 5321 §4.5.2 — a line that begins with '.'
        // must be escaped, or the SMTP server treats it as end-of-DATA.
        $body = preg_replace('/^\./m', '..', $message->htmlBody) ?? $message->htmlBody;

        $headers = [
            'Date: ' . $date,
            'Message-ID: ' . $messageId,
            'From: ' . $fromHeader,
            'To: ' . $toHeader,
            'Subject: ' . $encodedSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $body;
    }

    private function encodeAddressHeader(string $name, string $email): string
    {
        if ($name === '') {
            return $email;
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    private function friendlyError(\Throwable $e): string
    {
        // The exception messages thrown throughout this class are already
        // friendly/credential-free (never include $this->password, and
        // never echo the raw socket errstr which could contain the host).
        // A message from anywhere else (e.g. a TypeError) falls back to a
        // generic line rather than risking an unreviewed string.
        $message = $e->getMessage();
        $known = [
            'Tidak dapat terhubung ke server email',
            'Waktu tunggu server email habis (timeout)',
            'Koneksi ke server email terputus',
            'Gagal mengaktifkan TLS ke server email',
        ];
        foreach ($known as $k) {
            if ($message === $k) {
                return $message;
            }
        }
        if (str_starts_with($message, 'Server email menolak permintaan')) {
            return $message;
        }
        return 'Gagal mengirim email (kesalahan tidak terduga)';
    }
}
