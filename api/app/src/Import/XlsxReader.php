<?php

declare(strict_types=1);

namespace Amor\Api\Import;

/**
 * Minimal, dependency-free .xlsx reader — ZipArchive + SimpleXML only (both
 * standard on cPanel/CloudLinux PHP builds), no Composer/vendor directory.
 * Deliberately NOT a general-purpose spreadsheet library: it reads exactly
 * what the PO import wizard needs — one sheet as a plain 2D array of cell
 * strings, mirroring the shape SheetJS's `sheet_to_json(ws, {header:1,
 * defval:""})` produces in the legacy frontend parser this was ported from
 * (see PoFileParser). Formulas, styles, merged cells, and multiple
 * worksheets beyond picking one by name are out of scope.
 *
 * Deliberately uses plain SimpleXML property access (->sheetData->row->c)
 * rather than ->xpath() with a registered prefix: registerXPathNamespace()
 * only applies to the exact object it was called on, not to elements
 * returned from later xpath() calls on that object — a well-known SimpleXML
 * gotcha that silently breaks nested xpath() on namespaced OOXML parts.
 * Direct property access resolves the (single, default) spreadsheetml
 * namespace correctly without that trap.
 *
 * Real .xls (the old pre-2007 binary format) is NOT supported — that is a
 * different, much more complex binary format. A .xls upload should be
 * rejected with a friendly "please re-save as .xlsx or .csv" message by the
 * caller, not attempted here.
 */
final class XlsxReader
{
    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array<int,array<int,string>> rows, each a sparse map of
     *         0-based column index -> cell text (missing columns simply
     *         absent — callers should read via ($row[$i] ?? '')).
     * @throws \RuntimeException on a corrupt/unreadable archive
     */
    public static function readSheet(string $filePath, ?string $preferredSheetName = null): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('File is not a readable .xlsx (zip) archive.');
        }

        try {
            $sheetPath = self::resolveSheetPath($zip, $preferredSheetName);
            $sharedStrings = self::readSharedStrings($zip);
            $sheetXml = $zip->getFromName($sheetPath);
            if ($sheetXml === false) {
                throw new \RuntimeException("Worksheet part '{$sheetPath}' not found inside the .xlsx archive.");
            }
            return self::parseSheetXml($sheetXml, $sharedStrings);
        } finally {
            $zip->close();
        }
    }

    /** @return string[] sheet tab names in workbook order (e.g. "01","02",...) */
    public static function listSheetNames(string $filePath): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \RuntimeException('File is not a readable .xlsx (zip) archive.');
        }
        try {
            return array_map(static fn ($s) => $s['name'], self::listSheets($zip));
        } finally {
            $zip->close();
        }
    }

    /** @return array<int,array{name:string,rId:string}> */
    private static function listSheets(\ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        if ($workbookXml === false) {
            throw new \RuntimeException('xl/workbook.xml not found — not a valid .xlsx file.');
        }
        $wb = new \SimpleXMLElement($workbookXml);
        $sheets = [];
        foreach ($wb->sheets->sheet as $sheetEl) {
            $rAttrs = $sheetEl->attributes(self::REL_NS);
            $sheets[] = ['name' => (string) $sheetEl['name'], 'rId' => (string) $rAttrs['id']];
        }
        if ($sheets === []) {
            throw new \RuntimeException('Workbook has no sheets.');
        }
        return $sheets;
    }

    /**
     * Resolves which xl/worksheets/sheetN.xml to read: the sheet named
     * $preferredSheetName if the workbook has one (exact match), otherwise
     * the first sheet in workbook order.
     */
    private static function resolveSheetPath(\ZipArchive $zip, ?string $preferredSheetName): string
    {
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($relsXml === false) {
            throw new \RuntimeException('xl/_rels/workbook.xml.rels not found — not a valid .xlsx file.');
        }
        $sheets = self::listSheets($zip);

        $rels = new \SimpleXMLElement($relsXml);
        $targetByRId = [];
        foreach ($rels->Relationship as $rel) {
            $targetByRId[(string) $rel['Id']] = (string) $rel['Target'];
        }

        $chosen = $sheets[0];
        if ($preferredSheetName !== null) {
            foreach ($sheets as $s) {
                if ($s['name'] === $preferredSheetName) {
                    $chosen = $s;
                    break;
                }
            }
        }

        $target = $targetByRId[$chosen['rId']] ?? null;
        if ($target === null) {
            throw new \RuntimeException("No relationship target found for sheet '{$chosen['name']}'.");
        }
        // Targets are relative to xl/ (e.g. "worksheets/sheet1.xml"); normalize.
        $target = ltrim($target, '/');
        return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
    }

    /** @return array<int,string> shared string index -> concatenated text */
    private static function readSharedStrings(\ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }
        $sst = new \SimpleXMLElement($xml);
        $out = [];
        foreach ($sst->si as $si) {
            // <si><t>text</t></si>  OR  <si><r><t>a</t></r><r><t>b</t></r></si> (rich text runs)
            if (isset($si->t)) {
                $out[] = (string) $si->t;
                continue;
            }
            $text = '';
            foreach ($si->r as $run) {
                $text .= (string) $run->t;
            }
            $out[] = $text;
        }
        return $out;
    }

    /** @return array<int,array<int,string>> */
    private static function parseSheetXml(string $sheetXml, array $sharedStrings): array
    {
        $sheet = new \SimpleXMLElement($sheetXml);

        $rows = [];
        foreach ($sheet->sheetData->row as $rowEl) {
            $rowIndex = ((int) $rowEl['r']) - 1;
            if ($rowIndex < 0) {
                continue;
            }
            $rowOut = [];
            foreach ($rowEl->c as $cellEl) {
                $ref = (string) $cellEl['r'];
                $colIndex = self::columnLetterToIndex(self::refToColumnLetters($ref));
                $type = (string) $cellEl['t'];
                $rowOut[$colIndex] = self::cellValue($cellEl, $type, $sharedStrings);
            }
            $rows[$rowIndex] = $rowOut;
        }

        // Re-index sequentially (0..N-1) preserving relative order, since row
        // numbers in the XML may have gaps (fully blank rows are often omitted).
        ksort($rows);
        return array_values($rows);
    }

    private static function cellValue(\SimpleXMLElement $cellEl, string $type, array $sharedStrings): string
    {
        if ($type === 's') {
            $idx = isset($cellEl->v) ? (int) $cellEl->v : -1;
            return $sharedStrings[$idx] ?? '';
        }
        if ($type === 'inlineStr') {
            if (isset($cellEl->is->t)) {
                return (string) $cellEl->is->t;
            }
            $text = '';
            foreach ($cellEl->is->r ?? [] as $run) {
                $text .= (string) $run->t;
            }
            return $text;
        }
        // 'str' (formula result string), 'b' (boolean), numeric (no/empty t), or 'e' (error):
        // the raw <v> text is exactly what's needed in every one of these cases.
        return isset($cellEl->v) ? (string) $cellEl->v : '';
    }

    /** "C5" -> "C" */
    private static function refToColumnLetters(string $ref): string
    {
        preg_match('/^([A-Z]+)\d+$/', $ref, $m);
        return $m[1] ?? 'A';
    }

    /** "A"->0, "B"->1, ..., "Z"->25, "AA"->26, ... */
    private static function columnLetterToIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split($letters) as $ch) {
            $index = $index * 26 + (ord($ch) - ord('A') + 1);
        }
        return $index - 1;
    }
}
