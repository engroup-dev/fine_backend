# Phase 02: Procurement, Import & Treasury

> **Duration Estimate:** 4–5 weeks  
> **Team Size:** 2 backend engineers, 1 frontend engineer  
> **Dependencies:** Phase 01 (Foundation, Auth, Units)

---

## 2.1 Objective

Build the foreign procurement pipeline from supplier order creation through multi-currency payment execution (bank hold/release or black-market exchange) to landed-cost allocation into raw-material inventory. This phase also delivers the Treasury module with FX rate capture, FX gain/loss posting, and multi-currency cash account management.

### Architecture & API Scope
All procurement, supplier lookups, import orders, payment requests, bank holds, FX execution, and landed cost GL postings execute live over central REST API endpoints directly against the central server.

---

## 2.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Supplier management CRUD | Full CRUD with contact info, default currency, history of orders |
| 2 | Import Order lifecycle | Full state machine (Draft → PendingPayment → AwaitingBankApproval/AwaitingTransfer → Paid → InTransit → AtPort → AwaitingReceipt → Received → Complete) |
| 3 | Payment Request & Bank Hold | Two-route payment execution; bank hold buffer tracking; remainder release |
| 4 | Landed Cost tracking | Discrete LandedCostLine entries per cost component (supplier price, FX spread, customs, freight, local transport) |
| 5 | Goods Receipt | Physical receipt confirmation linking to warehouse and import order |
| 6 | FX Rate capture | Snapshot on every conversion; historical rate lookup |
| 7 | FX Gain/Loss auto-posting | Journal entry auto-generated when realized rate differs from booked estimate |
| 8 | Treasury cash accounts | Multi-currency account balances; account ledger view |
| 9 | Unit client: Procurement screens | Import order creation, status pipeline, goods receipt entry |
| 10 | Unit client: Treasury screens | Payment execution, FX rate entry, cash account overview |

---

## 2.3 Database Migrations (This Phase)

- `suppliers`
- `import_orders`
- `payment_requests`
- `bank_holds`
- `landed_cost_lines`
- `goods_receipts`
- `fx_rates`
- `cash_accounts`

---

## 2.4 State Machine: Import Order

```
Draft ──[Procurement enters order]──► PendingPayment

PendingPayment ──[Treasury selects bank route]──► AwaitingBankApproval
PendingPayment ──[Treasury selects market route]──► AwaitingTransfer

AwaitingBankApproval ──[Bank approves hold]──► PaymentProcessing
PaymentProcessing ──[Bank converts & remits]──► Paid

AwaitingTransfer ──[Market exchange completed]──► Paid

Paid ──[Supplier confirms & ships]──► InTransit

InTransit ──[Goods arrive at port]──► AtPort

AtPort ──[Transport to warehouse]──► AwaitingReceipt

AwaitingReceipt ──[Warehouse checks in]──► Received

Received ──[Accounting verifies all costs settled]──► Complete
```

### Side Effects per Transition

| Transition | Side Effect |
|------------|-------------|
| `PendingPayment → *` | Creates `PaymentRequest`; notifies Treasury |
| `AwaitingBankApproval → PaymentProcessing` | Creates `BankHold`; reserves LYD buffer in `cash_accounts` |
| `PaymentProcessing → Paid` | Releases BankHold remainder; records FX rate used |
| `AwaitingTransfer → Paid` | Records FX rate at market rate |
| `InTransit → AtPort` | Adds customs/freight `LandedCostLines` |
| `AtPort → AwaitingReceipt` | Adds local_transport `LandedCostLine` |
| `AwaitingReceipt → Received` | Creates `GoodsReceipt`; increments Main Warehouse inventory (provisional value) |
| `Received → Complete` | Allocates full landed cost into raw-material valuation; posts AP settlement + landed-cost journal entries |

**Supplier leg of purchase order journals (W5-07).** A payment (import `executePayment`, local `payLocal`) debits the order supplier's own ledger account, or the shared `15` Advances to Suppliers when the supplier has none; the supplier is found whatever operating unit it belongs to (the caller's `X-Operating-Unit-ID` plays no part) and a soft-deleted supplier counts as having no account. The clearing credit (the import completion journal and the local receipt journal) does not re-derive that account: it credits the one supplier-side account (`15` or any supplier-linked account) on which the order's own earlier entries (source `PurchaseOrder` or its `PaymentRequest`s, reversals included) left a net debit, so an order paid on `15` before its supplier got an account, or paid under another unit's header before W5-07, still clears to zero where it opened. Only when nothing has posted for the order yet, or balances sit on more than one such account (no split is invented), does the clearing fall back to the payment rule. Both lookups lock suppliers before orders, the order a supplier merge takes them in.

---

## 2.5 API Endpoints (This Phase)

### Suppliers
```
GET    /api/v1/suppliers
POST   /api/v1/suppliers
GET    /api/v1/suppliers/{id}
PUT    /api/v1/suppliers/{id}
DELETE /api/v1/suppliers/{id}
GET    /api/v1/suppliers/{id}/import-orders
```

### Import Orders
```
GET    /api/v1/import-orders
POST   /api/v1/import-orders
GET    /api/v1/import-orders/{id}
PUT    /api/v1/import-orders/{id}
POST   /api/v1/import-orders/{id}/transition        ← state machine transitions
GET    /api/v1/import-orders/{id}/landed-cost-lines
POST   /api/v1/import-orders/{id}/landed-cost-lines
GET    /api/v1/import-orders/{id}/goods-receipts
POST   /api/v1/import-orders/{id}/goods-receipts
```

### Treasury
```
GET    /api/v1/payment-requests
POST   /api/v1/payment-requests
PUT    /api/v1/payment-requests/{id}/execute        ← Treasury executes payment
GET    /api/v1/bank-holds
GET    /api/v1/fx-rates
POST   /api/v1/fx-rates
GET    /api/v1/cash-accounts
GET    /api/v1/cash-accounts/{id}/ledger
```

---

## 2.6 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| PROC-01 | ImportOrder currency defaults to supplier's default currency | Default in model; overridable |
| PROC-02 | Only Treasury role can execute payment routes | Policy check on PaymentRequestController |
| PROC-03 | Bank hold amount must exceed invoice converted cost (buffer) | Validation on `held_amount_lyd` field |
| PROC-04 | Landed cost lines must sum to total inventory value on completion | Auto-validation before Complete transition |
| PROC-05 | GoodsReceipt quantity must not exceed ImportOrder quantity | Validation rule |
| PROC-06 | FX rate must be captured at time of every conversion | Required field on PaymentRequest execution |
| PROC-07 | FX gain/loss posted when realized rate ≠ booked estimate | Observer on PaymentRequest status change to Paid |
| PROC-08 | ImportOrder cannot reach Complete without GoodsReceipt | State machine guard |
| PROC-09 | All landed cost components capitalized into inventory, not expensed | Journal entry template (DR Inventory, CR AP/Landed Cost Clearing) |
| PROC-10 | Soft delete only — no hard deletes on ImportOrders with payments | Model policy + observer |

---

## 2.7 Landed Cost Allocation Formula

```
landed_cost_per_unit = (
    supplier_price
    + fx_spread_cost
    + customs_cost
    + freight_cost
    + local_transport_cost
) / received_quantity

// Posted to Accounting on Complete:
DR  Raw Material Inventory (at landed_cost_per_unit × qty)
CR  Accounts Payable / Landed Cost Clearing
```

---

## 2.8 FX Gain/Loss Posting

```
booked_rate  = rate at ImportOrder creation/estimate
realized_rate = rate at PaymentRequest execution

if (realized_rate > booked_rate):
    // LYD weakened — we paid more
    DR  FX Loss (Expense)
    CR  Accounts Payable
else:
    // LYD strengthened — we paid less
    DR  Accounts Payable
    CR  FX Gain (Revenue)
```

---

## 2.9 Testing Strategy

- **Unit tests:** Landed cost calculator, FX gain/loss calculator, state machine guards
- **Feature tests:** Full import order lifecycle (Draft → Complete), bank hold buffer validation, FX posting accuracy
- **Policy tests:** Procurement cannot execute payments; Treasury cannot create import orders

---

## 2.10 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Landed cost estimation vs. actual variance | Medium | Build for estimated costs now; variance journal entries can be added in Phase 08 if needed |
| Bank API integration unavailable | High | Bank hold is a manual tracking record; no actual bank API integration in v1 — Treasury user updates status manually |
| Multi-currency complexity grows | Medium | Scope to USD⇄LYD only for v1; schema supports generic pairs but business logic targets this pair |
| Customs/freight costs unpredictable | Low | Manual entry per shipment; no formula estimation |

---

## 2.11 Open Questions to Resolve

1. **Estimated vs. confirmed landed costs:** Can ImportOrder close with estimated costs and true up later? (SRS Section 6, 18) — *Default: block Complete until all LandedCostLines are confirmed.*
2. **Multi-currency scope:** Is USD⇄LYD sufficient, or do we need a generic rate matrix? (SRS Section 7, 18) — *Default: USD⇄LYD only for v1; schema keeps generic pair fields.*
3. **Bank integration:** Is there any bank API available, or is everything manual entry? — *Assumption: manual status updates in v1.*

---

## 2.12 Phase Exit Criteria

- [ ] Import order can be created and moved through full state machine to Complete
- [ ] Landed cost is correctly calculated and allocated on Complete
- [ ] PaymentRequest executes both bank and market routes successfully
- [ ] FX gain/loss journal entry auto-posts when rates differ
- [ ] GoodsReceipt increments Main Warehouse inventory
- [ ] Audit log captures every state transition with actor and timestamp
- [ ] Unit client screens for Procurement and Treasury are functional
- [ ] All Treasury actions blocked for non-Treasury roles
