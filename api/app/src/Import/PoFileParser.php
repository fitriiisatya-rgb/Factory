<?php

declare(strict_types=1);

namespace Amor\Api\Import;

/**
 * Ported, line-by-line audited, from the live frontend's PO parser
 * (amorcakes-manufacturing-v5-slate(2).html — parseAngka/UP/findHeaderRow/
 * findHeaderRowBolu/findTotalCols/findStoreDetailBlocks/
 * hitungDenganBreakdown/parsePOCsv/parsePOBolu/deteksiFactory/parsePOAuto).
 * Operates on the SAME shape of input (a 2D array of row-arrays, exactly
 * what SheetJS's `sheet_to_json(ws, {header:1, defval:""})` produces
 * client-side) so this is a faithful behavioral port, not a rewrite from
 * memory — see XlsxReader for the .xlsx -> 2D-array step and CsvReader
 * (in PoImporter) for the .csv one.
 *
 * Two known real layouts, auto-detected from the header row itself, never
 * from a factory dropdown (so a Bolu file can never accidentally overwrite
 * Karangtengah's PO or vice versa):
 *   Layout A (Karangtengah): NO | KATEGORI | KODE | NAMA PRODUK | ...store
 *     columns... | TOTAL | ...revision store columns... | TOTAL | ...PB
 *     store columns... | TOTAL. Store identity in this layout is the STORE
 *     CODE used as the column sub-header (e.g. "SDRM", "CKLE").
 *   Layout B (Cibadak/Bolu): Kategori | Nama Produk | Harga Satuan | ...
 *     same TOTAL PO block structure, but store identity is the full store
 *     NAME as the column sub-header, and there is no product code column
 *     at all (resolved by name only downstream in PoResolver).
 *
 * Per-store breakdown is the PRIMARY source of truth (not the TOTAL cell —
 * real files have been observed with a stale/blank TOTAL despite non-zero
 * per-store figures); a mismatch is recorded as a warning, never silently
 * discarded. PB (Penerimaan Barang) is parsed for display only — it is
 * NEVER used in target/production/FG/stock/DO/dashboard calculations
 * anywhere in this codebase, matching the audited legacy behavior exactly.
 */
final class PoFileParser
{
    /**
     * @return array{factory:string,rows:array<int,array>}
     * @throws \RuntimeException on an unrecognized layout
     */
    public static function parseAuto(array $rows): array
    {
        $factory = self::detectFactory($rows);
        if ($factory === 'karangtengah') {
            return ['factory' => $factory, 'rows' => self::parseKarangtengah($rows, $factory)];
        }
        if ($factory === 'cibadak') {
            return ['factory' => $factory, 'rows' => self::parseBolu($rows, $factory)];
        }
        throw new \RuntimeException(
            'Format file tidak dikenali — header (NO/KATEGORI/KODE/NAMA PRODUK) maupun (Kategori/Nama Produk) tidak ditemukan.'
        );
    }

    public static function detectFactory(array $rows): ?string
    {
        if (self::findHeaderRow($rows) >= 0) {
            return 'karangtengah';
        }
        if (self::findHeaderRowBolu($rows) >= 0) {
            return 'cibadak';
        }
        return null;
    }

    public static function parseAngka(mixed $v): float
    {
        if ($v === null) {
            return 0.0;
        }
        $s = trim((string) $v);
        if ($s === '') {
            return 0.0;
        }
        $s = preg_replace('/^Rp\.?\s*/i', '', $s);
        $s = preg_replace('/\s/', '', $s);
        if (preg_match('/^-?\d{1,3}(,\d{3})+(\.\d+)?$/', $s)) {
            $s = str_replace(',', '', $s); // 42,000.00 (EN export format)
        } elseif (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $s)) {
            $s = str_replace('.', '', $s); // 42.000,00 (ID format)
            $s = str_replace(',', '.', $s);
        } elseif (preg_match('/^-?\d+,\d+$/', $s)) {
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float) $s : 0.0;
    }

    public static function up(mixed $v): string
    {
        $s = $v === null ? '' : (string) $v;
        return strtoupper(trim(preg_replace('/\s+/', ' ', $s)));
    }

    private static function cell(array $row, int $idx): mixed
    {
        return $row[$idx] ?? '';
    }

    /** Layout A (Karangtengah): NO | KATEGORI | KODE | NAMA... */
    private static function findHeaderRow(array $rows): int
    {
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            if (self::up(self::cell($r, 0)) === 'NO'
                && self::up(self::cell($r, 1)) === 'KATEGORI'
                && self::up(self::cell($r, 2)) === 'KODE'
                && str_starts_with(self::up(self::cell($r, 3)), 'NAMA')
            ) {
                return $i;
            }
        }
        return -1;
    }

    /** Layout B (Cibadak/Bolu): _ | Kategori | Nama Produk | ... */
    private static function findHeaderRowBolu(array $rows): int
    {
        foreach ($rows as $i => $r) {
            if (!is_array($r)) {
                continue;
            }
            if (self::up(self::cell($r, 1)) === 'KATEGORI'
                && str_starts_with(self::up(self::cell($r, 2)), 'NAMA PRODUK')
            ) {
                return $i;
            }
        }
        return -1;
    }

    /** @return int[] 0-based column indexes whose header text is TOTAL or TOTAL PO */
    private static function findTotalCols(array $headerRow): array
    {
        $idx = [];
        foreach ($headerRow as $i => $v) {
            $t = strtoupper(trim((string) $v));
            if ($t === 'TOTAL' || $t === 'TOTAL PO') {
                $idx[] = $i;
            }
        }
        return $idx;
    }

    /**
     * Karangtengah per-store column blocks, matched by the store CODE
     * printed in the sub-header row (hIdx+1) — never by raw column
     * position, so the parser stays correct even if the file's column
     * order changes between uploads (audited from the real sample file's
     * structure — see the class header comment).
     * @return array<int,array{toko:string,colAwal:int,colRevisi:?int,colPB:?int}>
     */
    private static function findStoreDetailBlocks(array $rows, int $hIdx, int $colAwal, ?int $colRevisi, ?int $colPB): array
    {
        $sub = $rows[$hIdx + 1] ?? [];
        $bacaBlok = static function (int $mulai, int $akhir) use ($sub): array {
            $peta = [];
            for ($c = $mulai; $c < $akhir; $c++) {
                $nm = trim((string) ($sub[$c] ?? ''));
                if ($nm !== '') {
                    $peta[self::up($nm)] = ['nama' => $nm, 'col' => $c];
                }
            }
            return $peta;
        };
        $blokAwal = $bacaBlok(4, $colAwal);
        $blokRevisi = $colRevisi !== null ? $bacaBlok($colAwal + 1, $colRevisi) : [];
        $blokPBStart = ($colRevisi ?? $colAwal) + 1;
        $blokPB = $colPB !== null ? $bacaBlok($blokPBStart, $colPB) : [];

        $out = [];
        foreach ($blokAwal as $key => $b) {
            $out[] = [
                'toko' => $b['nama'],
                'colAwal' => $b['col'],
                'colRevisi' => $blokRevisi[$key]['col'] ?? null,
                'colPB' => $blokPB[$key]['col'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Breakdown-per-store is the primary source; the TOTAL cell is only a
     * cross-check. A mismatch is recorded, never silently overwritten —
     * see the class header comment.
     * @param int[] $storeCols
     * @return array{nilai:float,sumber:string,selisih:float,totalCellKosong:bool}
     */
    private static function hitungDenganBreakdown(mixed $totalRaw, array $storeCols, array $row): array
    {
        $breakdown = 0.0;
        foreach ($storeCols as $c) {
            if ($c !== null) {
                $breakdown += self::parseAngka($row[$c] ?? '');
            }
        }
        $totalRawStr = trim((string) ($totalRaw ?? ''));
        $totalCell = self::parseAngka($totalRaw);
        if ($breakdown > 0) {
            $selisih = $totalRawStr !== '' ? ($totalCell - $breakdown) : 0.0;
            return ['nilai' => $breakdown, 'sumber' => 'breakdown', 'selisih' => $selisih, 'totalCellKosong' => $totalRawStr === ''];
        }
        return ['nilai' => $totalCell, 'sumber' => 'total', 'selisih' => 0.0, 'totalCellKosong' => $totalRawStr === ''];
    }

    /** @return array<int,array> */
    private static function parseKarangtengah(array $rows, string $factory): array
    {
        $hIdx = self::findHeaderRow($rows);
        if ($hIdx < 0) {
            throw new \RuntimeException('Header (NO/KATEGORI/KODE/NAMA PRODUK) tidak ditemukan di file ini.');
        }
        $totals = self::findTotalCols($rows[$hIdx]);
        if ($totals === []) {
            throw new \RuntimeException('Kolom TOTAL tidak ditemukan di header.');
        }
        $colAwal = $totals[0];
        $colRevisi = $totals[1] ?? null;
        $colPB = $totals[2] ?? null;
        $storeBlocks = self::findStoreDetailBlocks($rows, $hIdx, $colAwal, $colRevisi, $colPB);

        $out = [];
        $rowCount = count($rows);
        for ($i = $hIdx + 2; $i < $rowCount; $i++) {
            $r = $rows[$i] ?? null;
            if (!is_array($r)) {
                continue;
            }
            $kode = trim((string) self::cell($r, 2));
            $kategori = self::up(self::cell($r, 1));
            $nama = trim(preg_replace('/\s+/', ' ', (string) self::cell($r, 3)));
            if ($kode === '' || $nama === '' || $kode === '0' || $nama === '0') {
                continue;
            }

            $kolAwalTiap = array_map(static fn ($b) => $b['colAwal'], $storeBlocks);
            $kolRevTiap = array_map(static fn ($b) => $b['colRevisi'], $storeBlocks);
            $hAwal = self::hitungDenganBreakdown($r[$colAwal] ?? '', $kolAwalTiap, $r);
            $hRevisi = $colRevisi !== null
                ? self::hitungDenganBreakdown($r[$colRevisi] ?? '', $kolRevTiap, $r)
                : ['nilai' => 0.0, 'sumber' => 'total', 'selisih' => 0.0, 'totalCellKosong' => true];
            $pb = $colPB !== null ? self::parseAngka($r[$colPB] ?? '') : 0.0;

            $stores = [];
            foreach ($storeBlocks as $b) {
                $sAwal = self::parseAngka($r[$b['colAwal']] ?? '');
                $sRevisi = $b['colRevisi'] !== null ? self::parseAngka($r[$b['colRevisi']] ?? '') : 0.0;
                if ($sAwal > 0 || $sRevisi > 0) {
                    $stores[] = ['toko' => $b['toko'], 'poAwal' => $sAwal, 'poRevisi' => $sRevisi];
                }
            }
            $sumAlokasi = (float) array_sum(array_map(static fn ($s) => $s['poAwal'] + $s['poRevisi'], $stores));

            $catatan = [];
            if ($hAwal['sumber'] === 'breakdown' && $hAwal['selisih'] !== 0.0) {
                $catatan[] = ['tipe' => 'selisih_awal', 'pesan' => sprintf(
                    "PO Awal: TOTAL tertulis %s, breakdown toko %s (selisih %s) — dipakai breakdown toko.",
                    self::fmt(self::parseAngka($r[$colAwal] ?? '')), self::fmt($hAwal['nilai']), self::fmt(abs($hAwal['selisih']))
                )];
            }
            if ($colRevisi !== null && $hRevisi['sumber'] === 'breakdown' && $hRevisi['selisih'] !== 0.0) {
                $catatan[] = ['tipe' => 'selisih_revisi', 'pesan' => sprintf(
                    "PO Revisi: TOTAL tertulis %s, breakdown toko %s (selisih %s) — dipakai breakdown toko.",
                    self::fmt(self::parseAngka($r[$colRevisi] ?? '')), self::fmt($hRevisi['nilai']), self::fmt(abs($hRevisi['selisih']))
                )];
            }
            if (($hAwal['nilai'] + $hRevisi['nilai']) > 0 && $sumAlokasi === 0.0) {
                $catatan[] = ['tipe' => 'tanpa_alokasi', 'pesan' => sprintf(
                    'Total PO %s pcs tapi tidak ada alokasi ke toko mana pun (breakdown toko kosong).',
                    self::fmt($hAwal['nilai'] + $hRevisi['nilai'])
                )];
            }

            $out[] = [
                'factory' => $factory,
                'kategori' => $kategori,
                'kode' => $kode,
                'produkAsli' => $nama,
                'poAwal' => $hAwal['nilai'],
                'poRevisi' => $hRevisi['nilai'],
                'pb' => $pb,
                'stores' => $stores,
                'catatan' => $catatan,
            ];
        }
        return $out;
    }

    /** @return array<int,array> */
    private static function parseBolu(array $rows, string $factory): array
    {
        $hIdx = self::findHeaderRowBolu($rows);
        if ($hIdx < 0) {
            throw new \RuntimeException('Header (Kategori / Nama Produk) tidak ditemukan di file ini.');
        }
        $totals = self::findTotalCols($rows[$hIdx]);
        if ($totals === []) {
            throw new \RuntimeException('Kolom "TOTAL PO" tidak ditemukan di header.');
        }
        $colAwal = $totals[0];
        $colRevisi = $totals[1] ?? null;
        $colPB = $totals[2] ?? null;
        $sub = $rows[$hIdx + 1] ?? [];

        $kolTokoAwal = [];
        for ($c = 4; $c < $colAwal; $c++) {
            $nm = trim((string) ($sub[$c] ?? ''));
            if ($nm !== '') {
                $kolTokoAwal[self::up($nm)] = ['nama' => $nm, 'col' => $c];
            }
        }
        $kolTokoRev = [];
        if ($colRevisi !== null) {
            for ($c = $colAwal + 1; $c < $colRevisi; $c++) {
                $nm = trim((string) ($sub[$c] ?? ''));
                if ($nm !== '') {
                    $kolTokoRev[self::up($nm)] = $c;
                }
            }
        }

        $out = [];
        $rowCount = count($rows);
        for ($i = $hIdx + 2; $i < $rowCount; $i++) {
            $r = $rows[$i] ?? null;
            if (!is_array($r)) {
                continue;
            }
            $kategori = self::up(self::cell($r, 1));
            $nama = trim(preg_replace('/\s+/', ' ', (string) self::cell($r, 2)));
            if ($nama === '' || $kategori === '' || $nama === '.' || self::up($nama) === 'NAMA PRODUK') {
                continue;
            }
            if (preg_match('/^(TOTAL|GRAND TOTAL|JUMLAH)/', $kategori) || preg_match('/^(TOTAL|GRAND TOTAL|JUMLAH)/', self::up($nama))) {
                continue; // recap row, not a product
            }

            $kolAwalTiap = array_map(static fn ($b) => $b['col'], $kolTokoAwal);
            $kolRevTiap = array_map(static fn ($k) => $kolTokoRev[$k] ?? null, array_keys($kolTokoAwal));
            $hAwal = self::hitungDenganBreakdown($r[$colAwal] ?? '', $kolAwalTiap, $r);
            $hRevisi = $colRevisi !== null
                ? self::hitungDenganBreakdown($r[$colRevisi] ?? '', $kolRevTiap, $r)
                : ['nilai' => 0.0, 'sumber' => 'total', 'selisih' => 0.0, 'totalCellKosong' => true];
            $pb = $colPB !== null ? self::parseAngka($r[$colPB] ?? '') : 0.0;

            $stores = [];
            foreach ($kolTokoAwal as $key => $b) {
                $sAwal = self::parseAngka($r[$b['col']] ?? '');
                $revCol = $kolTokoRev[$key] ?? null;
                $sRevisi = $revCol !== null ? self::parseAngka($r[$revCol] ?? '') : 0.0;
                if ($sAwal > 0 || $sRevisi > 0) {
                    $stores[] = ['toko' => $b['nama'], 'poAwal' => $sAwal, 'poRevisi' => $sRevisi];
                }
            }
            $sumAlokasi = (float) array_sum(array_map(static fn ($s) => $s['poAwal'] + $s['poRevisi'], $stores));

            $catatan = [];
            if ($hAwal['sumber'] === 'breakdown' && $hAwal['selisih'] !== 0.0) {
                $catatan[] = ['tipe' => 'selisih_awal', 'pesan' => sprintf(
                    "PO Awal: TOTAL PO tertulis %s, breakdown toko %s (selisih %s) — dipakai breakdown toko.",
                    self::fmt(self::parseAngka($r[$colAwal] ?? '')), self::fmt($hAwal['nilai']), self::fmt(abs($hAwal['selisih']))
                )];
            }
            if ($colRevisi !== null && $hRevisi['sumber'] === 'breakdown' && $hRevisi['selisih'] !== 0.0) {
                $catatan[] = ['tipe' => 'selisih_revisi', 'pesan' => sprintf(
                    "PO Tambahan: TOTAL PO tertulis %s, breakdown toko %s (selisih %s) — dipakai breakdown toko.",
                    self::fmt(self::parseAngka($r[$colRevisi] ?? '')), self::fmt($hRevisi['nilai']), self::fmt(abs($hRevisi['selisih']))
                )];
            }
            if (($hAwal['nilai'] + $hRevisi['nilai']) > 0 && $sumAlokasi === 0.0) {
                $catatan[] = ['tipe' => 'tanpa_alokasi', 'pesan' => sprintf(
                    'Total PO %s pcs tapi tidak ada alokasi ke toko mana pun.',
                    self::fmt($hAwal['nilai'] + $hRevisi['nilai'])
                )];
            }

            $out[] = [
                'factory' => $factory,
                'kategori' => $kategori,
                'kode' => '', // Bolu files carry no product code column — resolved by name only (see PoResolver)
                'produkAsli' => $nama,
                'poAwal' => $hAwal['nilai'],
                'poRevisi' => $hRevisi['nilai'],
                'pb' => $pb,
                'stores' => $stores,
                'catatan' => $catatan,
            ];
        }
        return $out;
    }

    private static function fmt(float $n): string
    {
        return rtrim(rtrim(number_format($n, 2, '.', ','), '0'), '.');
    }
}
