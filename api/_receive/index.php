<?php

declare(strict_types=1);

/**
 * PUBLIC Store Receipt portal (Phase 5.5, Part H) — reached by scanning the
 * QR printed on a Draft/Preprint DO. Deliberately NO session/login: access
 * control is entirely the high-entropy ?token= — every lookup goes through
 * ReceiptService -> ReceiptRepository::findDoIdByToken, never a raw
 * delivery_order_id. This page renders read-only via PHP (a plain SELECT
 * chain, same as any print page) and embeds that data for receipt.js to
 * render/interact with; the actual CONFIRM write always goes through the
 * real public JSON API (/api/receive/{token}/shipments/{id}/confirm), never
 * duplicated here.
 */

require __DIR__ . '/../app/autoload.php';

use Amor\Api\ApiException;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Dispatch\ReceiptService;

function rc_esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES);
}

try {
    Config::load();
} catch (\Throwable $e) {
    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body style="font-family:sans-serif;max-width:480px;margin:2rem auto;">'
        . '<h1>Konfigurasi belum lengkap</h1><p>' . htmlspecialchars($e->getMessage()) . '</p></body></html>';
    exit;
}

$token = (string) ($_GET['token'] ?? '');
$view = null;
$errorMessage = null;

if ($token === '') {
    $errorMessage = 'Tautan tidak valid — parameter token tidak ditemukan.';
} else {
    try {
        $service = new ReceiptService(Database::pdo());
        $view = $service->getPublicView($token);
    } catch (ApiException $e) {
        $errorMessage = $e->getMessage();
    }
}

// Automatic-email ask (Part H): the CTA link in a per-shipment email may
// include a non-authoritative ?shipment= hint so the store lands
// directly on THAT shipment's card. The token remains the sole access
// authority (checked above, exactly as before) — this only decides which
// of the ALREADY-authorized $view['shipments'] to scroll to. A hint that
// doesn't match any real shipment under THIS token (foreign, stale, or
// simply absent) is silently ignored, never an error, never exposes
// anything the token didn't already grant.
$focusShipmentId = null;
if ($view !== null && isset($_GET['shipment'])) {
    $candidate = (int) $_GET['shipment'];
    foreach ($view['shipments'] as $sh) {
        if ($sh['shipmentId'] === $candidate) {
            $focusShipmentId = $candidate;
            break;
        }
    }
}

header('Content-Type: text/html; charset=utf-8');
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Konfirmasi Penerimaan Barang</title>
<link rel="stylesheet" href="/api/assets/css/receipt.css">
</head>
<body class="rc-body">
<div class="rc-shell">
  <div class="rc-header">
    <img src="/api/assets/img/amor-logo.png" alt="Amor" width="36" height="36">
    <div>
      <div class="rc-header-title">Konfirmasi Penerimaan Barang</div>
      <div class="rc-header-sub">Amor Cakes &amp; Bakery</div>
    </div>
  </div>

  <?php if ($errorMessage !== null): ?>
  <div class="rc-card rc-empty"><?= rc_esc($errorMessage) ?></div>
  <?php else: ?>
  <div id="receipt-app"></div>
  <script>
    window.RECEIPT_TOKEN = <?= json_encode($token) ?>;
    window.RECEIPT_VIEW = <?= json_encode($view, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    window.RECEIPT_FOCUS_SHIPMENT_ID = <?= json_encode($focusShipmentId) ?>;
  </script>
  <script src="/api/assets/js/receipt.js"></script>
  <?php endif; ?>
</div>
</body>
</html>
