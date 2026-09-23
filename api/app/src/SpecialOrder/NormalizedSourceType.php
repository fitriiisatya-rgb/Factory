<?php

declare(strict_types=1);

namespace Amor\Api\SpecialOrder;

/**
 * The ONE authoritative downstream source classification (task's own "Final
 * Pre-Live Rework" Section A — "Do NOT infer source from customer name").
 * Derived exclusively from special_order.source_type + non_store_source —
 * the real, already-audited ENUM values from migration 0010
 * (source_type: 'toko_khusus'/'non_toko'; non_store_source:
 * 'konsumen_langsung'/'cs'/'sales_executive'/'umum') — never from free-text
 * customer_name/customer_contact. Regular PO has no special_order row at
 * all, so it is always REGULAR_STORE_PO, set directly by callers that know
 * they're looking at a Regular shipment/DO (never derived here).
 *
 * Every special/source-specific DO and Shipment exposes this via a plain
 * join back to special_order (through special_order_do) at read time —
 * deliberately NOT a snapshotted/denormalized column, because a source
 * classification is set once at order creation and never legitimately
 * changes afterward (unlike e.g. item_name_snapshot, which protects
 * against a LATER catalog edit reclassifying already-sent demand). This
 * keeps the "smallest safe schema addition" principle: zero new columns
 * for something a join already answers correctly.
 */
final class NormalizedSourceType
{
    public const REGULAR_STORE_PO = 'REGULAR_STORE_PO';
    public const SPECIAL_STORE_ORDER = 'SPECIAL_STORE_ORDER';
    public const CS_ORDER = 'CS_ORDER';
    public const SALES_ORDER = 'SALES_ORDER';
    public const DIRECT_CUSTOMER = 'DIRECT_CUSTOMER';
    public const GENERAL_ORDER = 'GENERAL_ORDER';
    /** Future work (task's own explicit "Replacement Reject remains future work") — never produced by fromSpecialOrder() today. */
    public const REPLACEMENT_REJECT = 'REPLACEMENT_REJECT';

    /**
     * @param string $sourceType special_order.source_type: 'toko_khusus' | 'non_toko'
     * @param string|null $nonStoreSource special_order.non_store_source: 'konsumen_langsung' | 'cs' | 'sales_executive' | 'umum' | null
     */
    public static function fromSpecialOrder(string $sourceType, ?string $nonStoreSource): string
    {
        if ($sourceType === 'toko_khusus') {
            return self::SPECIAL_STORE_ORDER;
        }
        return match ($nonStoreSource) {
            'cs' => self::CS_ORDER,
            'sales_executive' => self::SALES_ORDER,
            'konsumen_langsung' => self::DIRECT_CUSTOMER,
            'umum' => self::GENERAL_ORDER,
            // non_toko with an unset/unexpected non_store_source can't happen
            // through the normal create flow (SpecialOrderService validates
            // it), but this never throws for a read-only display helper —
            // GENERAL_ORDER is the safest, least-specific fallback.
            default => self::GENERAL_ORDER,
        };
    }

    public static function label(string $normalized): string
    {
        return match ($normalized) {
            self::REGULAR_STORE_PO => 'PO Reguler',
            self::SPECIAL_STORE_ORDER => 'Pesanan Khusus Toko',
            self::CS_ORDER => 'CS',
            self::SALES_ORDER => 'Sales',
            self::DIRECT_CUSTOMER => 'Konsumen Langsung',
            self::GENERAL_ORDER => 'Umum',
            self::REPLACEMENT_REJECT => 'Replacement Reject',
            default => $normalized,
        };
    }
}
