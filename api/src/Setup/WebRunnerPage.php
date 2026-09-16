<?php

declare(strict_types=1);

namespace Amor\Api\Setup;

/**
 * Minimal shared HTML shell for the public/_setup/*.php web fallback
 * runners, so the three scripts don't each hand-roll their own markup.
 * Deliberately plain — this is a one-time operator tool, not a product UI.
 */
final class WebRunnerPage
{
    public static function confirmForm(string $title, string $token, string $plannedActionHtml, ?string $errorHtml = null): void
    {
        $tokenAttr = htmlspecialchars($token, ENT_QUOTES);
        $errorBlock = $errorHtml !== null
            ? '<p style="color:#b00;font-weight:bold;">' . $errorHtml . '</p>'
            : '';
        echo <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$title}</title></head>
<body style="font-family:monospace;max-width:640px;margin:2rem auto;">
<h1>{$title}</h1>
{$errorBlock}
<p>{$plannedActionHtml}</p>
<form method="post">
  <input type="hidden" name="token" value="{$tokenAttr}">
  <label>Type CONFIRM to proceed: <input type="text" name="confirm" autocomplete="off"></label>
  <button type="submit">Run</button>
</form>
<p style="color:#666;">This page never displays or transmits the database password.</p>
</body></html>
HTML;
    }

    public static function result(string $title, bool $ok, string $bodyHtml): void
    {
        $status = $ok ? 'SUCCESS' : 'FAILED';
        $color = $ok ? '#080' : '#b00';
        $deleteNotice = $ok
            ? '<p style="background:#fee;color:#b00;font-weight:bold;padding:1rem;border:2px solid #b00;">DELETE THIS FILE NOW — this web setup runner must not remain reachable after use. See api/DEPLOY-CPANEL-PREPROD.md section on cleanup.</p>'
            : '';
        echo <<<HTML
<!DOCTYPE html>
<html><head><meta charset="utf-8"><title>{$title}</title></head>
<body style="font-family:monospace;max-width:640px;margin:2rem auto;">
<h1>{$title}: <span style="color:{$color};">{$status}</span></h1>
{$bodyHtml}
{$deleteNotice}
</body></html>
HTML;
    }
}
