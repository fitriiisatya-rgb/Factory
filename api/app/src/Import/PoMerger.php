<?php

declare(strict_types=1);

namespace Amor\Api\Import;

/**
 * Pure revision-snapshot merge logic — ported from the audited legacy
 * frontend's poMergeDenganExisting() (amorcakes-manufacturing-v5-slate(2).html),
 * with the identity key upgraded from raw code+name text to the resolved
 * (product_id, store_id) pair, per this phase's explicit requirement that
 * no business logic depend on raw strings after resolution. Takes no PDO
 * connection and does no I/O — a plain function of (existing lines, new
 * lines, upload type) -> resulting lines, so it can be tested in complete
 * isolation from the database.
 *
 * Audited business rule (do not change without understanding the
 * downstream impact — every future Production/FG/Packing/Stock/DO module
 * computes its target from this merged state):
 *   - INITIAL upload: establishes po_awal for every (product,store) line in
 *     the file. po_revisi is ALWAYS forced to 0, regardless of whatever the
 *     file's own PO Revisi/PB columns contain — a real file can carry all
 *     three blocks in one sheet even when the operator is uploading it as
 *     "PO Awal" (confirmed against a real cPanel UAT upload that exposed
 *     this exact bug — see mergeInitial()'s own comment for the full
 *     story). Upload TYPE decides what gets committed, never file content.
 *   - REVISION upload: for a (product,store) pair that already has a
 *     stored line, po_awal is LOCKED (never taken from the new file) and
 *     po_revisi is REPLACED with the new file's value as a full snapshot —
 *     never added to the previous po_revisi. Re-uploading the exact same
 *     revision file is therefore idempotent (target does not grow), and a
 *     revision corrected DOWN is reflected immediately, including back to
 *     0. A (product,store) pair that appears in the revision file but has
 *     no existing stored line is a brand-new demand line: po_awal is
 *     forced to 0 (a revision upload never establishes new baseline
 *     demand — only an initial upload does), po_revisi is the file's
 *     value. A stored line NOT mentioned at all in the new revision file
 *     is preserved completely unchanged (never deleted, never zeroed).
 */
final class PoMerger
{
    /**
     * @param array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}> $existingLines
     * @param array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}> $newLines
     * @param string $uploadType 'initial' | 'revision'
     * @return array{lines:array<int,array{productId:int,storeId:int,poAwal:float,poRevisi:float,kategori:?string}>,summary:array}
     */
    public static function merge(array $existingLines, array $newLines, string $uploadType): array
    {
        if ($uploadType === 'initial') {
            return self::mergeInitial($newLines);
        }
        return self::mergeRevision($existingLines, $newLines);
    }

    private static function key(array $line): string
    {
        return $line['productId'] . '|' . $line['storeId'];
    }

    /**
     * po_revisi is ALWAYS forced to 0 here, regardless of what the file's
     * own PO Revisi columns contain. Real Karangtengah/Bolu files commonly
     * carry both PO Awal AND PO Revisi/PB blocks in the very same sheet
     * (audited against a real cPanel UAT upload — a "PO AWAL" upload is
     * not a guarantee the revisi columns are blank), so this can never be
     * left as "normally 0 for a genuine initial file" — that assumption
     * previously let an initial upload's own revisi column leak straight
     * into the committed po_revisi and inflate the preview target by
     * exactly the file's raw revisi total. Upload TYPE, not file content,
     * decides what an initial upload commits (task rule: "PO AWAL MODE:
     * commit ONLY PO Awal baseline... ignore PO Revisi for initial
     * target"). The raw revisi figure is still available for informational
     * preview display via PoImporter's own row-loop totals (totalPoRevisi)
     * — computed before this merge step, so this zeroing never hides it
     * from the operator, only from what actually gets written.
     *
     * @return array{lines:array,summary:array}
     */
    private static function mergeInitial(array $newLines): array
    {
        $lines = array_map(static fn (array $l): array => [
            'productId' => $l['productId'],
            'storeId' => $l['storeId'],
            'poAwal' => $l['poAwal'],
            'poRevisi' => 0.0,
            'kategori' => $l['kategori'],
        ], $newLines);
        $summary = ['newLines' => count($lines), 'lockedLines' => 0, 'changedLines' => 0, 'unchangedLines' => 0];
        return ['lines' => $lines, 'summary' => $summary];
    }

    /** @return array{lines:array,summary:array} */
    private static function mergeRevision(array $existingLines, array $newLines): array
    {
        $existingByKey = [];
        foreach ($existingLines as $line) {
            $existingByKey[self::key($line)] = $line;
        }

        $mentioned = [];
        $result = [];
        $summary = ['newLines' => 0, 'lockedLines' => 0, 'changedLines' => 0, 'unchangedLines' => 0];

        foreach ($newLines as $baru) {
            $k = self::key($baru);
            $mentioned[$k] = true;
            $lama = $existingByKey[$k] ?? null;

            if ($lama === null) {
                // Brand-new (product,store) pair introduced by this revision —
                // never establishes baseline demand on its own (task item G).
                $result[$k] = [
                    'productId' => $baru['productId'],
                    'storeId' => $baru['storeId'],
                    'poAwal' => 0.0,
                    'poRevisi' => $baru['poRevisi'],
                    'kategori' => $baru['kategori'],
                ];
                $summary['newLines']++;
                continue;
            }

            $result[$k] = [
                'productId' => $baru['productId'],
                'storeId' => $baru['storeId'],
                'poAwal' => $lama['poAwal'], // LOCKED — never taken from the new file
                'poRevisi' => $baru['poRevisi'], // full snapshot, never additive
                'kategori' => $baru['kategori'] ?? $lama['kategori'],
            ];
            $summary['lockedLines']++;
            if ((float) $lama['poRevisi'] !== (float) $baru['poRevisi']) {
                $summary['changedLines']++;
            } else {
                $summary['unchangedLines']++;
            }
        }

        // Existing lines not mentioned at all in this revision file are
        // preserved completely unchanged — never deleted, never zeroed.
        foreach ($existingLines as $line) {
            $k = self::key($line);
            if (!isset($mentioned[$k])) {
                $result[$k] = $line;
            }
        }

        return ['lines' => array_values($result), 'summary' => $summary];
    }
}
