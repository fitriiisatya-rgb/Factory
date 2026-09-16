<?php

declare(strict_types=1);

/**
 * One-time (re-run whenever the frontend source changes) extraction of the
 * legacy master data that is genuinely bundled in the frontend source —
 * NOT a runtime parser, NOT part of the deployed package. Produces
 * database/legacy/phase1-source-v1.json, a small, reviewable, git-tracked
 * artifact that the Phase 1 import tool reads instead of re-parsing a
 * 750KB HTML file on every request.
 *
 * Source of truth: amorcakes-manufacturing-v5-slate(2).html (the current
 * production frontend). Extracts exactly three things, and invents nothing:
 *   1. KATALOG_BAWAAN -> products[]
 *   2. DEFAULT_DIVISI (+ SPECIAL_FG/SPECIAL_FG_CIBADAK/SPECIAL_BOLU) -> divisions[]
 *      with is_verification and factory derived from divisiDari()/
 *      fgFactoryForDivisi() in the same file (see README section below).
 *   3. bootstrapTokoCanonicalDikenal()'s hardcoded alias group -> storeAliasGroups[]
 *      (the ONLY hardcoded store/alias data that exists in this source file
 *      — everything else about stores is runtime/Sheets data this repo has
 *      no access to; the extraction intentionally does NOT invent more).
 *
 * Usage: php dist/tools/extract-legacy-source.php
 */

$repoRoot = dirname(__DIR__, 2);
$htmlPath = $repoRoot . '/amorcakes-manufacturing-v5-slate(2).html';
$html = file_get_contents($htmlPath);
if ($html === false) {
    fwrite(STDERR, "Cannot read {$htmlPath}\n");
    exit(1);
}

// --- 1. KATALOG_BAWAAN -----------------------------------------------------
if (!preg_match('/const KATALOG_BAWAAN = (\[.*?\]);/s', $html, $m)) {
    fwrite(STDERR, "KATALOG_BAWAAN not found in source\n");
    exit(1);
}
$catalog = json_decode($m[1], true);
if ($catalog === null) {
    fwrite(STDERR, "KATALOG_BAWAAN JSON decode failed: " . json_last_error_msg() . "\n");
    exit(1);
}

// --- 2. Divisions — DEFAULT_DIVISI + factory/is_verification derivation ---
// divisiDari(): "if(factory==='cibadak') return SPECIAL_BOLU" -> Bolu is Cibadak-only.
// fgFactoryForDivisi(): SPECIAL_FG -> karangtengah, SPECIAL_FG_CIBADAK -> cibadak.
// divisiVerifikasi(): true only for SPECIAL_FG / SPECIAL_FG_CIBADAK.
// Every other division in DEFAULT_DIVISI is Karangtengah-only (Cibadak produces
// nothing but Bolu — confirmed by divisiDari() having no other factory branch).
$divisions = [
    ['name' => 'Roti & Bollen',                    'factory' => 'Karangtengah', 'is_verification' => false],
    ['name' => 'Basic',                             'factory' => 'Karangtengah', 'is_verification' => false],
    ['name' => 'Donat/Mochi/AKB',                   'factory' => 'Karangtengah', 'is_verification' => false],
    ['name' => 'Pastry',                            'factory' => 'Karangtengah', 'is_verification' => false],
    ['name' => 'Cookies',                           'factory' => 'Karangtengah', 'is_verification' => false],
    ['name' => 'Finishgood & Packing',              'factory' => 'Karangtengah', 'is_verification' => true],
    ['name' => 'Bolu',                              'factory' => 'Cibadak',      'is_verification' => false],
    ['name' => 'Finishgood & Packing (Cibadak)',    'factory' => 'Cibadak',      'is_verification' => true],
];
// Sanity-check these 8 names against the actual source constant, so this file
// fails loudly instead of silently drifting if the frontend ever changes them.
if (!preg_match('/const DEFAULT_DIVISI = (\[[^\]]*\]);/', $html, $dm)) {
    fwrite(STDERR, "DEFAULT_DIVISI not found in source\n");
    exit(1);
}
$rawDivisiExpr = $dm[1];
foreach (['Roti & Bollen', 'Basic', 'Donat/Mochi/AKB', 'Pastry', 'Cookies'] as $expectName) {
    if (strpos($rawDivisiExpr, $expectName) === false) {
        fwrite(STDERR, "DEFAULT_DIVISI in source no longer contains expected '{$expectName}' — re-check this extractor.\n");
        exit(1);
    }
}

// --- 3. Store alias groups — bootstrapTokoCanonicalDikenal() --------------
if (!preg_match('/function bootstrapTokoCanonicalDikenal\(\)\{.*?const grup = (\[.*?\]);/s', $html, $sm)) {
    fwrite(STDERR, "bootstrapTokoCanonicalDikenal()'s grup array not found in source\n");
    exit(1);
}
// This is a JS object literal (unquoted keys), not strict JSON — quote the keys.
$jsLiteral = $sm[1];
$jsonish = preg_replace('/([{,]\s*)([A-Za-z_][A-Za-z0-9_]*)\s*:/', '$1"$2":', $jsLiteral);
$storeAliasGroups = json_decode($jsonish, true);
if ($storeAliasGroups === null) {
    fwrite(STDERR, "storeAliasGroups JSON decode failed: " . json_last_error_msg() . "\nRaw: {$jsonish}\n");
    exit(1);
}

$out = [
    'extractedAt' => gmdate('c'),
    'sourceFile' => basename($htmlPath),
    'sourceNote' => 'products[] = KATALOG_BAWAAN verbatim. divisions[] = DEFAULT_DIVISI with factory/'
        . 'is_verification derived from divisiDari()/fgFactoryForDivisi()/divisiVerifikasi() in the same '
        . 'source file (see extractor comments). storeAliasGroups[] = the ONLY hardcoded store/alias data '
        . 'in this source (bootstrapTokoCanonicalDikenal) — this repo has no access to the live Sheets-backed '
        . 'store list, so nothing else is invented here.',
    'divisions' => $divisions,
    'products' => $catalog,
    'storeAliasGroups' => $storeAliasGroups,
];

$outPath = $repoRoot . '/database/legacy/phase1-source-v1.json';
file_put_contents($outPath, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");

fwrite(STDOUT, "Wrote {$outPath}\n");
fwrite(STDOUT, "  divisions: " . count($divisions) . "\n");
fwrite(STDOUT, "  products: " . count($catalog) . "\n");
fwrite(STDOUT, "  storeAliasGroups: " . count($storeAliasGroups) . "\n");
