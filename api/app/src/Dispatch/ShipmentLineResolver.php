<?php

declare(strict_types=1);

namespace Amor\Api\Dispatch;

use Amor\Api\Delivery\DoRepository;
use Amor\Api\SpecialOrder\SpecialOrderDoRepository;
use PDO;

/**
 * Shared shipment-line read model (task's own "Final Pre-Live Rework"
 * Section B). A shipment's real dispatched lines live in one of TWO
 * physically separate tables depending on where the shipment came from —
 * shipment_item for Regular PO (product_id NOT NULL), or
 * special_order_do_shipment_item for a special/non-regular DO (no
 * product_id requirement at all, since a custom/catalog item has none).
 * That split stays exactly as Task 9 built it ("may remain... do not fake
 * product_id for custom items") — this class only adds a READ-time
 * normalization layer on top so every consumer (Driver history/detail,
 * Digital Surat Jalan, Admin shipment/receipt screens) can render either
 * kind through ONE shape, without ever inserting a fake shipment_item row
 * to satisfy an old screen.
 *
 * Purely additive/read-only — never writes, never duplicates a physical
 * line, never touches stock_ledger/fg.
 */
final class ShipmentLineResolver
{
    private DoRepository $doRepo;
    private SpecialOrderDoRepository $specialRepo;

    public function __construct()
    {
        $this->doRepo = new DoRepository();
        $this->specialRepo = new SpecialOrderDoRepository();
    }

    /**
     * @param array $shipment a plain `shipment` row (or any superset of
     *        it) — only shipment_id and source_type are read.
     * @return array<int,array{lineId:int,itemType:string,productId:?int,specialCatalogId:?int,itemName:string,qtyShipped:float,sourceOrderItemId:?int,division:?string,factory:?string,notes:?string}>
     */
    public function linesForShipment(PDO $pdo, array $shipment): array
    {
        $shipmentId = (int) $shipment['shipment_id'];
        if (($shipment['source_type'] ?? null) === 'special_order_do') {
            return array_map(static fn (array $r) => [
                'lineId' => (int) $r['special_order_do_shipment_item_id'],
                'itemType' => $r['item_type'],
                'productId' => $r['product_id'] !== null ? (int) $r['product_id'] : null,
                'specialCatalogId' => $r['special_catalog_id'] !== null ? (int) $r['special_catalog_id'] : null,
                'itemName' => $r['item_name_snapshot'],
                'qtyShipped' => (float) $r['qty'],
                'sourceOrderItemId' => (int) $r['special_order_item_id'],
                'division' => $r['division_name'] ?? null,
                'factory' => $r['factory_name'] ?? null,
                'notes' => $r['special_note'] ?? null,
            ], $this->specialRepo->findShipmentLines($pdo, $shipmentId));
        }

        return array_map(static fn (array $r) => [
            'lineId' => (int) $r['shipment_item_id'],
            'itemType' => 'existing_product',
            'productId' => (int) $r['product_id'],
            'specialCatalogId' => null,
            'itemName' => $r['product_name'],
            'qtyShipped' => (float) $r['qty'],
            'sourceOrderItemId' => null,
            'division' => $r['division_name'] ?? null,
            'factory' => null,
            'notes' => null,
        ], $this->doRepo->findShipmentItems($pdo, $shipmentId));
    }
}
