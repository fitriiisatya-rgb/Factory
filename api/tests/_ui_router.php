<?php

declare(strict_types=1);

/**
 * php -S router script used ONLY by run-ui-preview.sh's disposable test
 * server. Every other orchestrator's `php -S -t "$API_ROOT"` (docroot =
 * the api/ folder itself) works fine without a router, because none of
 * them serve real static asset files — but this phase's CSS/JS live at
 * api/assets/*, referenced with a leading /api/ prefix (correct for the
 * REAL cPanel deployment, where docroot is public_html/factory/ and
 * api/ is a real subdirectory of it — and where api/assets/ is publicly
 * servable precisely BECAUSE it sits outside the deny-all api/app/
 * directory, see api/app/.htaccess). Locally, docroot IS the api/
 * folder, so a literal "/api/assets/..." request has no matching file
 * (it would need a nonexistent nested api/api/ directory). This router
 * only special-cases that one mismatch; every other request (including
 * the API routes and every _admin-login//_do-uat/-style UAT page, which
 * rely on the built-in server's own directory-index/index.php-fallback
 * behavior) falls straight through unchanged.
 */

$uri = urldecode((string) parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH));

if (preg_match('#^/api/(assets/[\w./-]+\.(css|js|png|jpg|jpeg|svg))$#', $uri, $m)) {
    $file = __DIR__ . '/../' . $m[1];
    if (is_file($file)) {
        $types = [
            'css' => 'text/css; charset=UTF-8', 'js' => 'application/javascript; charset=UTF-8',
            'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml',
        ];
        header('Content-Type: ' . $types[$m[2]]);
        readfile($file);
        return true;
    }
}

return false; // let the built-in server's normal file/index.php-fallback handling take it from here
