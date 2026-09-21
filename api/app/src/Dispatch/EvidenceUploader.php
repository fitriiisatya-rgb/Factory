<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\ApiException;

/**
 * Store Receipt photo evidence upload — the ONLY file-upload mechanism in
 * this project (audited first: no existing upload infrastructure was
 * found anywhere in the codebase, so this is a from-scratch, deliberately
 * minimal implementation, not a reuse of something bigger).
 *
 * Security discipline (task's own explicit list):
 *  - image only, whitelisted by REAL file content (finfo + getimagesize()),
 *    never the client-supplied MIME/extension, which is trivially forged;
 *  - no SVG (not a raster format getimagesize() accepts, and finfo would
 *    report text/xml or image/svg+xml — neither is in the whitelist);
 *  - max 5 MB per file, max 3 files per receipt;
 *  - server-generated random filename (bin2hex(random_bytes(16))) — the
 *    client's original filename is stored for DISPLAY ONLY, never used to
 *    build a path;
 *  - stored under api/uploads/receipt-evidence/, a sibling of api/app/
 *    with the SAME deny-all .htaccess — never directly web-reachable,
 *    always served through the admin-authenticated evidence controller
 *    (ReceiptController::adminEvidence()), so the real filesystem path is
 *    never exposed to a client and an uploaded file can never execute as
 *    a script regardless of its extension.
 *
 * Files are validated and moved to disk BEFORE any DB row is written —
 * see ReceiptService::confirmReceipt()'s own docblock for how a later
 * failure in the same request cleans them back up (deleteStoredFiles()).
 */
final class EvidenceUploader
{
    public const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 MB
    public const MAX_FILES = 3;

    /** @var array<string,string> real MIME (as sniffed from file content) -> stored extension */
    private const ALLOWED_MIME = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    public static function uploadRoot(): string
    {
        return dirname(__DIR__, 3) . '/uploads/receipt-evidence';
    }

    /**
     * Normalizes PHP's own $_FILES['evidence'] shape — which differs for a
     * single <input name="evidence"> vs a multi <input name="evidence[]"
     * multiple> — into one flat list. Never trusts the client type/name.
     * @param array $filesField Request::fileField('evidence')
     * @return array<int,array{tmpName:string,originalName:string,size:int,error:int}>
     */
    public static function normalize(array $filesField): array
    {
        if ($filesField === []) {
            return [];
        }
        if (!is_array($filesField['name'] ?? null)) {
            // Single-file shape.
            if (($filesField['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                return [];
            }
            return [[
                'tmpName' => (string) ($filesField['tmp_name'] ?? ''),
                'originalName' => (string) ($filesField['name'] ?? ''),
                'size' => (int) ($filesField['size'] ?? 0),
                'error' => (int) ($filesField['error'] ?? UPLOAD_ERR_NO_FILE),
            ]];
        }
        // Multi-file shape: $_FILES['evidence']['name'][0..n], etc.
        $out = [];
        foreach ($filesField['name'] as $i => $name) {
            $error = (int) ($filesField['error'][$i] ?? UPLOAD_ERR_NO_FILE);
            if ($error === UPLOAD_ERR_NO_FILE) {
                continue; // an empty extra <input> slot — not an error, just nothing chosen
            }
            $out[] = [
                'tmpName' => (string) ($filesField['tmp_name'][$i] ?? ''),
                'originalName' => (string) $name,
                'size' => (int) ($filesField['size'][$i] ?? 0),
                'error' => $error,
            ];
        }
        return $out;
    }

    /**
     * Validates every file in $filesField and moves the valid ones to
     * disk under a random filename. Throws ApiException(400, ...) — never
     * partially applies: on the first invalid file, nothing already moved
     * in THIS call is left behind (cleaned up before rethrowing).
     * @return array<int,array{filePath:string,mimeType:string,fileSize:int,originalName:?string}>
     */
    public static function validateAndStore(array $filesField): array
    {
        $normalized = self::normalize($filesField);
        if ($normalized === []) {
            return [];
        }
        if (count($normalized) > self::MAX_FILES) {
            throw new ApiException(400, 'TOO_MANY_EVIDENCE_FILES', 'Maksimal ' . self::MAX_FILES . ' foto bukti per pengiriman');
        }

        $root = self::uploadRoot();
        if (!is_dir($root) && !mkdir($root, 0755, true) && !is_dir($root)) {
            throw new ApiException(500, 'EVIDENCE_STORAGE_UNAVAILABLE', 'Penyimpanan bukti foto tidak tersedia di server');
        }

        $stored = [];
        try {
            foreach ($normalized as $f) {
                if ($f['error'] !== UPLOAD_ERR_OK) {
                    throw new ApiException(400, 'EVIDENCE_UPLOAD_ERROR', 'Gagal mengunggah salah satu foto bukti');
                }
                if ($f['size'] <= 0 || $f['size'] > self::MAX_FILE_SIZE) {
                    throw new ApiException(400, 'EVIDENCE_TOO_LARGE', 'Ukuran foto bukti maksimal 5 MB');
                }
                if (!is_uploaded_file($f['tmpName'])) {
                    // Defense in depth: refuses anything not delivered by
                    // PHP's own multipart handler, even though normalize()
                    // only ever reads from $_FILES in the first place.
                    throw new ApiException(400, 'EVIDENCE_UPLOAD_ERROR', 'Berkas bukti foto tidak valid');
                }

                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = $finfo !== false ? (finfo_file($finfo, $f['tmpName']) ?: '') : '';
                if ($finfo !== false) {
                    finfo_close($finfo);
                }
                if (!isset(self::ALLOWED_MIME[$mime])) {
                    throw new ApiException(400, 'EVIDENCE_INVALID_MIME', 'Foto bukti harus berformat JPEG, PNG, atau WEBP');
                }
                // getimagesize() actually decodes the image header — a
                // renamed .php/.svg/.html file with a spoofed MIME never
                // passes this, only a genuinely well-formed raster image does.
                if (@getimagesize($f['tmpName']) === false) {
                    throw new ApiException(400, 'EVIDENCE_INVALID_FILE', 'Berkas bukti foto tidak valid atau rusak');
                }

                $ext = self::ALLOWED_MIME[$mime];
                $randomName = bin2hex(random_bytes(16)) . '.' . $ext;
                $dest = $root . '/' . $randomName;
                if (!move_uploaded_file($f['tmpName'], $dest)) {
                    throw new ApiException(500, 'EVIDENCE_UPLOAD_FAILED', 'Gagal menyimpan foto bukti di server');
                }
                chmod($dest, 0644);

                $stored[] = [
                    'filePath' => $randomName,
                    'mimeType' => $mime,
                    'fileSize' => $f['size'],
                    'originalName' => $f['originalName'] !== '' ? mb_substr($f['originalName'], 0, 255) : null,
                ];
            }
        } catch (\Throwable $e) {
            self::deleteStoredFiles($stored);
            throw $e;
        }

        return $stored;
    }

    /** @param array<int,array{filePath:string}> $stored */
    public static function deleteStoredFiles(array $stored): void
    {
        $root = self::uploadRoot();
        foreach ($stored as $s) {
            $path = $root . '/' . basename($s['filePath']);
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /** basename() defends against a stored value ever containing a path traversal, even though every value here is always server-generated. */
    public static function absolutePath(string $filePath): string
    {
        return self::uploadRoot() . '/' . basename($filePath);
    }
}
