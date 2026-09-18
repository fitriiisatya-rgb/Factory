<?php

declare(strict_types=1);

namespace Amor\Api\Ui;

/**
 * Minimal, dependency-free QR Code encoder (ISO/IEC 18004), byte mode only,
 * error-correction level L, fixed mask pattern 0. Written from scratch for
 * this project because the whole stack is intentionally composer-free
 * (shared-hosting/cPanel deployment, no vendor/ directory to ship) — see
 * XlsxReader.php for the same "no external dependency" precedent.
 *
 * Supports versions 1-10 (up to 274 data codewords at level L, i.e. up to
 * ~271 bytes of byte-mode payload before overhead) — comfortably enough for
 * this project's one and only use: encoding a receipt-portal URL
 * (`https://<host>/api/_receive/?token=<64 hex chars>`, well under 150
 * bytes even on a long hostname).
 *
 * Correctness note: this is genuinely spec-compliant Reed-Solomon ECC +
 * standard module placement/masking/format-info, verified against a
 * reference QR library by round-tripping through a real decoder during
 * development (see the Phase 5.5 final report) — not a decorative
 * QR-shaped image.
 */
final class QrEncoder
{
    /** Total data codewords per version (1-10), EC level L. */
    private const DATA_CODEWORDS = [19, 34, 55, 80, 108, 136, 156, 194, 232, 274];
    /** EC codewords PER BLOCK per version (1-10), EC level L. */
    private const EC_PER_BLOCK = [7, 10, 15, 20, 26, 18, 20, 24, 30, 18];
    /** [group1 blockCount, group1 blockSize, group2 blockCount, group2 blockSize] per version (1-10), EC level L. */
    private const BLOCK_STRUCTURE = [
        [1, 19, 0, 0], [1, 34, 0, 0], [1, 55, 0, 0], [1, 80, 0, 0], [1, 108, 0, 0],
        [2, 68, 0, 0], [2, 78, 0, 0], [2, 97, 0, 0], [2, 116, 0, 0], [2, 68, 2, 69],
    ];
    /** Alignment pattern center coordinates per version (1-10); empty for version 1. */
    private const ALIGNMENT_CENTERS = [
        [], [6, 18], [6, 22], [6, 26], [6, 30], [6, 34], [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    ];
    /** Remainder bits after data placement, per version (1-10). */
    private const REMAINDER_BITS = [0, 7, 7, 7, 7, 7, 0, 0, 0, 0];

    /** @return array{size:int,matrix:array<int,array<int,bool>>} true = dark module */
    public static function encode(string $data): array
    {
        $version = self::selectVersion(strlen($data));
        $dataCodewords = self::buildDataCodewords($data, $version);
        [$group1Count, $group1Size, $group2Count, $group2Size] = self::BLOCK_STRUCTURE[$version - 1];
        $ecPerBlock = self::EC_PER_BLOCK[$version - 1];

        $blocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ([[$group1Count, $group1Size], [$group2Count, $group2Size]] as [$count, $size]) {
            for ($i = 0; $i < $count; $i++) {
                $block = array_slice($dataCodewords, $offset, $size);
                $offset += $size;
                $blocks[] = $block;
                $ecBlocks[] = self::reedSolomonEncode($block, $ecPerBlock);
            }
        }

        $finalCodewords = self::interleave($blocks, $ecBlocks);

        $size = 17 + 4 * $version;
        $matrix = array_fill(0, $size, array_fill(0, $size, false));
        $isFunction = array_fill(0, $size, array_fill(0, $size, false));

        self::placeFinderPattern($matrix, $isFunction, 0, 0);
        self::placeFinderPattern($matrix, $isFunction, $size - 7, 0);
        self::placeFinderPattern($matrix, $isFunction, 0, $size - 7);
        self::placeTimingPatterns($matrix, $isFunction, $size);
        self::placeAlignmentPatterns($matrix, $isFunction, $version, $size);
        self::reserveFormatAreas($isFunction, $size);
        if ($version >= 7) {
            self::reserveVersionAreas($isFunction, $size);
        }
        $matrix[$size - 8][8] = true; // dark module, always present
        $isFunction[$size - 8][8] = true;

        self::placeData($matrix, $isFunction, $finalCodewords, $size);
        self::applyFormatInfo($matrix, $isFunction, $size, 0 /* mask 0 */);
        if ($version >= 7) {
            self::applyVersionInfo($matrix, $isFunction, $version, $size);
        }

        return ['size' => $size, 'matrix' => $matrix];
    }

    public static function toSvg(string $data, int $moduleSize = 4, string $darkColor = '#000', string $lightColor = '#fff'): string
    {
        $enc = self::encode($data);
        $n = $enc['size'];
        $quiet = 4; // modules of white border, per spec minimum
        $total = ($n + $quiet * 2) * $moduleSize;
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $total . ' ' . $total . '" width="' . $total . '" height="' . $total . '" shape-rendering="crispEdges">'
             . '<rect width="100%" height="100%" fill="' . $lightColor . '"/>';
        for ($r = 0; $r < $n; $r++) {
            for ($c = 0; $c < $n; $c++) {
                if ($enc['matrix'][$r][$c]) {
                    $x = ($c + $quiet) * $moduleSize;
                    $y = ($r + $quiet) * $moduleSize;
                    $svg .= '<rect x="' . $x . '" y="' . $y . '" width="' . $moduleSize . '" height="' . $moduleSize . '" fill="' . $darkColor . '"/>';
                }
            }
        }
        $svg .= '</svg>';
        return $svg;
    }

    private static function selectVersion(int $byteLength): int
    {
        for ($v = 1; $v <= 10; $v++) {
            $charCountBits = $v <= 9 ? 8 : 16;
            $headerBits = 4 + $charCountBits;
            $capacityBits = self::DATA_CODEWORDS[$v - 1] * 8;
            if ($headerBits + $byteLength * 8 + 4 <= $capacityBits) { // +4 terminator (best case)
                return $v;
            }
        }
        throw new \RuntimeException('QrEncoder: data too long for supported versions 1-10 (' . $byteLength . ' bytes)');
    }

    /** @return int[] codewords (0-255) */
    private static function buildDataCodewords(string $data, int $version): array
    {
        $bits = '0100'; // byte mode indicator
        $charCountBits = $version <= 9 ? 8 : 16;
        $bits .= str_pad(decbin(strlen($data)), $charCountBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < strlen($data); $i++) {
            $bits .= str_pad(decbin(ord($data[$i])), 8, '0', STR_PAD_LEFT);
        }

        $totalDataBits = self::DATA_CODEWORDS[$version - 1] * 8;
        // Terminator: up to 4 zero bits.
        $bits .= str_repeat('0', min(4, $totalDataBits - strlen($bits)));
        // Pad to a multiple of 8.
        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }
        // Pad codewords 0xEC, 0x11 alternating until full.
        $padBytes = ['11101100', '00010001'];
        $i = 0;
        while (strlen($bits) < $totalDataBits) {
            $bits .= $padBytes[$i % 2];
            $i++;
        }

        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }
        return $codewords;
    }

    // ------------------------------------------------------------------
    // GF(256) Reed-Solomon (generator 2, primitive polynomial 0x11D).
    // ------------------------------------------------------------------

    /** @var int[]|null */
    private static ?array $gfExp = null;
    /** @var int[]|null */
    private static ?array $gfLog = null;

    private static function initGf(): void
    {
        if (self::$gfExp !== null) {
            return;
        }
        $exp = array_fill(0, 512, 0);
        $log = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[$i] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11D;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[$i] = $exp[$i - 255];
        }
        self::$gfExp = $exp;
        self::$gfLog = $log;
    }

    private static function gfMul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$gfExp[self::$gfLog[$a] + self::$gfLog[$b]];
    }

    /**
     * Generator polynomial g(x) = product_{i=0}^{ecCount-1} (x - alpha^i),
     * coefficients highest-degree-first (g[0] is always 1, the leading
     * monic term). Built via plain polynomial multiplication rather than
     * an incremental in-place trick — this exact form was cross-checked
     * against the independent `reedsolo` Python library during development
     * after an earlier incremental version had a term-order bug that
     * produced undecodable QR codes (see the Phase 5.5 final report).
     * @return int[]
     */
    private static function generatorPolynomial(int $ecCount): array
    {
        $g = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            // Multiply g by [1, gfExp[i]] (i.e. the degree-1 polynomial x - alpha^i).
            $next = array_fill(0, count($g) + 1, 0);
            foreach ($g as $gi => $gCoef) {
                $next[$gi] ^= self::gfMul($gCoef, 1);
                $next[$gi + 1] ^= self::gfMul($gCoef, self::$gfExp[$i]);
            }
            $g = $next;
        }
        return $g;
    }

    /**
     * Standard systematic polynomial long division: dividend (data,
     * highest-degree-first) padded with ecCount zero coefficients, divided
     * by the generator, remainder = the EC codewords.
     * @param int[] $data @return int[] EC codewords
     */
    private static function reedSolomonEncode(array $data, int $ecCount): array
    {
        self::initGf();
        $generator = self::generatorPolynomial($ecCount);
        $msg = array_merge($data, array_fill(0, $ecCount, 0));
        $dataLen = count($data);
        for ($i = 0; $i < $dataLen; $i++) {
            $coef = $msg[$i];
            if ($coef === 0) {
                continue;
            }
            for ($j = 0; $j < count($generator); $j++) {
                if ($generator[$j] !== 0) {
                    $msg[$i + $j] ^= self::gfMul($generator[$j], $coef);
                }
            }
        }
        return array_slice($msg, $dataLen);
    }

    /** @param int[][] $blocks @param int[][] $ecBlocks @return int[] */
    private static function interleave(array $blocks, array $ecBlocks): array
    {
        $out = [];
        $maxLen = max(array_map('count', $blocks));
        for ($i = 0; $i < $maxLen; $i++) {
            foreach ($blocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        $maxEcLen = max(array_map('count', $ecBlocks));
        for ($i = 0; $i < $maxEcLen; $i++) {
            foreach ($ecBlocks as $b) {
                if (isset($b[$i])) {
                    $out[] = $b[$i];
                }
            }
        }
        return $out;
    }

    // ------------------------------------------------------------------
    // Matrix construction
    // ------------------------------------------------------------------

    private static function placeFinderPattern(array &$matrix, array &$isFunction, int $top, int $left): void
    {
        for ($r = -1; $r <= 7; $r++) {
            for ($c = -1; $c <= 7; $c++) {
                $rr = $top + $r;
                $cc = $left + $c;
                if ($rr < 0 || $cc < 0 || $rr >= count($matrix) || $cc >= count($matrix)) {
                    continue;
                }
                $isFunction[$rr][$cc] = true;
                if ($r < 0 || $r > 6 || $c < 0 || $c > 6) {
                    $matrix[$rr][$cc] = false; // separator (white)
                    continue;
                }
                $isRing1 = ($r === 0 || $r === 6 || $c === 0 || $c === 6);
                $isCore = ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4);
                $matrix[$rr][$cc] = $isRing1 || $isCore;
            }
        }
    }

    private static function placeTimingPatterns(array &$matrix, array &$isFunction, int $size): void
    {
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;
            $matrix[6][$i] = $dark;
            $isFunction[6][$i] = true;
            $matrix[$i][6] = $dark;
            $isFunction[$i][6] = true;
        }
    }

    private static function placeAlignmentPatterns(array &$matrix, array &$isFunction, int $version, int $size): void
    {
        $centers = self::ALIGNMENT_CENTERS[$version - 1];
        if ($centers === []) {
            return;
        }
        foreach ($centers as $row) {
            foreach ($centers as $col) {
                // Skip the three positions that overlap a finder pattern corner.
                if (($row <= 8 && $col <= 8) || ($row <= 8 && $col >= $size - 9) || ($row >= $size - 9 && $col <= 8)) {
                    continue;
                }
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $rr = $row + $r;
                        $cc = $col + $c;
                        $isFunction[$rr][$cc] = true;
                        $ring = (abs($r) === 2 || abs($c) === 2);
                        $center = ($r === 0 && $c === 0);
                        $matrix[$rr][$cc] = $ring || $center;
                    }
                }
            }
        }
    }

    private static function reserveFormatAreas(array &$isFunction, int $size): void
    {
        for ($i = 0; $i <= 8; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        for ($i = 0; $i < 8; $i++) {
            $isFunction[8][$size - 1 - $i] = true;
            $isFunction[$size - 1 - $i][8] = true;
        }
    }

    private static function reserveVersionAreas(array &$isFunction, int $size): void
    {
        for ($r = 0; $r < 6; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $isFunction[$r][$size - 11 + $c] = true;
                $isFunction[$size - 11 + $c][$r] = true;
            }
        }
    }

    private static function placeData(array &$matrix, array $isFunction, array $codewords, int $size): void
    {
        $bits = '';
        foreach ($codewords as $cw) {
            $bits .= str_pad(decbin($cw), 8, '0', STR_PAD_LEFT);
        }
        $version = ($size - 17) / 4;
        $bits .= str_repeat('0', self::REMAINDER_BITS[$version - 1]);

        $bitIndex = 0;
        $bitLen = strlen($bits);
        $col = $size - 1;
        $upward = true;
        while ($col > 0) {
            if ($col === 6) {
                $col--; // skip the vertical timing column
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? ($size - 1 - $i) : $i;
                foreach ([$col, $col - 1] as $c) {
                    if ($isFunction[$row][$c]) {
                        continue;
                    }
                    $bitVal = $bitIndex < $bitLen ? $bits[$bitIndex] === '1' : false;
                    $bitIndex++;
                    $masked = self::maskAt($row, $c) ? !$bitVal : $bitVal;
                    $matrix[$row][$c] = $masked;
                }
            }
            $upward = !$upward;
            $col -= 2;
        }
    }

    /** Mask pattern 0: (row + col) mod 2 === 0. */
    private static function maskAt(int $row, int $col): bool
    {
        return ($row + $col) % 2 === 0;
    }

    /**
     * Bit i is extracted as (bch >> i) & 1 — i.e. i=0 is the LSB of the
     * 15-bit format codeword. Placement below was derived empirically by
     * round-tripping a rendered QR through an independent decoder during
     * development (see the Phase 5.5 final report) after an initial
     * row/col mix-up produced an undecodable image — every coordinate here
     * is verified against that decoder, not just copied from memory of the
     * spec diagram.
     */
    private static function applyFormatInfo(array &$matrix, array $isFunction, int $size, int $mask): void
    {
        // EC level bits per ISO/IEC 18004 table: L=01, M=00, Q=11, H=10.
        $ecBits = 0b01;
        $data = ($ecBits << 3) | $mask; // 5 bits
        $bch = self::bchFormat($data);
        $bit = static fn (int $i): bool => (($bch >> $i) & 1) === 1;

        // Copy 1 — around the top-left finder pattern.
        for ($i = 0; $i <= 5; $i++) {
            $matrix[$i][8] = $bit($i);
        }
        $matrix[7][8] = $bit(6);
        $matrix[8][8] = $bit(7);
        $matrix[8][7] = $bit(8);
        for ($i = 9; $i <= 14; $i++) {
            $matrix[8][14 - $i] = $bit($i);
        }

        // Copy 2 — split across the bottom-left and top-right corners.
        for ($i = 0; $i <= 7; $i++) {
            $matrix[8][$size - 1 - $i] = $bit($i);
        }
        for ($i = 8; $i <= 14; $i++) {
            $matrix[$size - 15 + $i][8] = $bit($i);
        }
    }

    private static function bchFormat(int $data): int
    {
        $g = 0b10100110111; // generator, degree 10
        $value = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if ($value & (1 << $i)) {
                $value ^= $g << ($i - 10);
            }
        }
        $result = ($data << 10) | $value;
        return $result ^ 0b101010000010010; // fixed XOR mask
    }

    /** Bit i (LSB-first) goes at row=i/3, col=i%3 within the 6x3 block (row-major, verified per applyFormatInfo's own note). */
    private static function applyVersionInfo(array &$matrix, array $isFunction, int $version, int $size): void
    {
        $bch = self::bchVersion($version);
        for ($i = 0; $i < 18; $i++) {
            $bitVal = (($bch >> $i) & 1) === 1;
            $r = intdiv($i, 3);
            $c = $i % 3;
            $matrix[$r][$size - 11 + $c] = $bitVal;
            $matrix[$size - 11 + $c][$r] = $bitVal;
        }
    }

    private static function bchVersion(int $version): int
    {
        $g = 0b1111100100101; // generator, degree 12
        $value = $version << 12;
        for ($i = 17; $i >= 12; $i--) {
            if ($value & (1 << $i)) {
                $value ^= $g << ($i - 12);
            }
        }
        return ($version << 12) | $value;
    }
}
