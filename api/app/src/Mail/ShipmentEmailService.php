<?php

declare(strict_types=1);

namespace Amor\Api\Mail;

use Amor\Api\ApiException;
use Amor\Api\Audit;
use Amor\Api\Config;
use Amor\Api\Database;
use Amor\Api\Dispatch\DispatchService;
use Amor\Api\Dispatch\ReceiptService;
use Amor\Api\Repositories\StoreRepository;
use PDO;

/**
 * Automatic Bakery Email / Digital Surat Jalan / Admin Resend (Phase 5.5
 * finalization). Two entry points, used identically for the very first
 * automatic send and every later Admin resend — never a second code path:
 *
 *   - createOutboxForShipment() — called INSIDE the SAME DB transaction as
 *     a real departure (Dispatch\DepartureService), right after
 *     ShipmentService::ship() succeeds. Creates exactly ONE
 *     shipment_email_delivery row (status pending/no_email depending on
 *     whether the store has an email on file) and returns its id. Does
 *     NOT touch the network — inserting one row can never fail the way an
 *     SMTP handshake can, so it is safe to run inside the same
 *     transaction as the stock-deduction write it's paired with.
 *
 *   - attemptSend() — called AFTER that transaction has committed (see
 *     Controllers\DispatchController::departures()'s own comment for
 *     exactly where), or later by an Admin resend
 *     (Controllers\ShipmentEmailController::resend()). Re-resolves the
 *     store's CURRENT email fresh (never trusts the row's stale
 *     recipient_email — a corrected Store email must be used on the next
 *     attempt), rebuilds the message by reusing
 *     Dispatch\DispatchService::shipmentDetail() (never a duplicated
 *     shipment/driver/item query), attempts a real send, and records the
 *     outcome. THE core guarantee this whole file exists to provide:
 *     nothing in this method can roll back a shipment — by the time it
 *     ever runs, the shipment's own transaction is long since committed.
 *
 * No background worker (Part O) — each attempt is synchronous, blocking
 * the HTTP response by however long the SMTP round-trip takes (typically
 * well under a second, capped by SmtpMailTransport's own connect/read
 * timeout so a hung mail server can't hang the request indefinitely).
 * Documented, accepted cost on shared hosting with no queue/cron
 * infrastructure — see the README's own "Risiko & Catatan".
 */
final class ShipmentEmailService
{
    private ShipmentEmailRepository $repo;
    private StoreRepository $storeRepo;

    public function __construct()
    {
        $this->repo = new ShipmentEmailRepository();
        $this->storeRepo = new StoreRepository();
    }

    /** @return array{outboxId:int,hasEmail:bool} */
    public function createOutboxForShipment(PDO $pdo, int $shipmentId, int $storeId): array
    {
        $store = $this->storeRepo->findById($pdo, $storeId);
        $storeName = $store['canonical_name'] ?? '-';
        $email = $store['email'] ?? null;
        $email = ($email !== null && trim($email) !== '') ? trim($email) : null;

        $subject = self::buildSubject($shipmentId, $storeName);
        $status = $email !== null ? 'pending' : 'no_email';

        $outboxId = $this->repo->insert($pdo, $shipmentId, $storeId, $email, $subject, $status);

        return ['outboxId' => $outboxId, 'hasEmail' => $email !== null];
    }

    /**
     * @return array{status:string,error:?string}
     */
    public function attemptSend(int $outboxId, ?int $triggeredBy, ?string $requestId): array
    {
        $pdo = Database::pdo();
        $outbox = $this->repo->findById($pdo, $outboxId);
        if ($outbox === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Data pengiriman email tidak ditemukan');
        }
        $shipmentId = (int) $outbox['shipment_id'];

        $store = $this->storeRepo->findById($pdo, (int) $outbox['store_id']);
        $recipient = $store['email'] ?? null;
        $recipient = ($recipient !== null && trim($recipient) !== '') ? trim($recipient) : null;

        if (!self::mailEnabled()) {
            return $this->finish($outboxId, 'failed', $recipient, 'Sistem email belum dikonfigurasi di server', $triggeredBy, $requestId, attempted: false);
        }
        if ($recipient === null) {
            return $this->finish($outboxId, 'no_email', null, 'Email toko belum diisi', $triggeredBy, $requestId, attempted: false);
        }

        // Reuses the EXISTING shipment detail query (Dispatch\DispatchService)
        // — never a second, duplicated shipment/items/driver lookup.
        // requestingUserId is irrelevant here (isAdmin=true bypasses the
        // shipped_by-only ownership check this method also serves the
        // Driver's own detail page with).
        $detail = (new DispatchService($pdo))->shipmentDetail($shipmentId, $triggeredBy ?? 0, true);
        $token = (new ReceiptService($pdo))->getReceiptToken((int) $detail['doId']);
        $message = $this->buildMessage($detail, $store, $token, $recipient);

        $result = MailTransportFactory::create()->send($message);

        return $this->finish(
            $outboxId,
            $result->success ? 'sent' : 'failed',
            $recipient,
            $result->error,
            $triggeredBy,
            $requestId,
            attempted: true,
        );
    }

    /** @return array{status:string,error:?string} */
    private function finish(int $outboxId, string $status, ?string $recipient, ?string $error, ?int $triggeredBy, ?string $requestId, bool $attempted): array
    {
        Database::transaction(function (PDO $pdo) use ($outboxId, $status, $recipient, $error, $triggeredBy, $requestId, $attempted) {
            if ($attempted) {
                $this->repo->recordAttempt($pdo, $outboxId, $status, $recipient, $error, $status === 'sent');
            } else {
                $this->repo->recordSkipped($pdo, $outboxId, $status, $recipient, $error);
            }
            $action = $status === 'sent' ? 'shipment.email.sent' : ($status === 'no_email' ? 'shipment.email.skipped' : 'shipment.email.failed');
            Audit::write(
                $pdo, $requestId, $triggeredBy, $action, 'shipment_email_delivery', (string) $outboxId,
                $status === 'sent' ? 'ok' : 'error', null, null, ['recipient' => $recipient, 'error' => $error]
            );
        });

        return ['status' => $status, 'error' => $error];
    }

    /**
     * POST /api/admin/shipments/{shipmentId}/email/resend — the CONTROLLER
     * enforces ADMIN-only (Auth::requireRole, same convention as every
     * other admin mutation in this app — see ReceiptController::
     * adminVerify() for the same split). Reuses the SAME outbox row (never
     * creates a second one), re-resolves the store's CURRENT email, and
     * never touches shipment/shipment_item/stock_ledger/shipment_receipt/
     * delivery_order in any way (task's own explicit resend constraints).
     */
    public function resend(int $shipmentId, int $adminUserId, ?string $requestId): array
    {
        $pdo = Database::pdo();
        $outbox = $this->repo->findByShipmentId($pdo, $shipmentId);
        if ($outbox === null) {
            throw new ApiException(404, 'NOT_FOUND', 'Belum ada catatan email untuk pengiriman ini');
        }

        return $this->attemptSend((int) $outbox['shipment_email_delivery_id'], $adminUserId, $requestId);
    }

    private function buildMessage(array $detail, array $store, string $token, string $recipientEmail): MailMessage
    {
        $baseUrl = rtrim((string) Config::get('APP_BASE_URL', 'https://factory.amorgroup.id'), '/');
        // Same path convention as the existing DO receipt QR (see
        // Ui/print-template.php's ui_do_receipt_qr_svg()) — the public
        // Store Receipt portal lives at api/_receive/, never a bare
        // /_receive/ at the docroot.
        $link = $baseUrl . '/api/_receive/?token=' . urlencode($token) . '&shipment=' . (int) $detail['shipmentId'];

        $storeName = (string) ($store['canonical_name'] ?? $detail['storeName'] ?? '-');
        $subject = self::buildSubject((int) $detail['shipmentId'], $storeName);

        $itemRows = '';
        foreach ($detail['items'] as $it) {
            $itemRows .= '<tr><td style="padding:4px 8px;border-bottom:1px solid #eee;">' . htmlspecialchars((string) $it['productName'])
                . '</td><td style="padding:4px 8px;border-bottom:1px solid #eee;text-align:right;">' . self::fmtQty((float) $it['qty']) . '</td></tr>';
        }

        $html = '<div style="font-family:Arial,Helvetica,sans-serif;max-width:520px;margin:0 auto;color:#222;">'
            . '<h2 style="margin-bottom:0;">Amor Cakes &amp; Bakery</h2>'
            . '<p style="margin-top:4px;color:#666;">Pengiriman Barang</p>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . '<tr><td style="padding:4px 8px;color:#666;">Bakery</td><td style="padding:4px 8px;font-weight:bold;">' . htmlspecialchars($storeName) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">No. Shipment</td><td style="padding:4px 8px;">SHP-' . (int) $detail['shipmentId'] . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">No. DO</td><td style="padding:4px 8px;">' . htmlspecialchars((string) ($detail['docNo'] ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Driver</td><td style="padding:4px 8px;">' . htmlspecialchars((string) ($detail['driverName'] ?? '-')) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Grup</td><td style="padding:4px 8px;">' . htmlspecialchars((string) $detail['shipmentGroup']) . '</td></tr>'
            . '<tr><td style="padding:4px 8px;color:#666;">Waktu Berangkat</td><td style="padding:4px 8px;">' . htmlspecialchars((string) ($detail['shippedAt'] ?? '-')) . '</td></tr>'
            . '</table>'
            . '<table style="width:100%;border-collapse:collapse;margin:16px 0;font-size:14px;">'
            . '<thead><tr><th style="text-align:left;padding:4px 8px;border-bottom:2px solid #333;">Produk</th><th style="text-align:right;padding:4px 8px;border-bottom:2px solid #333;">Qty</th></tr></thead>'
            . '<tbody>' . $itemRows . '</tbody></table>'
            . '<p style="font-size:14px;">Total: <strong>' . (int) $detail['summary']['productCount'] . ' produk &middot; ' . self::fmtQty((float) $detail['summary']['totalQty']) . ' pcs</strong></p>'
            . '<p style="font-size:14px;">Barang telah diberangkatkan. Silakan melakukan pengecekan dan konfirmasi penerimaan ketika bakery buka.</p>'
            . '<p style="margin:24px 0;"><a href="' . htmlspecialchars($link) . '" style="background:#7a3b2e;color:#fff;padding:12px 20px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block;">LIHAT SURAT JALAN &amp; KONFIRMASI PENERIMAAN</a></p>'
            . '<p style="font-size:12px;color:#999;">Jika tombol di atas tidak berfungsi, salin tautan berikut: ' . htmlspecialchars($link) . '</p>'
            . '</div>';

        return new MailMessage(
            $recipientEmail,
            $storeName,
            (string) Config::get('MAIL_FROM_ADDRESS', 'factory@amorgroup.id'),
            (string) Config::get('MAIL_FROM_NAME', 'Amor Factory System'),
            $subject,
            $html,
        );
    }

    private static function buildSubject(int $shipmentId, string $storeName): string
    {
        return '[Amor Factory] Pengiriman SHP-' . $shipmentId . ' — ' . $storeName;
    }

    private static function fmtQty(float $n): string
    {
        $s = number_format($n, 2, ',', '.');
        $s = rtrim($s, '0');
        $s = rtrim($s, ',');
        return $s;
    }

    private static function mailEnabled(): bool
    {
        $v = Config::get('MAIL_ENABLED', false);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }
}
