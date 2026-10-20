-- ============================================================================
-- Migration 0024 — "Impor Stok Masuk" file for Runchise per surat jalan
-- ============================================================================
--
-- WHY THIS MIGRATION EXISTS
-- --------------------------
-- After a store confirms a shipment, it downloads one CSV per surat jalan
-- and imports it into Runchise (Produk → Stok → Stok Masuk → Impor). The
-- conversion needs a stored, admin-editable mapping (never fuzzy-matched at
-- runtime): store → Runchise location, product → Runchise product, plus a
-- record of when each file was downloaded so stock is not imported twice.
--
-- CHANGES
--   - store.runchise_location: exact Runchise location name (NULL = not set).
--   - product.runchise_sku / runchise_name / runchise_unit / runchise_mode:
--     mode 'stok' = goes into the file, 'abaikan' = not stock, 'topping' =
--     sales add-on, not stock. NULL mode = not mapped yet.
--   - shipment.runchise_downloaded_at / runchise_downloaded_by /
--     runchise_download_count.
--   - runchise_catalog: the Runchise product list uploaded by Admin.
--   - Initial fill: store locations from the store name (fixed alias list),
--     and the donut ring / topping rules already used by FinHub.
--
-- SAFETY
-- ------
--   - Additive only: new nullable/defaulted columns and one new table.
--   - The initial fill only touches rows whose new columns are still NULL,
--     so re-running is a no-op and never overwrites Admin's own mapping.
-- ============================================================================

ALTER TABLE store ADD COLUMN IF NOT EXISTS runchise_location VARCHAR(100) NULL AFTER channel;

ALTER TABLE product ADD COLUMN IF NOT EXISTS runchise_sku VARCHAR(64) NULL AFTER aktif;
ALTER TABLE product ADD COLUMN IF NOT EXISTS runchise_name VARCHAR(255) NULL AFTER runchise_sku;
ALTER TABLE product ADD COLUMN IF NOT EXISTS runchise_unit VARCHAR(32) NULL AFTER runchise_name;
ALTER TABLE product ADD COLUMN IF NOT EXISTS runchise_mode ENUM('stok','abaikan','topping') NULL AFTER runchise_unit;

ALTER TABLE shipment ADD COLUMN IF NOT EXISTS runchise_downloaded_at DATETIME NULL;
ALTER TABLE shipment ADD COLUMN IF NOT EXISTS runchise_downloaded_by VARCHAR(150) NULL;
ALTER TABLE shipment ADD COLUMN IF NOT EXISTS runchise_download_count INT UNSIGNED NOT NULL DEFAULT 0;

CREATE TABLE IF NOT EXISTS runchise_catalog (
  sku          VARCHAR(64)  NOT NULL PRIMARY KEY,
  name         VARCHAR(255) NOT NULL,
  unit         VARCHAR(32)  NOT NULL DEFAULT 'PCS',
  uploaded_at  DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Store → Runchise location: name without a leading "Bakery"/"Toko", upper
-- case, spaces removed, matched against the valid locations and the fixed
-- alias list (Perumnas 3 → PERUMNAS, Villa Nusa Indah → NUSA INDAH, ...).
UPDATE store s
INNER JOIN (
  SELECT 'ABDULGANI' AS k, 'ABDUL GANI' AS loc UNION ALL SELECT 'ABDULGHANI', 'ABDUL GANI'
  UNION ALL SELECT 'BANGBARUNG', 'BANGBARUNG' UNION ALL SELECT 'BANTARGEBANG', 'BANTARGEBANG'
  UNION ALL SELECT 'BAROS', 'BAROS' UNION ALL SELECT 'BENDA', 'BENDA'
  UNION ALL SELECT 'BENDHIL', 'BENDHIL' UNION ALL SELECT 'BENHIL', 'BENDHIL'
  UNION ALL SELECT 'BOJONGLOPANG', 'BOJONGLOPANG' UNION ALL SELECT 'CIBADAK', 'CIBADAK'
  UNION ALL SELECT 'CIBINONG', 'CIBINONG' UNION ALL SELECT 'CIBUBUR', 'CIBUBUR'
  UNION ALL SELECT 'CICURUG', 'CICURUG' UNION ALL SELECT 'CIKARET', 'CIKARET'
  UNION ALL SELECT 'CIKEMBANG', 'CIKEMBANG' UNION ALL SELECT 'CIKOLE', 'CIKOLE'
  UNION ALL SELECT 'CIPAYUNG', 'CIPAYUNG' UNION ALL SELECT 'CISAAT', 'CISAAT'
  UNION ALL SELECT 'DRAMAGA', 'DRAMAGA' UNION ALL SELECT 'HARJAMUKTI', 'HARJAMUKTI'
  UNION ALL SELECT 'JATIMELATI', 'JATIMELATI' UNION ALL SELECT 'KEJAYAAN', 'KEJAYAAN'
  UNION ALL SELECT 'LEMBURSITU', 'LEMBURSITU' UNION ALL SELECT 'MEKARSARI', 'MEKARSARI'
  UNION ALL SELECT 'NUSAINDAH', 'NUSA INDAH' UNION ALL SELECT 'VILLANUSAINDAH', 'NUSA INDAH'
  UNION ALL SELECT 'PAGELARAN', 'PAGELARAN' UNION ALL SELECT 'PAMURUYAN', 'PAMURUYAN'
  UNION ALL SELECT 'PANGLESERAN', 'PANGLESERAN' UNION ALL SELECT 'PARUNGKUDA', 'PARUNGKUDA'
  UNION ALL SELECT 'PEKAPURAN', 'PEKAPURAN' UNION ALL SELECT 'PERUMNAS', 'PERUMNAS'
  UNION ALL SELECT 'PERUMNAS3', 'PERUMNAS' UNION ALL SELECT 'PLARA', 'PLARA'
  UNION ALL SELECT 'PELABUHANRATU', 'PLARA' UNION ALL SELECT 'PONDOKUNGU', 'PONDOK UNGU'
  UNION ALL SELECT 'RAWALUMBU', 'RAWALUMBU' UNION ALL SELECT 'RTMDEPOK', 'RTM DEPOK'
  UNION ALL SELECT 'SESEUPAN', 'SESEUPAN' UNION ALL SELECT 'SEUSEUPAN', 'SESEUPAN'
  UNION ALL SELECT 'SUDIRMAN', 'SUDIRMAN' UNION ALL SELECT 'SUKALARANG', 'SUKALARANG'
  UNION ALL SELECT 'SUKAHATI', 'Sukahati' UNION ALL SELECT 'VILLABOGORINDAH', 'VILLA BOGOR INDAH'
) m ON m.k = REPLACE(REGEXP_REPLACE(UPPER(TRIM(s.canonical_name)), '^(BAKERY|TOKO)[[:space:]]+', ''), ' ', '')
SET s.runchise_location = m.loc
WHERE s.runchise_location IS NULL;

-- Donut ring / shell: one Runchise product DONUT PLAIN (209621), summed —
-- toppings are added in the store.
UPDATE product p
SET p.runchise_sku = '209621', p.runchise_name = 'DONUT PLAIN', p.runchise_unit = 'PCS', p.runchise_mode = 'stok'
WHERE p.runchise_mode IS NULL
  AND (UPPER(p.name) LIKE 'DONUT RING%' OR UPPER(p.name) LIKE 'DONAT RING%'
       OR EXISTS (SELECT 1 FROM product_legacy_code l WHERE l.product_id = p.product_id AND l.legacy_code IN ('DT0060', 'DT0026')));

-- Topping Cherry (OT0006) is stock in Runchise; the other OT items are
-- sales add-ons, never stock.
UPDATE product p
SET p.runchise_sku = 'KFGJS343', p.runchise_name = 'Topping Cherry', p.runchise_unit = 'PCS', p.runchise_mode = 'stok'
WHERE p.runchise_mode IS NULL
  AND EXISTS (SELECT 1 FROM product_legacy_code l WHERE l.product_id = p.product_id AND l.legacy_code = 'OT0006');
UPDATE product p
SET p.runchise_mode = 'topping'
WHERE p.runchise_mode IS NULL
  AND EXISTS (SELECT 1 FROM product_legacy_code l WHERE l.product_id = p.product_id AND l.legacy_code LIKE 'OT%');
