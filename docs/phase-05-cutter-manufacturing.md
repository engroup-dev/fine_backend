# Phase 05: Cutter Manufacturing

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation), Phase 03 (Inventory), Phase 04 (Foam Manufacturing)

---

## 5.1 Objective

Build the cutter work order module: from client/internal request submission through template shape assignment, manual foam block selection, cutting execution, mandatory byproduct weigh-in, quality check, and inventory output. This is one of the most complex modules due to the template-vs-requested shape distinction, manual block selection, partial consumption logic, and automatic byproduct generation.

### Architecture & API Scope
All cutter work order creation, template selection, block assignment, cutting execution, and byproduct weigh-in logging execute live via direct central REST API endpoints against the central server database.

---

## 5.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Cutter Work Order lifecycle | Full state machine (Requested → Confirmed → InProduction → AwaitingByproductWeighIn → QualityCheck → Completed → Invoiced) |
| 2 | WorkOrderLine dual-shape tracking | `requested_spec` (client-facing) and `template_shape` (production/costing bounding box) stored distinctly |
| 3 | Manual block selection | System filters available blocks by volume ≥ template volume; user manually selects specific block(s) |
| 4 | Partial consumption logic | Full consumption → block status = Consumed; partial → remainder logic (default to byproduct for v1) |
| 5 | Mandatory byproduct weigh-in | Work order blocked from QualityCheck until offcuts and hard-top trims are weighed and recorded |
| 6 | Automatic byproduct yield | ByproductYield rows created from work order completion; increments byproduct-fill inventory by weight |
| 7 | Slice output tracking | Slices produced as distinct inventory items, usable in Furniture BOM |
| 8 | Template piece inventory | Cut pieces entered into Cutter inventory using template dimensions |
| 9 | Cost tracking | Material cost transferred from consumed block(s) to work order WIP, then to output inventory |
| 10 | Unit client: Work order management | Request entry, template assignment, block selection UI, cutting completion, weigh-in |

---

## 5.3 Database Migrations (This Phase)

- `cutter_work_orders`
- `work_order_lines`
- `foam_block_consumptions`
- `byproduct_yields`

*(Note: `slices` may reuse `inventory_items` + `stock_lots` with `item_type = slice`)*

---

## 5.4 State Machine: Cutter Work Order

```
Requested ──[Client/internal submits request]──► Confirmed

Confirmed ──[Cutter Manager assigns template_shape per line]──► InProduction
  • requested_spec = client-facing description (e.g., "round pillow, radius 8cm")
  • template_shape = bounding box (e.g., 8×8×L cm)

InProduction ──[Workers finish cutting]──► AwaitingByproductWeighIn
  • Consumes source FoamBlock stock (manual selection)
  • System blocks further progress until weigh-in

AwaitingByproductWeighIn ──[Offcuts weighed and recorded]──► QualityCheck
  • Creates ByproductYield row(s) with weight_kg (0.0 if none)
  • Increments byproduct-fill inventory

QualityCheck ──[Cutter Manager verifies output]──► Completed
  • Increments Cutter inventory: template-cut pieces, slices
  • Posts production cost journal entry

Completed ──[Sale finalized]──► Invoiced
  • External sale: credit-gated (Sales module)
  • Internal sale: no credit limit, same order model
```

---

## 5.5 Template vs. Requested Shape Rule

This is a **core business rule** that must be enforced at the data model level, not generalized away:

| Field | Purpose | Example |
|-------|---------|---------|
| `requested_spec` | Client-facing description on quotes/invoices | "Round pillow insert, radius 8cm" |
| `template_shape` | Internal production, inventory, and costing | `{"length": 0.08, "width": 0.08, "height": 0.15}` (bounding cube) |

**Costing rule:** The client is billed for the full template volume. The material "lost" cutting the custom shape out of the block is recovered through template-based pricing.

---

## 5.6 Manual Block Selection Flow

```
1. Cutter Manager opens WorkOrderLine
2. System calls: GET /api/v1/stock-lots/available-for-cutting?min_volume_m3={template_volume}
3. System returns list of available FoamBlock StockLots:
   - status = available
   - volume_m3 >= template_volume
   - sorted by: volume ascending (default), or grade, or dimensions
4. Cutter Manager manually clicks/selects the specific block(s) to use
5. System records FoamBlockConsumption:
   - work_order_line_id
   - foam_block_stock_lot_id
   - volume_consumed_m3
   - consumption_type: full | partial
```

**Explicit constraint (SRS 22.2):** The system does NOT auto-assign, score, or optimize block selection. It filters and presents; the human decides.

---

## 5.7 Consumption Logic

### Full Consumption
```
Condition: block.volume_m3 ≈ template_volume (nothing usable remains)
Result:
  - stock_lot.status = "consumed"
  - stock_lot.quantity = 0
  - Full block.unit_cost moves to work order WIP
```

### Partial Consumption (Default v1: All Remainder → Byproduct)
```
Condition: block.volume_m3 > template_volume
Result:
  - consumed_volume_cost = block.unit_cost × (template_volume / block.volume_m3)
  - consumed_volume_cost → work order WIP
  - remainder_volume = block.volume_m3 - template_volume
  - remainder automatically becomes ByproductYield (weight estimated or weighed)
  - stock_lot.status = "consumed" (full block consumed)
  
W4-06: a clean remainder registered at completion becomes a new smaller block StockLot
(remnant_of_lot_id = the cut block) at its volume share of the block cost; only the
rest of the offcut is byproduct.
```

---

## 5.8 Stock Movements from One Work Order

Three `StockMovement` records are created:

| Movement Type | From | To | Quantity |
|---------------|------|-----|----------|
| `cutter_consumption` | FoamBlock StockLot | WIP | consumed volume |
| `template_piece_output` | WIP | Cutter inventory (template piece) | 1 (serialized) |
| `byproduct_yield` | WIP | Cutter inventory (byproduct fill) | weight_kg |

---

## 5.9 API Endpoints (This Phase)

### Cutter Work Orders
```
GET    /api/v1/cutter-work-orders
POST   /api/v1/cutter-work-orders
GET    /api/v1/cutter-work-orders/{id}
PUT    /api/v1/cutter-work-orders/{id}
POST   /api/v1/cutter-work-orders/{id}/transition
POST   /api/v1/cutter-work-orders/{id}/confirm-templates     → Confirmed state
POST   /api/v1/cutter-work-orders/{id}/start-production      → InProduction state
POST   /api/v1/cutter-work-orders/{id}/submit-weigh-in       → AwaitingByproductWeighIn → QualityCheck
POST   /api/v1/cutter-work-orders/{id}/complete              → Completed state
```

### Work Order Lines
```
GET    /api/v1/cutter-work-orders/{id}/lines
POST   /api/v1/cutter-work-orders/{id}/lines
PUT    /api/v1/work-order-lines/{id}
PUT    /api/v1/work-order-lines/{id}/assign-template         ← set template_shape
```

### Block Selection
```
GET    /api/v1/work-order-lines/{id}/available-blocks        ← filtered list for manual selection
POST   /api/v1/work-order-lines/{id}/select-block            ← record consumption
```

### Byproduct
```
GET    /api/v1/cutter-work-orders/{id}/byproduct-yields
POST   /api/v1/cutter-work-orders/{id}/byproduct-yields
```

---

## 5.10 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| CUT-01 | Every WorkOrderLine must have both requested_spec and template_shape before InProduction | Validation on Confirmed → InProduction transition |
| CUT-02 | Block selection is manual; system only filters by volume ≥ template | UI presents list; API records selection but never auto-assigns |
| CUT-03 | Work order cannot reach QualityCheck until byproduct weigh-in recorded | State machine guard; weigh-in API required |
| CUT-04 | Byproduct weight must be recorded even if zero | Validation: weight_kg is required (0.0 explicitly allowed) |
| CUT-05 | Template piece inventory uses template dimensions, not requested shape | StockLot created with template_shape dimensions |
| CUT-06 | Client is billed for full template volume | SalesOrderLine uses template-derived unit cost and price |
| CUT-07 | Full consumption: block status → Consumed, full cost to WIP | Model observer on consumption record |
| CUT-08 | Partial consumption: the consumed block cost C splits by volume at completion (W4-06): pieces C × P/V, usable remnants C × R/V (restocked as smaller blocks of the block's item), byproduct C × S/V with S = V − P − R, priced per kg of the weigh-in (a zero weigh-in puts S on the pieces, and S = max(0, V − P − R)). Remnants claimed beyond what the pieces left are refused (`CUT_VOLUME_EXCEEDS_BLOCKS`, with a rounding tolerance per block, remnant and line); with no remnant nothing is refused, so pieces larger than the blocks just carry the whole cost (a deviation from the W4-06 plan's unconditional P + R > V refusal, kept so reserve-path and sale-component orders whose quantities round up can always complete; pending owner confirmation). Each remnant may name the cut block it came from (`stock_lot_id`, else the first block) and is restocked as that block's item; the remnants of one block must fit it (`CUT_VOLUME_EXCEEDS_BLOCKS`), the block must be one the order cut (`CUT_REMNANT_NO_SOURCE_BLOCK`) and have a size (`BLOCK_DIMENSIONS_REQUIRED`), and a remnant must measure at least 0.0001 m³ (`CUT_REMNANT_TOO_SMALL`). A positive weigh-in needs a byproduct fill item (`BYPRODUCT_ITEM_REQUIRED`) and always stocks a fill lot (named warehouse, else the output warehouse, else the first block's). Weigh-ins price the fill at the no-remnant split, shared by weight across all the order's weigh-ins: a later weigh-in re-prices the earlier untouched lots, and is refused if one has moved (`BYPRODUCT_ALREADY_MOVED`), so a completion without remnants never changes a fill price. A re-price is recorded as a zero-quantity `adjustment` movement (`reason = cutter_byproduct_reprice`); the yield movement keeps its weigh-in price (movements are append-only). At completion, remnants that would lower the price of a fill lot that has moved are refused (`BYPRODUCT_ALREADY_MOVED`; completing without remnants keeps the price). A moved lot whose price differs for any other reason (reserve-path lots weighed in before W4-06 were priced at 0) keeps its price and the difference stays on the pieces, as does the share of a pre-W4-06 positive weigh-in with no lot. Pieces, remnants and (by default) the weighed-in fill land in the order's `output_warehouse_id` (set on create or on the completion, a warehouse of the order's unit, `OUTPUT_WAREHOUSE_OTHER_UNIT`), else the first block's warehouse | `CutterWorkOrderService::splitCost()` / `produceOutputs()`; completion takes `remnants: [{length_m, width_m, height_m, stock_lot_id?}]` (max 50, sides ≥ 0.01) and `output_warehouse_id?`; journal DR 1132 + DR 1131 + DR 1133 / CR 1122 |
| CUT-09 | Three StockMovement records per work order | Transactionally created on Completed transition |
| CUT-10 | Slices are a distinct inventory item type | `inventory_items.item_type = slice` |

---

## 5.11 Unit Client Screens

1. **Work Order List:** Pipeline view by status; filter by client/internal
2. **Request Entry:** Form with requested_spec (free text) and quantity
3. **Template Assignment:** Cutter Manager views each line, enters template_shape (L×W×H), sees volume calculation
4. **Block Selection:** Grid of available blocks filtered by min volume; click to select; shows block grade, dimensions, cost
5. **Cutting Execution:** Simple screen for workshop staff to mark lines complete
6. **Weigh-In:** Form for offcut weight and hard-top trim weight; required before QC
7. **Quality Check:** Cutter Manager verifies output, approves for completion

---

## 5.12 Testing Strategy

- **Unit tests:** Volume comparison logic, cost pro-ration, byproduct weight validation
- **Feature tests:** Full work order lifecycle; manual block selection; mandatory weigh-in gate
- **Policy tests:** Cutter Manager cannot select blocks below template volume; workshop staff cannot approve QC

---

## 5.13 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Template shape entry is error-prone | Medium | Volume auto-calculation; preview of bounding box; validation against machine limits |
| Partial consumption logic too complex for v1 | Medium | Default all remainder to byproduct; cleaner remainder restocking as v1.5 enhancement |
| Byproduct weigh-in skipped by users | High | Hard state machine gate; work order literally cannot progress without it |
| Many work order lines per order | Low | Pagination; bulk template assignment where multiple lines share same shape |

---

## 5.14 Open Questions to Resolve

1. **Clean remainder restocking:** Should partial consumption ever create a new smaller block, or always byproduct? (SRS 19.3) — *Default: always byproduct for v1.*

---

## 5.15 Phase Exit Criteria

- [ ] Work order can be created and moved through full lifecycle to Completed
- [ ] Template shape assignment enforced before production starts
- [ ] Manual block selection works with volume-filtered list
- [ ] Byproduct weigh-in is mandatory; work order blocked without it
- [ ] Three stock movements created correctly per work order
- [ ] Template pieces and byproduct fill correctly enter Cutter inventory
- [ ] Journal entry auto-posts on completion
- [ ] Unit client screens functional in Arabic/RTL
