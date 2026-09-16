<?php

declare(strict_types=1);

namespace Amor\Api\Import;

/**
 * Loads the bundled legacy-source extraction (database/legacy/phase1-source-v1.json
 * — produced offline by dist/tools/extract-legacy-source.php, never parsed
 * live from the 750KB frontend HTML on a request). Same dual-location
 * pattern as the schema migration pointer files: packaged copy first, then
 * the monorepo's canonical copy for local/CI use.
 */
final class LegacyCatalogSource
{
    private static ?array $data = null;
    private static ?array $storeCandidates = null;

    public static function path(): string
    {
        $packaged = dirname(__DIR__, 2) . '/database/legacy/phase1-source-v1.json';
        $monorepo = dirname(__DIR__, 4) . '/database/legacy/phase1-source-v1.json';
        return is_file($packaged) ? $packaged : $monorepo;
    }

    public static function storeCandidatesPath(): string
    {
        $packaged = dirname(__DIR__, 2) . '/database/legacy/phase1-store-candidates-v1.json';
        $monorepo = dirname(__DIR__, 4) . '/database/legacy/phase1-store-candidates-v1.json';
        return is_file($packaged) ? $packaged : $monorepo;
    }

    /**
     * OPERATOR-PROVIDED, not source-derived — see the file's own "provenance"
     * field. Every entry is a REVIEW CANDIDATE only; nothing here is ever
     * auto-authoritative (docs: Phase 1 patch request, item 7).
     * @return array<int,array{canonicalName:string,pendingAliases?:string[],note?:string}>
     */
    public static function storeCandidates(): array
    {
        if (self::$storeCandidates !== null) {
            return self::$storeCandidates;
        }
        $path = self::storeCandidatesPath();
        if (!is_file($path)) {
            throw new \RuntimeException("Store candidate list not found at {$path}.");
        }
        $json = json_decode(file_get_contents($path), true);
        if ($json === null) {
            throw new \RuntimeException('Store candidate JSON decode failed: ' . json_last_error_msg());
        }
        return self::$storeCandidates = $json['candidates'];
    }

    public static function load(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $path = self::path();
        if (!is_file($path)) {
            throw new \RuntimeException("Legacy source data not found at {$path}. Run dist/tools/extract-legacy-source.php.");
        }
        $json = json_decode(file_get_contents($path), true);
        if ($json === null) {
            throw new \RuntimeException('Legacy source JSON decode failed: ' . json_last_error_msg());
        }
        return self::$data = $json;
    }

    public static function divisions(): array
    {
        return self::load()['divisions'];
    }

    public static function products(): array
    {
        return self::load()['products'];
    }

    public static function storeAliasGroups(): array
    {
        return self::load()['storeAliasGroups'];
    }
}
