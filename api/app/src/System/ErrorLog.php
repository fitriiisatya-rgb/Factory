<?php

declare(strict_types=1);

namespace Amor\Api\System;

use Amor\Api\Auth;
use Amor\Api\Config;
use Amor\Api\Mail\MailMessage;
use Amor\Api\Mail\MailTransportFactory;

/**
 * System errors (500s, PHP fatals on pages, failed backups) kept as JSON
 * lines in api/app/storage/logs/errors-YYYY-MM.log so Admin sees them on
 * "Hari Ini" and Pengaturan → Sistem — no more "nobody knew the FG page
 * was broken". Optional e-mail: set ERROR_ALERT_EMAIL (and MAIL_*) in
 * config.php; at most one mail per hour.
 */
final class ErrorLog
{
    public static function record(string $type, string $message, string $file = '', int $line = 0): void
    {
        try {
            $entry = [
                't' => date('c'), 'type' => $type, 'msg' => mb_substr($message, 0, 500),
                'where' => $file !== '' ? basename($file) . ':' . $line : '',
                'path' => mb_substr((string) ($_SERVER['REQUEST_METHOD'] ?? 'CLI') . ' ' . strtok((string) ($_SERVER['REQUEST_URI'] ?? ''), '?'), 0, 200),
                'user' => self::userName(),
            ];
            $f = Storage::dir('logs') . '/errors-' . date('Y-m') . '.log';
            @file_put_contents($f, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND | LOCK_EX);
            self::maybeMail($entry);
        } catch (\Throwable) {
            // logging must never break the request
        }
    }

    /** @return array<int,array<string,string>> newest first */
    public static function recent(int $limit = 100, ?int $sinceTs = null): array
    {
        $files = glob(Storage::dir('logs') . '/errors-*.log') ?: [];
        rsort($files);
        $out = [];
        foreach (array_slice($files, 0, 2) as $f) {
            $lines = @file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            foreach (array_reverse($lines) as $l) {
                $e = json_decode($l, true);
                if (!is_array($e)) {
                    continue;
                }
                if ($sinceTs !== null && strtotime((string) $e['t']) < $sinceTs) {
                    break 2;
                }
                $out[] = $e;
                if (count($out) >= $limit) {
                    break 2;
                }
            }
        }
        return $out;
    }

    public static function countSince(int $ts): int
    {
        return count(self::recent(1000, $ts));
    }

    /** Catches PHP fatals on server-rendered pages (register once per request). */
    public static function registerFatalHandler(): void
    {
        register_shutdown_function(static function (): void {
            $e = error_get_last();
            if ($e !== null && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                self::record('fatal', (string) $e['message'], (string) $e['file'], (int) $e['line']);
            }
        });
    }

    private static function userName(): string
    {
        try {
            if (session_status() === PHP_SESSION_ACTIVE && Auth::currentUserId() !== null) {
                return (string) ($_SESSION['username'] ?? ('#' . Auth::currentUserId()));
            }
        } catch (\Throwable) {
        }
        return '';
    }

    private static function maybeMail(array $entry): void
    {
        $to = (string) (Config::get('ERROR_ALERT_EMAIL') ?? '');
        if ($to === '' || Config::get('MAIL_ENABLED') !== true) {
            return;
        }
        $stamp = Storage::dir('logs') . '/.last-mail';
        if (is_file($stamp) && time() - (int) filemtime($stamp) < 3600) {
            return;
        }
        @touch($stamp);
        $esc = static fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
        $html = '<div style="font-family:sans-serif"><p>Ada error di Amor Factory System.</p><table cellpadding="4">'
            . '<tr><td>Waktu</td><td>' . $esc($entry['t']) . '</td></tr><tr><td>Jenis</td><td>' . $esc($entry['type']) . '</td></tr>'
            . '<tr><td>Halaman</td><td>' . $esc($entry['path']) . '</td></tr><tr><td>User</td><td>' . $esc($entry['user']) . '</td></tr>'
            . '<tr><td>Pesan</td><td>' . $esc($entry['msg']) . '</td></tr></table>'
            . '<p>Lihat semua di Pengaturan → Sistem. Email berikutnya paling cepat 1 jam lagi.</p></div>';
        MailTransportFactory::create()->send(new MailMessage($to, null, (string) Config::get('MAIL_FROM_ADDRESS', 'factory@amorgroup.id'),
            (string) Config::get('MAIL_FROM_NAME', 'Amor Factory System'), '[Amor Factory] Error sistem', $html));
    }
}
