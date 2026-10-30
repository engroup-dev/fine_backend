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
