# Phase 09: HR & Payroll

> **Duration Estimate:** 3–4 weeks  
> **Team Size:** 1–2 backend engineers, 1 frontend engineer (shared with Phase 08)  
> **Dependencies:** Phase 01 (Foundation)

---

## 9.1 Objective

Build the HR module covering employee records, attendance tracking, labor role rate versioning, labor time logging against production/work orders (for costing), and full payroll processing with state-machine-controlled payroll runs. Payroll must ultimately comply with Libyan labor law, but statutory deductions are flagged as requiring local legal input.

### Architecture & API Scope
All employee record lookups, daily attendance tracking, workshop labor time logging, payroll calculations, approvals, and payroll GL postings execute live via direct central REST API endpoints against the central server database.

---

## 9.2 Deliverables

| # | Deliverable | Acceptance Criteria |
|---|-------------|---------------------|
| 1 | Employee management | CRUD for employees scoped to operating unit; distinct from system User accounts |
| 2 | Attendance tracking | Daily present/absent/leave/half_day records with hours worked |
| 3 | Labor role rates | Versioned pay rates per role (tailor, carpenter, etc.); effective date tracking |
| 4 | Labor logging | Hours logged against ProductionOrder or CutterWorkOrder; rate snapshot at log time |
| 5 | Payroll run lifecycle | Draft → Calculated → PendingApproval → Approved → Paid → Posted |
| 6 | Payslip generation | Per-employee gross pay, deductions, net pay; linked to journal entry |
| 7 | Leave requests | Employee time-off request workflow with approval |
| 8 | Payroll journal posting | Auto-posts wage-expense and payable entries per unit on Posted status |
| 9 | Unit client: HR screens | Employee list, attendance entry, leave requests, payroll view |
| 10 | Unit client: Labor logging | Simple screen for workshop staff to log hours by order and role |

---

## 9.3 Database Migrations (This Phase)

- `employees`
- `attendances`
- `labor_role_rates`
- `payroll_runs`
- `payslips`
- `leave_requests`

*(Note: `labor_logs` created in Phase 06)*

---

## 9.4 State Machine: Payroll Run

```
Draft ──[HR opens payroll period]──► Calculated
  • Aggregates Attendance/LaborLog hours per employee
  • Multiplies by LaborRoleRate effective for period
  • Generates draft Payslips

Calculated ──[HR reviews draft payslips]──► PendingApproval

PendingApproval ──[Accounting Manager/Owner reviews total cost]──► Approved

Approved ──[Treasury disburses payment]──► Paid
  • Net pay disbursed per employee

Paid ──[Accounting Manager posts to ledger]──► Posted
  • Posts wage-expense and payable/cash journal entries per unit
```

---

## 9.5 Employee vs. User

```
Employee: A person employed by the company
  - Has job_title, labor_role, employment_type, base_rate
  - Scoped to operating_unit
  - May or may not have a system login

User: A system login account
  - Has email, password, roles, permissions
  - Can be linked to Employee via user_id (nullable)
  - One User can be linked to one Employee; not all Employees have Users
```

---

## 9.6 Labor Cost Integration

### Labor Log → Production Costing
```
// Created during Furniture production (Phase 06) or Foam batch (Phase 04)
LaborLog {
  production_order_id OR cutter_work_order_id
  employee_id
  role
  hours_logged
  hourly_rate_at_log  // snapshot from LaborRoleRate at creation time
}

// Production order cost rollup:
production_order.total_labor_cost = SUM(labor_logs.hours_logged × labor_logs.hourly_rate_at_log)
```

### Labor Log → Payroll
```
// Payroll calculation:
employee_gross_pay = SUM(attendance.hours_worked × applicable_rate) 
                     + SUM(labor_logs.hours_logged × labor_logs.hourly_rate_at_log)
                     + base_salary (if salaried)
```

---

## 9.7 Payslip Structure

```
Payslip {
  employee_id
  payroll_run_id
  
  gross_pay: decimal
  
  deductions: [
    { type: "social_security", amount: x },
    { type: "income_tax", amount: y },
    { type: "advance", amount: z },
    // ... extensible for Libyan statutory requirements
  ]
  
  net_pay: decimal  // gross_pay - sum(deductions)
  
  journal_entry_id  // linked after Posted
}
```

**Important:** Deduction types and rates require Libyan HR/legal input. Build schema to be extensible; hardcode minimum viable deductions for v1 if local input is unavailable.

---

## 9.8 API Endpoints (This Phase)

### Employees
```
GET    /api/v1/employees
POST   /api/v1/employees
GET    /api/v1/employees/{id}
PUT    /api/v1/employees/{id}
DELETE /api/v1/employees/{id}
GET    /api/v1/employees/{id}/attendance
GET    /api/v1/employees/{id}/labor-logs
GET    /api/v1/employees/{id}/payslips
```

### Attendance
```
GET    /api/v1/attendance
POST   /api/v1/attendance/bulk                   ← bulk daily entry
PUT    /api/v1/attendance/{id}
```

### Labor Role Rates
```
GET    /api/v1/labor-role-rates
POST   /api/v1/labor-role-rates
GET    /api/v1/labor-role-rates/current          ← current effective rates
```

### Payroll
```
GET    /api/v1/payroll-runs
POST   /api/v1/payroll-runs                      ← open new period
POST   /api/v1/payroll-runs/{id}/calculate       → Draft → Calculated
POST   /api/v1/payroll-runs/{id}/submit          → Calculated → PendingApproval
POST   /api/v1/payroll-runs/{id}/approve         → PendingApproval → Approved
POST   /api/v1/payroll-runs/{id}/mark-paid       → Approved → Paid
POST   /api/v1/payroll-runs/{id}/post            → Paid → Posted
GET    /api/v1/payroll-runs/{id}/payslips
GET    /api/v1/payslips/{id}
```

### Leave Requests
```
GET    /api/v1/leave-requests
POST   /api/v1/leave-requests
PUT    /api/v1/leave-requests/{id}/approve
PUT    /api/v1/leave-requests/{id}/reject
```

---

## 9.9 Key Business Rules

| Rule ID | Description | Enforcement |
|---------|-------------|-------------|
| HR-01 | Employee records are distinct from User accounts | Separate table; optional `user_id` linkage |
| HR-02 | Employees are scoped to an operating unit | `employees.operating_unit_id` foreign key |
| HR-03 | Attendance feeds into both payroll and production costing | Same data used by PayrollRun and LaborLog |
| HR-04 | LaborRoleRate is versioned by effective date | Multiple rates per role; query picks latest before log date |
| HR-05 | LaborLog captures hourly_rate_at_log as snapshot | Set from current rate at creation; not updated if rate changes later |
| HR-06 | PayrollRun must pass through all states before Posted | State machine guards |
| HR-07 | Payslip net_pay = gross_pay - sum(deductions) | Validation before save |
| HR-08 | Payroll posting creates journal entries per unit | Observer on Posted transition |
| HR-09 | Leave requests require manager approval | Workflow with approver_id |
| HR-10 | Only HR role can create/edit employees; only HR + Accounting Manager can run payroll | Policy checks |
| HR-11 | Pay terms (pay type, monthly salary, personal hourly rate) are versioned by effective date and never edited in place; payroll resolves them per day and prorates a mid-month change | `employee_compensations` (append-only, unique per employee + date); `PayrollService::calculate`; changes via `POST /employees/{id}/compensations` (HR, accounting, owner), never dated before the hire date or into a period whose payroll left review; a change reaching a calculated run flags it (`needs_recalculation`) and the run cannot be submitted until recalculated; a mistaken non-opening version not covered by frozen payroll can be removed (`DELETE /employees/{id}/compensations/{compensationId}`); the opening version moves with a hire-date correction; the employee row's copy of today's terms is re-synced daily by `hr:sync-pay-terms`. Known limit: payroll does not yet read `termination_date` (an employee is paid while their status is active) |
| HR-12 | Salary advances and loans (decision #14) are paid from a treasury and recovered by payroll installments within company limits: one advance ≤ `advance_max_salary_multiple` × the monthly base salary in force (default 1, `ADVANCE_LIMIT_EXCEEDED`), and the employee's open monthly installments ≤ `advance_max_deduction_pct` % of it (default 50, `ADVANCE_DEDUCTION_LIMIT`) | `employee_advances` (requested → approved → paid → settled; cancelled before payout) + `advance_installments`; HR (manage-hr) requests, `approve-advances` (owner, accounting manager, HR manager) approves, pays out (DR 1215 employee / CR treasury) and takes cash repayments (DR treasury / CR 1215 employee, shortening the schedule from the end); `PayrollService::calculate` adds every pending installment due by the period as a locked `{type: advance, installment_id, locked: true}` deduction, capped by the percentage of net pay; `setDeductions` keeps the locked lines out of the editor's reach but measures the cap on net pay after the manual deductions (`advance_max_deduction_pct` % × (gross − manual deductions), never below zero net), lowering the locked lines when a manual deduction is added (restoring them when it is removed); posting credits them to 1215 per employee and marks them recovered; an unrecovered remainder stays pending and is due again next period. Guards: recovery cannot start in a month whose payroll has left review (`ADVANCE_START_PERIOD_CLOSED`); a payout moves the whole schedule forward to the first open period and flags every calculated run from that period on that has the employee's payslip (`needs_recalculation`, so it is recalculated before submission), so a late payout never makes two installments due at once; payout refuses a terminated or deleted employee (`ADVANCE_EMPLOYEE_INACTIVE`); payout and repayment use LYD treasuries only (`ADVANCE_TREASURY_NOT_LYD`); while an employee has an advance being recovered, a receipt voucher or a manual journal crediting them on 1215 may take only their balance outside the schedule (a W5-00 payment-voucher balance: posted 1215 balance − pending installments of their paid advances); more is refused (`ADVANCE_USE_REPAY`, use the repayment); a manual payslip deduction typed `advance` or `DED-ADV` is refused (`ADVANCE_DEDUCTION_MANUAL`), since recovery comes only from the schedule; reversing (`POST /journal-entries/{id}/reverse`) or cancelling (a treasury voucher) an entry of an advance payout or repayment, or of a payroll run that recovered installments, is refused (`ADVANCE_USE_ADVANCE_FLOW`: cancel the advance before payout, or repay); reversing or cancelling any other entry on an employee's 1215 may credit only their balance outside the schedule (`ADVANCE_USE_REPAY`), so the posted balance never drops below the pending installments; the employee's unit cannot change while an advance is open (`EMPLOYEE_HAS_OPEN_ADVANCE`); cancelling an approved advance needs an approver (`ADVANCE_CANCEL_FORBIDDEN`); posting a run whose installment changed since calculation is refused (`ADVANCE_RECALCULATE_REQUIRED`). Owner decisions to confirm: only employees on monthly pay terms are eligible (hourly and piece-rate have no monthly base to apply the limits to, `ADVANCE_NO_MONTHLY_SALARY`); cash repayments are recorded only by `approve-advances` holders (owner, accounting manager, HR manager), so a treasury officer who takes the cash hands it to one of them |
| HR-13 | Rewards and deductions (W5-03; FR-PAY-030/031, BR-PAY-010/011, FR-HR-006/014): standing pay items recur in every period they are in force, one-off adjustments apply to one period once approved; gross = base + attendance + additions, and each deduction is credited by its type's `fields.account_role` (`liability` → 225, `expense_reduction` → 57) | `employee_pay_items` (code from `allowance-types` for an addition or `deduction-types` for a deduction, `fixed` per period or `percent_of_base` of base + attendance pay, effective_from/to, active; fixed amounts are not prorated: an item covering any day of a month is in force for the whole month) via `/employees/{id}/pay-items`, written by the pay roles (HR-10, `PAY_ITEM_FORBIDDEN`); `employee_adjustments` (period, kind, code, amount, reason; draft → approved) via `/employee-adjustments`, HR enters drafts, `approve-pay-adjustments` (owner, accounting manager, HR manager) approves and removes an approved one (`PAY_ADJUSTMENT_DELETE_FORBIDDEN`), only a draft is edited (`PAY_ADJUSTMENT_NOT_DRAFT`); `PayrollService::calculate` writes `additions` / `additions_pay` and adds the deductions as locked lines `{type, source, pay_item_id or adjustment_id, account_role, locked: true}` the payslip editor keeps; an employee with no base or attendance pay in the period gets no payslip and no allowances; deductions above gross refuse the calculation (`PAYROLL_DEDUCTIONS_EXCEED_GROSS`, listing every such employee under `employees`; the way out for that period is to remove or lower the one-off deduction, or end the standing item on the last day of the previous month and add it again from the next month, or deactivate it for the calculation and reactivate it once the run has been submitted); an approved one-off adjustment of the period for an employee who gets no payslip (terminated, piece rate, or nothing earned) refuses the calculation (`PAYROLL_ADJUSTMENT_UNPAID`) instead of being dropped; for the HR-12 advance cap they count like manual deductions (cap = `advance_max_deduction_pct` % × (gross − standing/one-off − manual deductions)); a manual payslip line takes the role of the deduction type it names, any other type is a withholding (225); posting debits 57 with base + attendance and, separately, the additions, credits 225 the liability deductions and 57 the expense-reduction ones. Guards: codes must be active lookup entries (`PAY_CODE_UNKNOWN`) and never advance recovery (`ADVANCE_DEDUCTION_MANUAL`); a percentage is at most 100 (`PAY_ITEM_INVALID_VALUE`); a pay item cannot start in or before a period whose payroll left review (`PAY_ITEM_PERIOD_CLOSED`), and one that applied there keeps its terms and may only be ended on or after the first day of the latest frozen month (it still covers that month) or deactivated, never removed (`PAY_ITEM_FROZEN`): to change its terms, end it on the last day of the last month it should pay and add a new one from the next month; two active items of one employee and code may not share a month (`PAY_ITEM_OVERLAP`); items and adjustments are refused for a terminated or piece-rate employee (`PAY_EMPLOYEE_NOT_ON_PAYROLL`); an allowance or deduction type used by an item or adjustment cannot be deleted or have its code changed (`PAY_CODE_IN_USE`, deactivate it instead), and changing a deduction type's `account_role` flags every calculated run with a line of that type (`needs_recalculation`); no adjustment may be entered, moved into or approved for a period up to the latest one whose payroll left review, even one that never had a run, and an approved adjustment of a frozen period cannot be removed (`PAY_PERIOD_CLOSED`); a draft never reached a payslip, so it can always be removed or moved to an open period; a pay item change reaching a calculated run with the employee's payslip flags it (`needs_recalculation`), and approving or removing an approved adjustment flags the period's calculated run whether or not the employee has a payslip in it; submitting a run re-checks `PAYROLL_ADJUSTMENT_UNPAID`; the in-use check on a type matches its kind (an allowance and a deduction type may share a code) and is case-insensitive, so re-saving a code in another case is not a rename |

---

## 9.10 Unit Client Screens

### HR (HR Staff)
1. **Employee Directory:** List by unit; search; photo (optional)
2. **Employee Profile:** Personal info, job details, labor role, pay rate history
3. **Attendance Entry:** Daily grid — employees × dates; bulk mark present/absent
4. **Leave Calendar:** Approved leave visible; pending requests highlighted
5. **Payroll Run:** Open period → calculate → review → submit for approval
6. **Payslip View:** Individual payslip with breakdown

### Labor Logging (Workshop Staff / Labor Manager)
1. **Log Hours:** Select production order → select role → enter hours → submit
2. **My Logs:** View own logged hours by date/period
3. **Order Labor Summary:** Labor Manager views total hours per order

---

## 9.11 Testing Strategy

- **Unit tests:** Payroll calculation formulas, rate versioning query, deduction math
- **Feature tests:** Full payroll run lifecycle; labor log creation; leave request workflow
- **Integration tests:** Payroll Posted triggers correct journal entries; labor logs feed production costing

---

## 9.12 Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| Libyan statutory deductions unknown | High | Build extensible deduction schema; start with basic gross/net; add statutory later |
| Payroll calculation errors | High | Multiple review gates (HR → Accounting Manager); preview before approval |
| Attendance entry forgotten | Medium | Daily reminder; batch entry allowed; supervisor can backfill |
| Labor log accuracy | Medium | Labor Manager review required; cross-check against attendance hours |

---

## 9.13 Open Questions to Resolve

1. **HR scope:** Full payroll + attendance, or labor-costing logs only? (SRS Section 15, 18) — *This phase builds full payroll; if scope is reduced, defer payslip/journal posting.*
2. **Libyan statutory requirements:** Social security contributions, minimum wage, mandatory leave — *Requires local HR/legal input before v1 launch.*

---

## 9.14 Phase Exit Criteria

- [ ] Employees can be created and managed per unit
- [ ] Attendance records feed into payroll calculations
- [ ] Labor logs capture hours with rate snapshots
- [ ] Payroll run completes full lifecycle from Draft → Posted
- [ ] Payslips calculate gross, deductions, and net correctly
- [ ] Payroll posting creates correct wage-expense journal entries
- [ ] Leave requests route through approval workflow
- [ ] Unit client HR and labor logging screens functional in Arabic/RTL
