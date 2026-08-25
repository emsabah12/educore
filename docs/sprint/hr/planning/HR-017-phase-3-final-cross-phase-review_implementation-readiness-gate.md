# HR-017 — Phase 3 Final Cross-Phase Review & Implementation Readiness Gate

**Version:** 0.1 Draft
**Phase:** 3H — Final Cross-Phase Review
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

# 1. Executive Conclusion

Phase 3 telah berhasil mendefinisikan target HR untuk:

```text
Information Architecture
→ Transaction UX
→ State & Recovery UX
→ Authorization
→ Security & Privacy
→ Performance & Recovery
→ Operational Deployment
```

Cross-phase review tidak menemukan requirement conflict yang membatalkan HR-001–HR-016.

Kesimpulan:

```text
HR Product / Architecture Specification
→ STABLE

Phase 3 Specification
→ READY TO CLOSE

Current Implementation
→ NOT YET PRODUCTION READY

Engineering Planning
→ READY TO START
```

---

# 2. Phase 3 Artifact Status

| Artifact   | Area                                  | Final Status           |
| ---------- | ------------------------------------- | ---------------------- |
| **HR-010** | Information Architecture & Navigation | **APPROVED / LOCKED**  |
| **HR-011** | Transaction UI/UX                     | **APPROVED / LOCKED**  |
| **HR-012** | Loading / Empty / Error / Recovery    | **APPROVED / LOCKED**  |
| **HR-013** | Authorization Matrix                  | **APPROVED / LOCKED**  |
| **HR-014** | Security / Privacy / Retention        | **APPROVED / LOCKED**  |
| **HR-015** | Performance / Scalability / Backup    | **APPROVED / LOCKED**  |
| **HR-016** | Logging / Monitoring / Deployment     | **APPROVED / LOCKED**  |
| **HR-017** | Final Cross-Phase Review              | **DRAFT FOR APPROVAL** |

---

# 3. Cross-Phase Consistency Review

## 3.1 Identity

Locked canonical chain remains:

```text
Person
→ Membership
→ Employee
→ Employment
```

Phase 3 does not introduce duplicate identity.

**Status:** CONSISTENT

---

## 3.2 Position vs Authorization

All Phase 3 artifacts consistently maintain:

```text
Position
≠ Role
≠ Permission
```

Legacy:

```text
employees.jabatan
```

remains **DEPRECATE GRADUALLY** and is never treated as authorization authority.

**Status:** CONSISTENT

---

## 3.3 Organizational Placement

Organizational placement remains owned by Core:

```text
OrganizationalAssignment
```

HR:

- consumes placement;
- uses it for scope;
- does not duplicate organizational ownership.

**Status:** CONSISTENT

---

## 3.4 Authorization

Unified model:

```text
Authentication
+
Tenant
+
Permission
+
Verified Organizational Scope
+
Target Resource Scope
+
Business State
+
Sensitivity Policy
```

Frontend capability projection remains UX-only.

**Status:** CONSISTENT

---

## 3.5 HR vs Finance

All artifacts preserve:

```text
HR
→ compensation / benefit / payroll-input facts

Finance
→ payroll calculation
→ payment
→ accounting
```

No Phase 3 requirement moves payroll ownership into HR.

**Status:** CONSISTENT

---

## 3.6 Attendance

Phase 3 consistently maintains:

```text
Raw Event
≠ Reconciliation
≠ Final Attendance Fact
```

No UI/error/report requirement turns raw attendance evidence into canonical attendance automatically.

**Status:** CONSISTENT

---

## 3.7 Reporting

HR reporting remains:

```text
Modules/HR
```

with:

```text
Direct Query First
→ Projection only when justified
```

Projection remains rebuildable and noncanonical.

**Status:** CONSISTENT

---

## 3.8 Documents

All phases maintain:

```text
metadata → database
binary → private storage

finalized/signed version → immutable
```

No requirement introduces public file storage or DB BLOB.

**Status:** CONSISTENT

---

## 3.9 Offboarding

Locked distinction remains:

```text
Employment ENDED
≠ Offboarding COMPLETED
≠ Membership deactivated
```

Access review does not become automatic RBAC revocation.

**Status:** CONSISTENT

---

# 4. Repository Alignment

Current repository remains substantially behind target HR specification but does not contradict its architecture.

Classification:

| Existing Area                          | Final Classification                          |
| -------------------------------------- | --------------------------------------------- |
| `Modules/HR`                           | **KEEP + EXTEND**                             |
| Employee foundation                    | **KEEP + EXTEND**                             |
| Employee → Membership                  | **KEEP**                                      |
| Person provisioning                    | **KEEP**, later identity-resolution hardening |
| Core RBAC                              | **KEEP / REUSE**                              |
| Organizational authorization evaluator | **KEEP / REUSE**                              |
| Core OrganizationalAssignment          | **KEEP / REUSE**                              |
| Tenant capability projection           | **KEEP**                                      |
| Workspace capability projection        | **KEEP**                                      |
| `employees.jabatan`                    | **DEPRECATE GRADUALLY**                       |
| HR route authorization                 | **REFACTOR — P0**                             |
| HR error contract                      | **REFACTOR — P0**                             |
| HR OpenAPI coverage                    | **EXTEND — P0**                               |
| QueueWatchdog                          | **REFACTOR — P0 before sensitive jobs**       |
| Health endpoint detail                 | **REFACTOR**                                  |
| Backup/deployment operations           | **ADD**                                       |

---

# 5. Repository Conflicts Carried Forward

## [CONFLICT-01] SQLite default vs PostgreSQL schema

Current:

```text
.env.example
→ DB_CONNECTION=sqlite
```

but authoritative migrations use PostgreSQL-specific integrity semantics.

### Decision

```text
PostgreSQL integrity
→ KEEP

stale SQLite compatibility
→ must not drive schema downgrade
```

---

## [CONFLICT-02] Filename casing

Git/index and archive/filesystem still show casing inconsistencies including:

```text
create_Employees_table.php
vs
create_employees_table.php
```

and ADR casing differences.

### Risk

Linux/CI deployment failures.

### Required remediation

Case-sensitive CI and canonical filename reconciliation.

---

## [CONFLICT-03] HR API vs locked HR architecture

Current Employee POST still accepts:

```text
jabatan =
GURU
KEPALA_SEKOLAH
STAFF
```

Target architecture separates:

```text
Employee
Employment
Position
OrganizationalAssignment
Authorization
```

### Decision

Current API:

```text
KEEP intent
+
REFACTOR contract progressively
```

Do not design new frontend around legacy `jabatan`.

---

# 6. P0 — Current Employee API Production Blockers

Before current Employee API receives broader production exposure:

```text
P0-01
Create canonical HR permission catalog

P0-02
Protect GET /v1/hr/employees
with hr.employees.view

P0-03
Protect POST /v1/hr/employees
with hr.employees.create

P0-04
Add authorization regression tests

P0-05
Harden canonical ApiError envelope

P0-06
Move HR endpoints out of deferred OpenAPI state

P0-07
Add OpenAPI contract tests

P0-08
Ensure sensitive fields use purpose-specific DTOs
```

These are implementation prerequisites, not new product requirements.

---

# 7. P0 — Organizational HR Rollout Blockers

Before organizationally scoped HR access:

```text
P0-ORG-01
Generic organizational.permission middleware

P0-ORG-02
Scope-aware Employee queries

P0-ORG-03
Resource-scope authorization

P0-ORG-04
Organization / Unit isolation tests

P0-ORG-05
Sibling-unit denial tests

P0-ORG-06
Stale/inactive assignment tests
```

Critical invariant:

```text
Scoped permission
+
tenant-wide query
=
SECURITY DEFECT
```

---

# 8. P0 — Sensitive HR Workload Blockers

Before enabling sensitive documents, payroll facts, discipline, or government export asynchronously:

```text
P0-SEC-01
Identifier-only queue payload

P0-SEC-02
Refactor QueueWatchdog
to remove raw input_payload

P0-SEC-03
Verify Person identifier encryption implementation

P0-SEC-04
Private document storage

P0-SEC-05
Private export storage

P0-SEC-06
Allowlisted HR audit metadata

P0-SEC-07
Sensitive DTO disclosure tests
```

---

# 9. P0 — Operational Readiness Blockers

Before declaring HR production-ready:

```text
P0-OPS-01
Sanitize readiness endpoint

P0-OPS-02
Introduce backend request correlation

P0-OPS-03
Centralized production log collection

P0-OPS-04
Document backend deployment process

P0-OPS-05
Document worker restart/deployment process

P0-OPS-06
Document rollback / roll-forward decisions

P0-OPS-07
Implement backup strategy

P0-OPS-08
Test restore procedure

P0-OPS-09
Resolve production PostgreSQL configuration

P0-OPS-10
Resolve filename casing / case-sensitive CI
```

---

# 10. Production Readiness by Capability

Current planning should not assume all HR capabilities launch simultaneously.

Example readiness model:

| Capability         |        Can be delivered incrementally? |
| ------------------ | -------------------------------------: |
| Employee Directory |                                    Yes |
| Employment         |                                    Yes |
| Recruitment        |                                    Yes |
| Leave              |                                    Yes |
| Attendance         |                                    Yes |
| Compensation       |     Yes, stronger sensitivity controls |
| Performance        |                                    Yes |
| Documents          |          Yes, private storage required |
| Discipline         |        Yes, highly restricted controls |
| Offboarding        |                                    Yes |
| Reporting          |                                    Yes |
| Government Export  | Yes, stronger security/operations gate |

Therefore:

```text
HR Module deployed
≠ every HR capability production-enabled
```

---

# 11. Important Open Decisions

Open decisions do not invalidate Phase 3, but must be resolved at the appropriate implementation milestone.

## Business / Domain

- NIP policy;
- Employment Type/Classification;
- leave calendar;
- attendance cutoff/finalization;
- disciplinary policy;
- appeal workflow;
- offboarding approval chain;
- exit interview template.

## Authorization

- historical-record organizational ownership;
- Core permission for OrganizationalAssignment mutation;
- role-grant revocation policy;
- Membership deactivation policy;
- default HR role bundles;
- out-of-scope `403` vs `404`.

## Privacy / Security

- exact masking;
- privacy cohort threshold;
- retention durations;
- malware scanner;
- production storage;
- KMS/secrets provider;
- future biometric/location policy.

## Performance / Recovery

- backend latency SLA;
- expected workforce volume;
- large-export threshold;
- RPO;
- RTO;
- backup schedule/retention;
- restore test frequency;
- PITR;
- per-Tenant restore requirement.

## Operations

- centralized observability provider;
- request-correlation transport;
- alert thresholds;
- CI/CD provider;
- worker supervisor;
- deployment topology;
- rollout strategy.

---

# 12. Open Decisions Classification

Not every open decision blocks the first engineering sprint.

### BLOCKS specific implementation

Example:

```text
exact disciplinary policy
→ blocks discipline workflow implementation

storage provider
→ blocks production document infrastructure

RPO/RTO
→ blocks final production recovery commitment
```

### DOES NOT BLOCK foundation remediation

Examples:

```text
HR permission catalog
route authorization
ApiError hardening
QueueWatchdog remediation
case-sensitive CI
```

can begin without those later decisions.

---

# 13. Cross-Phase Traceability

```text
Business Objective
        ↓
HR-001
Product / Business Requirements
        ↓
ADR-032
Domain Boundary
        ↓
HR-002 ... HR-009
Domain Specifications
        ↓
HR-010
Information Architecture
        ↓
HR-011
Transaction UX
        ↓
HR-012
State / Recovery UX
        ↓
HR-013
Authorization
        ↓
HR-014
Security / Privacy
        ↓
HR-015
Performance / Recovery
        ↓
HR-016
Operational Readiness
        ↓
Implementation Backlog
        ↓
API / DB / UI / Tests
        ↓
Production Readiness Gate
```

Traceability remains coherent.

---

# 14. Recommended Implementation Sequencing

Phase 4 should **not** start by implementing every HR business feature.

Recommended sequencing:

```text
Wave 0 — Platform / Security Remediation
        ↓
Wave 1 — Workforce Foundation
        ↓
Wave 2 — Employment + Organizational Scope
        ↓
Wave 3 — Recruitment / Leave / Attendance
        ↓
Wave 4 — Compensation / Performance
        ↓
Wave 5 — Documents / Discipline / Offboarding
        ↓
Wave 6 — Reporting / Government Export
        ↓
Production Hardening per capability
```

Exact sprint numbers and dates belong to Phase 4 planning.

---

# 15. Wave 0 Recommendation

First engineering wave should prioritize existing-system risks:

```text
HR Authorization Catalog
HR Employee Route Enforcement
Canonical Error Contract
OpenAPI Hardening
Authorization Tests

Organizational Middleware Foundation
Scope-aware Query Foundation

Queue Privacy Remediation
Health Endpoint Hardening
Request Correlation

CI Case Sensitivity
PostgreSQL Environment Alignment
```

Reason:

These changes reduce security and operational risk before functionality expands.

---

# 16. Do Not Start With

Phase 4 should not begin with:

```text
full HR dashboard
government integration
generic reporting engine
advanced cache
search engine
data warehouse
microservices
large configurable workflow engine
```

because none of these addresses the highest current risk first.

---

# 17. Phase 3 Definition of Done Review

| Criterion                                 | Result                       |
| ----------------------------------------- | ---------------------------- |
| Information Architecture defined          | PASS                         |
| Transaction patterns defined              | PASS                         |
| Loading/error/recovery defined            | PASS                         |
| Authorization model defined               | PASS                         |
| Sensitive-data model defined              | PASS                         |
| Performance strategy defined              | PASS                         |
| Backup/recovery requirements defined      | PASS                         |
| Operational deployment principles defined | PASS                         |
| Existing implementation impact classified | PASS                         |
| Critical conflicts recorded               | PASS                         |
| Open decisions preserved as open          | PASS                         |
| Cross-domain ownership preserved          | PASS                         |
| Traceability established                  | PASS                         |
| Production implementation complete        | **NOT REQUIRED FOR PHASE 3** |

---

# 18. Final Risk Register

## [RISK — CRITICAL]

### R-001 — HR endpoint authorization

Current Employee API is not yet protected by canonical HR permission/scope enforcement.

---

## [RISK — CRITICAL]

### R-002 — Scoped permission + tenant-wide query

Future organizational role deployment without scoped repository query could expose unauthorized Employee data.

---

## [RISK — HIGH]

### R-003 — Queue payload privacy

Serialized queue payload + QueueWatchdog can persist/copy sensitive HR data.

---

## [RISK — HIGH]

### R-004 — Person identifier security verification

Encryption schema exists, but operational implementation is not yet verified.

---

## [RISK — HIGH]

### R-005 — Backup/restore not proven

No verified application backup/restore operational process is present.

---

## [RISK — HIGH]

### R-006 — Health endpoint information exposure

Dependency exception details can currently reach unauthenticated health response.

---

## [RISK — HIGH]

### R-007 — Migration/environment mismatch

SQLite default conflicts with PostgreSQL integrity requirements.

---

## [RISK]

### R-008 — Filename casing

Case mismatch can break Linux/CI deployment.

---

## [RISK]

### R-009 — Legacy `jabatan`

New implementation may accidentally couple frontend/business logic to deprecated position semantics.

---

# 19. Phase 3 Quality Review

Artifact scores:

```text
HR-010  9.6
HR-011  9.7
HR-012  9.7
HR-013  9.6
HR-014  9.7
HR-015  9.7
HR-016  9.7
```

Cross-phase assessment:

**Quality Score:** **9.7/10**

### Gaps

Gaps yang tersisa sudah teridentifikasi dan ditempatkan pada:

- implementation;
- policy decision;
- infrastructure;
- vendor selection;
- production operations.

Tidak ada hidden critical requirement gap yang memerlukan redesign Phase 1–3.

### Risks

Current implementation masih memiliki beberapa P0/P1 security dan operations remediation.

### Recommendations

1. Lock HR-017 dan tutup Phase 3.
2. Jangan mengubah HR-001–HR-016 selama Phase 4 kecuali melalui explicit change request.
3. Mulai Phase 4 dengan Wave 0 remediation.
4. Bangun backlog dengan traceability ke requirement ID.
5. Bedakan:
   - implementation prerequisite;
   - product feature;
   - policy decision;
   - infrastructure decision.

6. Production readiness dievaluasi per capability, bukan hanya karena module telah deployed.

---

# 20. Final Status

## Phase 3 Specification

**READY FOR APPROVAL / CLOSURE**

## Overall HR Specification HR-001–HR-016

**STABLE / READY FOR ENGINEERING PLANNING**

## Current Repository Implementation

**NOT READY FOR FULL HR PRODUCTION**

Reason:

```text
critical authorization
+
scope
+
privacy
+
backup
+
deployment controls
```

belum seluruhnya implemented.

## Architecture Redesign Required?

**NO**

## Foundation Remediation Required?

**YES**

---

# 21. Recommended Next Phase

Setelah HR-017 disetujui:

```text
PHASE 4
Engineering Implementation Readiness
& Project Management
```

Mulai dengan:

```text
4A
Implementation Gap & Dependency Matrix

4B
Epic → Feature → User Story → Engineering Task

4C
Technical Implementation Sequencing

4D
Migration / API / Frontend / Test Plan

4E
Risk-based Sprint Planning

4F
Definition of Ready / Definition of Done

4G
Release Milestones & Production Gates

4H
Final Engineering Handoff
```

Phase 4 tidak mendesain ulang domain.

Focus:

```text
Locked Specification
→ Executable Engineering Backlog
```
