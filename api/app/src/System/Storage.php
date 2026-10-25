<?php

declare(strict_types=1);

namespace Amor\Api\System;

/**
 * Private server-side folder for backups and the error log:
 * api/app/storage/ (inside the deny-all api/app/ tree, never shipped in a
 * package, never reachable by URL). Created on first use.
 */
final class Storage
{
    public static function dir(string $sub): string
    {
        $root = dirname(__DIR__, 2) . '/storage';
        $dir = $root . '/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (!is_file($root . '/.htaccess')) {
            @file_put_contents($root . '/.htaccess', "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
        }
        return $dir;
    }
}
