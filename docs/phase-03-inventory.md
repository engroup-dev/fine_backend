# Phase 03: Inventory Management

> **Duration Estimate:** 3–4 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation, Auth, Units)

---

## 3.1 Objective

Build the inventory backbone that all manufacturing and sales modules depend on. Each of the five operating units maintains its own independent inventory ledger. The system must support: (a) serialized tracking for foam blocks (each block is a unique `StockLot` with dimensions, grade, and cost), (b) weighted-average costing for tank-mixed raw materials, (c) byproduct fill tracked by weight, (d) slices as a distinct BOM-component item type, and (e) stock movements that trace every inventory event back to its source document.

### Architecture & API Scope
All inventory lookups, serialized block stock lots, tank stock tracking, and stock movements execute live via direct central REST API endpoints against the central server database.

---

## 3.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Inventory item master | CRUD for `InventoryItem` with type enum and UOM; SKU uniqueness |
| 2 | Warehouse/Location management | CRUD for warehouses per operating unit |
| 3 | Serialized stock lots (foam blocks) | Each block is a `StockLot` with unique lot number, dimensions, volume (m³), grade, unit cost |
| 4 | Weighted-average tank stock | `TankStock` recalculates avg cost on every refill: `(old_qty × old_avg + refill_qty × refill_cost) / (old_qty + refill_qty)` |
| 5 | Stock movement engine | All inventory changes go through `StockMovement` with polymorphic reference to source document |
| 6 | Inventory valuation | Real-time valuation per unit and company-wide rollup |
| 7 | Barrels/pallets as sellable items | Empty containers returned from Raw Material Preparation entered as `InventoryItem` type `barrel` or `pallet` |
| 8 | Unit client: Inventory screens | Stock lookup by unit, item type, grade; movement history per lot |
| 9 | Unit client: Tank management | Tank levels, refill entry, weighted-avg cost display |

---

## 3.3 Database Migrations (This Phase)

- `inventory_items`
- `warehouses` (if not done in Phase 01)
- `stock_lots`
- `stock_movements`
- `tank_stocks`

---

## 3.4 Entity Deep Dive

### 3.4.1 StockLot — The Serialized Inventory Unit

```php
// Core fields
inventory_item_id  → FK to InventoryItem
warehouse_id       → FK to Warehouse (unit-scoped)
lot_number         → Unique identifier (e.g., "FOAM-2024-07-BATCH-42-001")
quantity           → 1 for serialized items; >1 for bulk raw materials
length_m           → nullable
width_m            → nullable
height_m           → nullable
volume_m3          → auto-calculated for foam blocks
weight_kg          → for byproduct fill
unit_cost          → weighted-avg or batch-apportioned cost
grade              → standard | acceptable_variant | defective_usable | reject
status             → available | reserved | consumed | damaged | quarantined
production_batch_id → nullable; links back to foam batch
record_version     → optimistic locking
```

### 3.4.2 StockMovement — Every Inventory Transaction

```php
stock_lot_id            → FK to StockLot
from_warehouse_id       → nullable (null for receipts)
to_warehouse_id         → nullable (null for issues)
movement_type           → receipt | issue | transfer | adjustment | consumption | production_output | byproduct_yield | sale
quantity                → amount moved
reference_document_id   → polymorphic FK
reference_document_type → ImportOrder | ProductionBatch | SalesOrder | CutterWorkOrder | InternalRestockRequest | Adjustment
```

### 3.4.3 TankStock — Weighted-Average Chemical Tracking

```php
chemical_inventory_item_id  → FK to InventoryItem (type = raw_material)
operating_unit_id           → Foam unit
quantity_on_hand            → current liters in tank
weighted_avg_unit_cost      → recalculated on every refill
```

**Refill formula:**
```
new_avg = (old_qty × old_avg + refill_qty × refill_unit_cost) / (old_qty + refill_qty)
```

---

## 3.5 API Endpoints (This Phase)

### Inventory Items
```
GET    /api/v1/inventory-items
POST   /api/v1/inventory-items
GET    /api/v1/inventory-items/{id}
PUT    /api/v1/inventory-items/{id}
DELETE /api/v1/inventory-items/{id}
```

### Stock Lots
```
GET    /api/v1/stock-lots
POST   /api/v1/stock-lots                    ← manual adjustment only; normal creation via manufacturing events
GET    /api/v1/stock-lots/{id}
PUT    /api/v1/stock-lots/{id}
GET    /api/v1/stock-lots/by-grade           ← filter for cutter block selection
GET    /api/v1/stock-lots/by-volume          ← filter min volume for cutter
```

### Stock Movements
```
GET    /api/v1/stock-movements
POST   /api/v1/stock-movements               ← manual adjustments only
GET    /api/v1/stock-movements/{id}
GET    /api/v1/stock-movements/for-document/{type}/{id}
```

### Tank Stocks
```
GET    /api/v1/tank-stocks
POST   /api/v1/tank-stocks/refill            ← triggers weighted-avg recalculation
GET    /api/v1/tank-stocks/{id}
```

### Inventory Valuation
```
GET    /api/v1/inventory/valuation           ← per-unit summary
GET    /api/v1/inventory/valuation/rollup    ← company-wide total
GET    /api/v1/inventory/ledger/{warehouse_id}
```

---

## 3.6 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| INV-01 | Each operating unit has its own independent inventory | `warehouse.operating_unit_id` scope; no cross-unit stock lot sharing |
| INV-02 | Foam blocks are individually serialized; each is its own StockLot | `quantity = 1` enforced for `item_type = foam_block` |
| INV-03 | Tank stock uses weighted-average costing, recalculated on every refill | Service method `TankStockService::refill()` encapsulates formula |
| INV-04 | Byproduct fill is tracked by weight (kg), not by count | `unit_of_measure = kg` on byproduct inventory items |
| INV-05 | Slices are a distinct InventoryItem type, usable as BOM components | `item_type = slice` |
| INV-06 | StockMovement is the ONLY way inventory quantities change | All modules create StockMovement records; no direct StockLot quantity updates |
| INV-07 | StockLot status = `consumed` when fully used by cutter | Set by CutterWorkOrder completion handler |
| INV-08 | Partial block consumption creates either: (a) new smaller StockLot, or (b) ByproductYield | W4-06: remnants registered on a cutter completion or a cut-remnant decision are restocked at their volume share of the block cost; the rest is byproduct (or, for a standalone cut with nothing weighed in, written off to 52) |
| INV-09 | Inventory valuation rollup is read-only for Owner/GM | Policy on valuation endpoints |
| INV-10 | Empty barrels/drums and pallets are sellable inventory items | `item_type = barrel \| pallet` with `unit_of_measure = each` |
| INV-11 | A lot's value enters and leaves the ledger through its item type's inventory account (2026-10-29) | `InventoryAccounts::forItem` / `forLot`; `inventory:reconcile-accounts` |

### Inventory accounts (INV-11)

Every posting that moves lot value names the account from the lot's item type, never the unit's `purchases` mapping: `foam_block` → 1131, `cut_template_piece` / `slice` → 1132, `byproduct_fill` → 1133, `furniture_finished_good` / `finished_good` → 1134, everything else (raw materials, chemicals, containers, fabric) → 111. Intake, local and import purchase receipts, sale cost, bundle delivery, restock and internal transfers (same account, only the unit changes), sales-return restock, foam close and cutter outputs all use it; WIP accounts 1121/1122/1123 hold value in production by design (a block reserved by a cutter work order sits in 1122).

`php artisan inventory:reconcile-accounts {--post} {--date=} {--unit=}` compares, per operating unit, each of 111 and 1131–1134 with the value of the unit's live lots (quantity > 0; available, quarantined or reserved for a sale) and prints unit, account, expected, actual and difference. Without `--post` it writes nothing. With `--post` each unit with a difference of at least 0.01 gets one adjustment entry ("تسوية حسابات المخزون مع قيمة الدفعات") that moves every account to its expected balance, the net to 52 فروقات المخزون والتصنيع; a locked month refuses it (ACC-11) and a second run posts nothing. Run it once after deploying this release: first without `--post`, review the table, then with `--post`.

What the command leaves out, refuses or warns about:

- Lots whose value is still in work in process are not counted: a block attached to a cutter work order (whatever its status; the value sits in 1122), a byproduct fill lot weighed in on a cutter order that is not completed yet (its value reaches the fill account only at completion), and blocks of a foam batch that is not closed yet (their value reaches 1131 only when the close apportions the batch cost and posts it).
- **Foam production consumption is not posted today.** Production never draws raw-material lots down (no route reaches `StockLotService::drawFromLot`), while the close credits 1121 with the batch's material cost. So 111 and its lots both still carry the consumed material, and 1121 carries the credit; the reconcile leaves 111 alone in that case, since ledger and lots agree. The command measures the gap directly (close credits on 1121 against consumption entries DR 1121 / CR 111, not the 1121 balance, which overhead absorption also feeds), prints it per unit, and `--post` refuses a unit only when that gap exists **and** 111 runs above its lots: consumed lots were then probably written down by hand with no entry, so the surplus belongs to 1121, not 52. Post DR 1121 / CR 111 for it (the lots are already down, so an entry alone is right), then run again. Do not post a consumption entry for lots that were not drawn down: the next run would see 111 below its lots and book the difference back through 52.
- Blocks consumed through a cut-remnant decision before W4-06 with no remnant restocked (`POST /stock-lots/{id}/process-cut-remnant`) kept their value on the block's account with no entry. The consumed lot no longer counts as stock, so `--post` books that value to 52 as a variance; the command warns per unit before it does. Since W4-06 the decision posts its own entry: the remnant takes C × R / V back into the block's account, the rest goes to a byproduct fill lot (priced per kg; `byproduct_inventory_item_id`, else the oldest fill item) or, with nothing weighed in, to 52; the block leaves stock with quantity 0 and only an available foam block lot of exactly one block can be cut (`LOT_NOT_AVAILABLE`, `LOT_NOT_FOAM_BLOCK`, `LOT_NOT_SINGLE_BLOCK`). A remnant needs a block with a size (`BLOCK_DIMENSIONS_REQUIRED`), at least 0.0001 m³ (`CUT_REMNANT_TOO_SMALL`) and no more than the block (`CUT_VOLUME_EXCEEDS_BLOCKS`); a weigh-in needs a byproduct fill item to exist (`BYPRODUCT_ITEM_REQUIRED`).
- Lot-account lines with no operating unit, and lots or lines of a deleted unit, appear on a "(no unit / deleted unit)" row with a warning. They are never posted, because no unit entry can correct them, so they need a manual correction.
- 52 and the lot accounts must be postable leaves. If one has gained sub-accounts, `--post` writes nothing.
- Warehouse transfers move lots only within one unit: both warehouses must belong to the transfer's own unit, on create, edit and completion (`TRANSFER_CROSS_UNIT`). Use an internal restock or an internal sale instead, since those post the value between the units.

Follow-ups, not built yet:

- Production consumption posting: a foam batch (and other production) should draw its raw-material lots down and post DR 1121 / CR 111 in the same transaction. Until then 1121 carries a credit for every closed batch and 111 overstates raw materials.
- An item's `item_type` can still be edited while it holds stock. Its lots would then sit in another account with no entry, and the next reconcile would book that reclassification to 52 as a variance.

---

## 3.7 Block Selection for Cutter (Preparation for Phase 05)

The Cutter module (Phase 05) needs to manually select foam blocks. The inventory module must provide:

```
GET /api/v1/stock-lots/available-for-cutting
    ?min_volume_m3={template_volume}
    &grade={preferred_grade}
    &sort_by=volume_asc|volume_desc|grade
```

Response includes only blocks where:
- `inventory_item.item_type = foam_block`
- `status = available`
- `warehouse.operating_unit_id = Foam unit`
- `volume_m3 >= min_volume_m3`

**Important:** The system filters the list but does NOT auto-select. The Cutter Manager manually picks from this filtered list (explicit business rule — SRS Section 19.3, 22.2).

---

## 3.8 Testing Strategy

- **Unit tests:** Weighted-average recalculation formula, volume calculation from dimensions, stock movement balance (total in = total out per lot)
- **Feature tests:** Full tank refill → new avg cost correct; serialized block creation; movement history traceability
- **Policy tests:** Unit A cannot see Unit B stock lots; workshop staff can only view/enter, not adjust

---

## 3.9 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Serialized tracking at scale (~138 blocks × many batches) | Medium | Pagination + search by lot number; consider DB partitioning by batch date if volume grows |
| Weighted-average cost drift | Low | Reconciliation report monthly; formula is standard and well-understood |
| Partial block consumption logic complexity | Medium | Default all leftovers to byproduct for v1; clean remainder restocking as Phase 05 enhancement |
| Tank stock running negative | High | Validation: `refill_qty + current_qty` must cover consumption; block batch run if insufficient |

---

## 3.10 Open Questions to Resolve

1. **Clean remainder restocking:** Should partial block consumption ever create a new smaller block StockLot, or always default to byproduct? (SRS 19.3) — *Default: always byproduct for v1.*
2. **Grade-based cost discounting:** Does grade affect unit cost, or only sale price? (SRS 19.1) — *Default: grade affects sale price only; all blocks in a batch share same unit cost.*

---

## 3.11 Phase Exit Criteria

- [ ] Inventory items can be created with all supported types and UOMs
- [ ] Tank stock correctly recalculates weighted-average cost on refill
- [ ] Serialized foam blocks can be created with dimensions, grade, and cost
- [ ] Stock movement records every change with polymorphic source reference
- [ ] Inventory valuation returns correct per-unit and company-wide totals
- [ ] Block selection API filters correctly by volume and grade
- [ ] Audit log tracks all inventory adjustments
- [ ] Unit client inventory screens are functional in Arabic/RTL
