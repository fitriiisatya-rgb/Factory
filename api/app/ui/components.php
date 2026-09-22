<?php

declare(strict_types=1);

/**
 * Small reusable render helpers shared by every /api/_ui-preview/ page.
 * Plain functions that echo/return HTML strings — no templating engine,
 * consistent with the project's existing PHP-rendered-page approach.
 */

/**
 * @param array{label:string,value:string,icon:string,color?:string,progressPct?:int,foot?:string} $opts
 */
/**
 * $opts['detail'] (real-UAT typography hotfix): true for an
 * information-detail card (a single record's Status/Tujuan/Waktu/Nama —
 * prose, not an aggregate metric) rather than a genuine Dashboard-style
 * KPI (a count/total/percentage meant to read as a headline number).
 * Every OTHER ui_kpi_card() caller (Dashboard, DO list/detail, FG &
 * Packing, Pengiriman, Pesanan Toko, Produksi) uses real aggregate
 * numbers and is intentionally left at the full KPI size — this flag
 * only adds the "kpi-card--detail" modifier class, so those pages are
 * completely unaffected.
 */
function ui_kpi_card(array $opts): string
{
    $color = $opts['color'] ?? 'primary';
    $cardClass = 'kpi-card' . (!empty($opts['detail']) ? ' kpi-card--detail' : '');
    $html = '<div class="' . $cardClass . '">';
    $html .= '<div class="kpi-top"><span class="kpi-icon ' . $color . '">' . ui_icon($opts['icon']) . '</span></div>';
    $html .= '<div class="kpi-label">' . ui_esc($opts['label']) . '</div>';
    $html .= '<div class="kpi-value">' . ui_esc($opts['value']) . '</div>';
    if (isset($opts['progressPct'])) {
        $html .= '<div class="kpi-progress"><span style="width:' . (int) $opts['progressPct'] . '%"></span></div>';
    }
    if (isset($opts['foot'])) {
        $html .= '<div class="kpi-foot"><span>' . $opts['foot'] . '</span>'
            . (isset($opts['progressPct']) ? '<span>' . (int) $opts['progressPct'] . '%</span>' : '') . '</div>';
    }
    $html .= '</div>';
    return $html;
}

function ui_empty_state(string $title, string $body = ''): string
{
    return '<div class="empty-state"><div class="empty-icon">&#128203;</div>'
        . '<div class="empty-title">' . ui_esc($title) . '</div>'
        . ($body !== '' ? '<div>' . ui_esc($body) . '</div>' : '')
        . '</div>';
}

/** Minimal inline line-icon set — no external icon font/library. */
function ui_icon(string $name): string
{
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
        'cart' => '<circle cx="9" cy="20" r="1.5"/><circle cx="18" cy="20" r="1.5"/><path d="M2 3h2l2.4 12.2a2 2 0 0 0 2 1.6h8.7a2 2 0 0 0 2-1.6L21 7H6"/>',
        'factory' => '<path d="M3 21V9l6 3V9l6 3V5l6 4v12z"/>',
        'box' => '<path d="M21 8L12 3 3 8v8l9 5 9-5z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
        'file' => '<path d="M6 2h9l5 5v15H6z"/><path d="M15 2v5h5"/>',
        'truck' => '<path d="M1 8h13v9H1z"/><path d="M14 11h4l4 3v3h-8z"/><circle cx="6" cy="19" r="1.7"/><circle cx="18" cy="19" r="1.7"/>',
        'chart' => '<path d="M4 19V9"/><path d="M11 19V4"/><path d="M18 19v-7"/>',
        'database' => '<ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.6 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.6-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9V9c.2.5.7 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
        'search' => '<circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/>',
        'bell' => '<path d="M18 8a6 6 0 1 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/>',
        'moon' => '<path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/>',
        'calendar' => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'building' => '<rect x="4" y="2" width="16" height="20"/><path d="M9 22v-4h6v4M9 6h1M14 6h1M9 10h1M14 10h1M9 14h1M14 14h1"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'heart' => '<path d="M12 21s-7-4.5-9.5-9A5.5 5.5 0 0 1 12 6a5.5 5.5 0 0 1 9.5 6c-2.5 4.5-9.5 9-9.5 9z"/>',
        'bolt' => '<path d="M13 2 3 14h7l-1 8 10-12h-7z"/>',
        'mail' => '<rect x="2" y="4" width="20" height="16" rx="2"/><path d="m22 6-10 7L2 6"/>',
    ];
    $body = $paths[$name] ?? $paths['dashboard'];
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $body . '</svg>';
}

/**
 * A lightweight CSS bar chart — two bars (target vs actual) per column —
 * built for "Tren Produksi vs Target" without pulling in a JS chart
 * library (the task explicitly prefers this for a first iteration).
 * @param array<int,array{label:string,target:float,actual:float}> $rows
 */
function ui_bar_chart(array $rows): string
{
    $max = 1.0;
    foreach ($rows as $r) {
        $max = max($max, $r['target'], $r['actual']);
    }
    $html = '<div class="bar-chart">';
    foreach ($rows as $r) {
        $tH = (int) round(($r['target'] / $max) * 100);
        $aH = (int) round(($r['actual'] / $max) * 100);
        $html .= '<div class="bar-chart-col">'
            . '<div class="bar-chart-bars">'
            . '<div class="bar-chart-bar target" style="height:' . $tH . '%" title="Target: ' . ui_fmt_num($r['target']) . '"></div>'
            . '<div class="bar-chart-bar actual" style="height:' . $aH . '%" title="Aktual: ' . ui_fmt_num($r['actual']) . '"></div>'
            . '</div>'
            . '<div class="bar-chart-label">' . ui_esc($r['label']) . '</div>'
            . '</div>';
    }
    $html .= '</div>';
    $html .= '<div class="chart-legend">'
        . '<span class="chart-legend-item"><span class="chart-legend-swatch" style="background:var(--surface-2);border:1px solid var(--border)"></span>Target PO</span>'
        . '<span class="chart-legend-item"><span class="chart-legend-swatch" style="background:var(--primary)"></span>Produksi Aktual</span>'
        . '</div>';
    return $html;
}

/**
 * A lightweight CSS conic-gradient donut chart — no JS chart library.
 * @param array<int,array{label:string,value:float,color:string}> $slices color = a CSS color value
 */
function ui_donut_chart(array $slices, string $centerLabel, string $centerValue): string
{
    $total = 0.0;
    foreach ($slices as $s) {
        $total += $s['value'];
    }
    $total = max($total, 0.0001);

    $stops = [];
    $acc = 0.0;
    foreach ($slices as $s) {
        $start = ($acc / $total) * 360;
        $acc += $s['value'];
        $end = ($acc / $total) * 360;
        $stops[] = $s['color'] . ' ' . round($start, 1) . 'deg ' . round($end, 1) . 'deg';
    }
    $gradient = $stops === [] ? 'var(--surface-2) 0deg 360deg' : implode(', ', $stops);

    $html = '<div class="donut-wrap">';
    $html .= '<div class="donut" style="background:conic-gradient(' . $gradient . ')">'
        . '<div class="donut-center"><b>' . ui_esc($centerValue) . '</b><span>' . ui_esc($centerLabel) . '</span></div></div>';
    $html .= '<div class="donut-legend">';
    foreach ($slices as $s) {
        $pct = $total > 0.0001 ? round(($s['value'] / $total) * 100) : 0;
        $html .= '<div class="donut-legend-item"><span class="donut-legend-swatch" style="background:' . $s['color'] . '"></span>'
            . ui_esc($s['label']) . '<span class="donut-legend-pct">' . $pct . '%</span></div>';
    }
    $html .= '</div></div>';
    return $html;
}
