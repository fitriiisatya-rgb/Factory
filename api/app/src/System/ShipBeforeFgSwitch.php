<?php

declare(strict_types=1);

namespace Amor\Api\System;

use PDO;

/**
 * Pengaturan → "Kirim tanpa menunggu FG" (master_setting ship_before_fg).
 * Masa adaptasi: Admin may press Kirim before FG has packed in the system,
 * and FG enters its own real result afterwards — two separate records that
 * the Rekonsiliasi Kirim vs FG page lines up. While on:
 *   - Kirim is capped only by the DO's remaining qty, not by FG stock
 *     (FG stock may go negative until FG submits);
 *   - FG Submit no longer refuses packed < already shipped for a store.
 * Missing row = off (the strict rule: Kirim only what FG packed).
 */
final class ShipBeforeFgSwitch
{
    private const KEY = 'ship_before_fg';

    public static function enabled(PDO $pdo): bool
    {
        $st = $pdo->prepare('SELECT setting_value FROM master_setting WHERE setting_key = ?');
        $st->execute([self::KEY]);
        return $st->fetchColumn() === '1';
    }

    public static function set(PDO $pdo, bool $enabled): void
    {
        $pdo->prepare('INSERT INTO master_setting (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)')
            ->execute([self::KEY, $enabled ? '1' : '0']);
    }
}
