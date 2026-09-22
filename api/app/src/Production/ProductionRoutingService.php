<?php

declare(strict_types=1);

namespace Amor\Api\Production;

use Amor\Api\ApiException;
use PDO;

/**
 * The single authoritative division -> factory resolver. Every place in
 * the app that needs "which factory does this production division belong
 * to" (Pesanan Khusus Toko / Pesanan Non-Toko item routing today, and any
 * future caller) goes through this class — never a scattered
 * `if ($divisionName === 'Bolu') { $factory = 'Cibadak'; }` literal.
 *
 * There is no new mapping table here: migration 0002 already made
 * division.factory_id an authoritative, NOT NULL master-data column
 * (Bolu -> Cibadak, every other real production division -> Karangtengah —
 * see database/schema-v1-0002-master-identity.sql's own docblock). This
 * service is a thin, validated read over that existing column, not a new
 * source of truth — read call sites that only need to LIST/DISPLAY the
 * mapping for many rows at once (e.g. Production's demand inbox) still
 * join division -> factory directly in SQL for performance; this service
 * exists for the single-row "resolve and validate before an authoritative
 * write" path (see SpecialOrderService::resolveItem()).
 */
final class ProductionRoutingService
{
    /**
     * Resolves the factory a production division belongs to. Throws
     * (task's own explicit "Safety" rule — never silently guess) if the
     * division does not exist or, defensively, if it somehow has no
     * factory assigned (division.factory_id is NOT NULL by schema, so
     * this only fires for a division_id that doesn't exist at all — kept
     * as a real, testable safety net rather than assumed unreachable).
     *
     * @return array{factoryId:int,factoryName:string,divisionName:string}
     */
    public static function resolveFactoryForDivision(PDO $pdo, int $divisionId): array
    {
        $stmt = $pdo->prepare(
            'SELECT d.name AS division_name, f.factory_id, f.name AS factory_name
             FROM division d
             INNER JOIN factory f ON f.factory_id = d.factory_id
             WHERE d.division_id = ?'
        );
        $stmt->execute([$divisionId]);
        $row = $stmt->fetch();

        if ($row === false) {
            throw new ApiException(
                422,
                'FACTORY_ROUTING_UNRESOLVED',
                "Factory tujuan tidak dapat ditentukan untuk Divisi ID {$divisionId}. Periksa master divisi/factory."
            );
        }

        return [
            'factoryId' => (int) $row['factory_id'],
            'factoryName' => $row['factory_name'],
            'divisionName' => $row['division_name'],
        ];
    }
}
