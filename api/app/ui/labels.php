<?php

declare(strict_types=1);

/**
 * Central Indonesian label maps + status-color system for the redesigned
 * UI. Business logic never changes what is STORED (draft/submitted/
 * reopened/shipped/... stay exactly as Phase 1-5 wrote them) — this file
 * only maps those stored values, or the presentation-only
 * displayStatusCode/fgStatusCode/packingStatusCode/itemStatusCode fields
 * Phase 3-5's own services already compute, to a label + badge color for
 * display. No raw enum value is ever printed directly in a page.
 */

/** Document-level lifecycle status shared by production_run/fg_batch (draft/submitted/reopened). */
function ui_doc_status_label(string $status): string
{
    return [
        'not_started' => 'Belum Dimulai',
        'draft' => 'Draft',
        'submitted' => 'Sudah Disubmit',
        'reopened' => 'Dibuka Kembali',
        'verified_fg' => 'Terverifikasi FG',
    ][$status] ?? ucfirst($status);
}

/** delivery_order.status. */
function ui_do_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'preprinted' => 'Sudah Preprint',
        'ready' => 'Siap Diproses',
        'shipped' => 'Terkirim Penuh',
        'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/** shipment.status. */
function ui_shipment_status_label(string $status): string
{
    return [
        'active' => 'Aktif',
        'void' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/** shipment_receipt.status (or the synthetic 'belum_dikonfirmasi' when no receipt row exists yet). */
function ui_receipt_status_label(string $status): string
{
    return [
        'belum_dikonfirmasi' => 'Belum Dikonfirmasi',
        'confirmed_ok' => 'Diterima Sesuai',
        'confirmed_discrepancy' => 'Ada Selisih',
        'verified' => 'Diverifikasi Admin',
    ][$status] ?? ucfirst($status);
}

/** shipment_email_delivery.status (Part I/K/L) — a shipment with no outbox row yet (should not normally happen — see Mail\ShipmentEmailService) also reads as "Belum Dikirim". */
function ui_email_status_label(?string $status): string
{
    return [
        'pending' => 'Belum Dikirim',
        'sent' => 'Terkirim',
        'failed' => 'Gagal',
        'no_email' => 'Email Toko Belum Diisi',
    ][$status ?? ''] ?? 'Belum Dikirim';
}

/** special_order.status (migration 0010 — Pesanan Khusus Toko / Pesanan Non-Toko). */
function ui_special_order_status_label(string $status): string
{
    return [
        'draft' => 'Draft',
        'confirmed' => 'Dikonfirmasi',
        'sent_to_production' => 'Dikirim ke Produksi',
        'in_production' => 'Sedang Diproduksi',
        'ready' => 'Siap',
        'completed' => 'Selesai',
        'cancelled' => 'Dibatalkan',
    ][$status] ?? ucfirst($status);
}

/**
 * Maps an already-Indonesian label (from any *StatusLabel field the
 * services compute, or from the maps above) to one of the 5 badge colors
 * in the task's own status design system. Unknown labels fall back to
 * neutral rather than erroring, so a future label never breaks the page.
 */
function ui_status_color(string $label): string
{
    static $neutral = ['Belum Diproduksi', 'Belum Diverifikasi', 'Belum Dikirim', 'Belum Dipacking', 'Belum Dimulai', 'Belum Dikonfirmasi', 'Perlu Produksi'];
    static $primary = ['Draft', 'Siap Diproses', 'Belum Diverifikasi FG', 'Sudah Dialokasikan'];
    static $warning = [
        'Dibuka Kembali', 'Sebagian Terverifikasi', 'Belum Sesuai Target', 'Sebagian Dikirim',
        'Sudah Preprint', 'Selisih', 'Sebagian Dipacking', 'Ada Selisih', 'Menunggu Konfirmasi',
        'Email Toko Belum Diisi', 'Dikirim ke Produksi', 'Sedang Diproduksi', 'Belum Selesai',
        'Sebagian FG / Perlu Produksi',
    ];
    static $success = [
        'Sudah Disubmit', 'Sesuai Target', 'Sesuai Produksi', 'Selesai Dipacking', 'Terkirim Penuh',
        'Terverifikasi FG', 'Aktif', 'Terkirim', 'Diterima Sesuai', 'Diverifikasi Admin', 'Siap', 'Selesai',
        'Bisa Dipenuhi dari FG', 'Tidak Perlu Produksi', 'Siap ke DO',
    ];
    static $danger = ['Overproduction', 'Dibatalkan', 'Error', 'Melebihi Produksi', 'Packing Melebihi FG', 'Nonaktif', 'Gagal'];

    if (in_array($label, $danger, true)) {
        return 'danger';
    }
    if (in_array($label, $success, true)) {
        return 'success';
    }
    if (in_array($label, $warning, true)) {
        return 'warning';
    }
    if (in_array($label, $primary, true)) {
        return 'primary';
    }
    if (in_array($label, $neutral, true)) {
        return 'neutral';
    }
    return 'neutral';
}

/** Renders a <span class="badge badge-*"> for any label — the ONLY place a status badge's HTML is built. */
function ui_badge(string $label): string
{
    $color = ui_status_color($label);
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}

/**
 * Task per Divisi's demand-SOURCE badge (distinct axis from a status
 * badge — a task's source never changes, unlike its status) — matches
 * the approved mockup's legend colors exactly: PO Reguler=blue,
 * Pesanan Khusus=gold, Pesanan Non-Toko=green, Replacement Reject=red.
 */
function ui_task_source_badge(string $source, string $label): string
{
    $color = match ($source) {
        'po_reguler' => 'primary',
        'pesanan_khusus' => 'warning',
        'pesanan_non_toko' => 'success',
        'replacement_reject' => 'danger',
        default => 'neutral',
    };
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}

/**
 * Downstream normalized source badge (Amor\Api\SpecialOrder\
 * NormalizedSourceType) — a DIFFERENT axis/vocabulary from
 * ui_task_source_badge() above (that one is Task per Divisi's PRODUCTION
 * demand source; this one is the shipment/DO/receipt's DOWNSTREAM
 * fulfillment source — task's own Final Pre-Live Rework Section A/H).
 */
function ui_normalized_source_badge(string $normalizedType, string $label): string
{
    $color = match ($normalizedType) {
        'REGULAR_STORE_PO' => 'primary',
        'SPECIAL_STORE_ORDER' => 'warning',
        'CS_ORDER', 'SALES_ORDER' => 'success',
        'DIRECT_CUSTOMER', 'GENERAL_ORDER' => 'neutral',
        'REPLACEMENT_REJECT' => 'danger',
        default => 'neutral',
    };
    return '<span class="badge badge-' . $color . '">' . ui_esc($label) . '</span>';
}
