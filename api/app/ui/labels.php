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

/**
 * Maps an already-Indonesian label (from any *StatusLabel field the
 * services compute, or from the maps above) to one of the 5 badge colors
 * in the task's own status design system. Unknown labels fall back to
 * neutral rather than erroring, so a future label never breaks the page.
 */
function ui_status_color(string $label): string
{
    static $neutral = ['Belum Diproduksi', 'Belum Diverifikasi', 'Belum Dikirim', 'Belum Dipacking', 'Belum Dimulai', 'Belum Dikonfirmasi'];
    static $primary = ['Draft', 'Siap Diproses', 'Belum Diverifikasi FG'];
    static $warning = [
        'Dibuka Kembali', 'Sebagian Terverifikasi', 'Belum Sesuai Target', 'Sebagian Dikirim',
        'Sudah Preprint', 'Selisih', 'Sebagian Dipacking', 'Ada Selisih', 'Menunggu Konfirmasi',
    ];
    static $success = [
        'Sudah Disubmit', 'Sesuai Target', 'Sesuai Produksi', 'Selesai Dipacking', 'Terkirim Penuh',
        'Terverifikasi FG', 'Aktif', 'Terkirim', 'Diterima Sesuai', 'Diverifikasi Admin',
    ];
    static $danger = ['Overproduction', 'Dibatalkan', 'Error', 'Melebihi Produksi', 'Packing Melebihi FG'];

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
