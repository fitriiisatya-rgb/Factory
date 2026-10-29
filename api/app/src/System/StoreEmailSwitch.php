<?php

declare(strict_types=1);

namespace Amor\Api\System;

use PDO;

/**
 * Pengaturan → "Email Surat Jalan ke Toko" on/off (master_setting
 * store_email_enabled). Masa adaptasi: toko belum memakai sistem, so the
 * Leader freezes these emails without wiping the stores' addresses.
 * Missing row = on (the behaviour before this switch existed). Frozen
 * shipments keep status "Belum Dikirim"; nothing re-sends them by itself
 * when the switch is turned back on (only an Admin's Kirim Ulang Email).
 * Error notifications to Admin are not affected (MAIL_ENABLED only).
 */
final class StoreEmailSwitch
{
    private const KEY = 'store_email_enabled';

    public static function enabled(PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT setting_value FROM master_setting WHERE setting_key = ?');
        $st->execute([self::KEY]);
        $v = $st->fetchColumn();
        return $v === false || $v === '1';
    }

    public static function set(PDO $pdo, bool $enabled): void
    {
        $pdo->prepare('INSERT INTO master_setting (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::KEY, $enabled ? '1' : '0']);
    }
}
