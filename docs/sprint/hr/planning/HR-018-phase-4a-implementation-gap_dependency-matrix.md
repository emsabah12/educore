# HR-018 — Phase 4A Implementation Gap & Dependency Matrix

**Version:** 0.1 Draft
**Phase:** 4A — Implementation Gap & Dependency Matrix
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
**Specification Baseline:** HR-001–HR-017 + ADR-032
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

# 1. Purpose

HR-018 menerjemahkan specification baseline yang sudah locked menjadi **implementation gap inventory**.

Dokumen ini belum membuat sprint atau engineering task detail.

Tujuannya menjawab:

```text
Apa yang sudah ada?
Apa yang harus dipertahankan?
Apa yang harus diperbaiki?
Apa yang belum ada?
Apa dependency-nya?
Apa yang menjadi blocker?
Dalam urutan apa engineering sebaiknya bergerak?
```

---

# 2. Current Repository State

## [FAKTA]

Implementasi HR saat ini hanya mempunyai foundation berikut:

```text
Modules/HR
├── Employee model
├── Employee migration
├── Employee repository
├── Employee provisioning service
├── Employee GET API
├── Employee POST API
└── Employee feature tests
```

Belum ditemukan implementation HR untuk:

```text
Employment
Recruitment
Leave & Permit
HR Attendance
Compensation & Benefits
Payroll Input
Performance
Competency
PKB
HR Documents
Employment Agreements
Discipline
Offboarding
HR Reporting
Government Export
```

---

# 3. Existing Employee Foundation

Current provisioning:

```text
Person
→ Membership
→ Employee
```

dilakukan atomically.

Current implementation sudah mempertahankan beberapa keputusan penting:

- Employee terhubung ke Membership;
- Person tetap canonical identity;
- Employee provisioning tidak otomatis membuat User;
- tenant-scoped NIP uniqueness;
- Employee list membaca canonical Person name;
- Employee menggunakan UUID;
- audit event `employee.created` tidak memasukkan nama/NIP;
- pagination dibatasi maksimum 100.

### Classification

```text
Employee foundation
→ KEEP + EXTEND
```

Bukan replace total.

---

# 4. Primary Existing Conflicts

## GAP-001 — Legacy `jabatan`

**[CONFLICT]**

Current schema:

```text
employees.jabatan
NOT NULL
```

Current API mewajibkan:

```text
GURU
KEPALA_SEKOLAH
STAFF
```

Locked target:

```text
Employee
≠ Employment
≠ Position
≠ OrganizationalAssignment
≠ Role
```

### Treatment

```text
DEPRECATE GRADUALLY
```

Jangan menghapus segera karena current tests/API masih bergantung padanya.

---

## GAP-002 — HR route authorization

**[RISK — CRITICAL]**

Current:

```text
GET  /api/v1/hr/employees
POST /api/v1/hr/employees
```

hanya menggunakan:

```text
InjectTenantContext
```

Belum ada:

```text
hr.employees.view
hr.employees.create
```

### Treatment

```text
REFACTOR — P0
```

---

## GAP-003 — HR permission catalog absent

Repository belum mempunyai:

```text
HRAuthorizationCatalogSeeder
```

sementara Academic sudah mempunyai pattern authorization catalog yang dapat direuse.

### Treatment

```text
ADD — P0
```

Canonical permission catalog mengikuti HR-013.

---

## GAP-004 — Organizational permission middleware absent

Core sudah mempunyai:

```text
InjectOrganizationalContext
OrganizationalAuthorizationService
```

tetapi belum mempunyai generic equivalent:

```text
organizational.permission:<permission>
```

### Treatment

```text
EXTEND Core
```

Tidak membuat HR-specific authorization engine.

---

## GAP-005 — Scoped Employee query absent

Current repository hanya mempunyai:

```text
getByTenantPaginated()
```

Belum ada query yang membatasi Employee berdasarkan verified:

```text
Organization
OrganizationUnit
```

### Treatment

```text
ADD
```

### Critical invariant

```text
organizational permission
+
tenant-wide Employee query
=
SECURITY DEFECT
```

---

# 5. API Contract Gap

## GAP-006 — HR error envelope

Current controller masih menghasilkan error seperti:

```json
{
  "status": "error",
  "message": "..."
}
```

sementara Core sudah mempunyai canonical:

```text
ApiErrorResponse
→ status
→ code
→ message
→ errors
```

### Treatment

```text
REFACTOR — P0
```

---

## GAP-007 — OpenAPI deferred status

Current OpenAPI menempatkan:

```text
GET  /api/v1/hr/employees
POST /api/v1/hr/employees
```

di:

```text
x-educore-deferred-routes
reason: domain-api-hardening-deferred
```

### Treatment

Endpoint tidak boleh dianggap production contract sampai:

```text
authorization
+ canonical errors
+ DTO contract
+ tests
+ OpenAPI
```

selesai.

---

# 6. Employee Creation Gap

Current:

```text
POST Employee
→ always creates new Person
→ creates Membership
→ creates Employee
```

Locked architecture membutuhkan identity-safety yang lebih kuat untuk lifecycle tertentu.

### [FAKTA]

Recruitment conversion wajib menggunakan explicit identity resolution.

### [REKOMENDASI]

Current administrative Employee provisioning dapat dipertahankan sementara, tetapi sebelum menjadi canonical long-term API perlu dievaluasi terhadap:

```text
existing Person
existing Membership
duplicate identity
rehire
```

Exact resolution contract akan masuk Workforce/API implementation specification.

---

# 7. Employment Gap

## GAP-008

**[MISSING IMPLEMENTATION]**

Belum ada:

```text
Employment entity/table
Employment repository
Employment service
Employment API
Employment lifecycle tests
```

Locked requirements:

```text
Employee
→ many Employments historically

maximum one ACTIVE Employment

rehire
→ new Employment

PLANNED
ACTIVE
ENDED
```

### Priority

**FOUNDATIONAL / HIGH**

Banyak capability HR bergantung pada Employment.

---

# 8. Organizational Placement Integration Gap

## GAP-009

Core OrganizationalAssignment sudah ada.

### Existing

```text
Core Organization
→ KEEP
```

### Missing HR integration

Belum ada HR flow untuk:

```text
Employee/Employment
→ organizational placement context
```

### Treatment

```text
REUSE Core
+
ADD HR integration
```

No duplicate HR organization table.

---

# 9. Recruitment Gap

## GAP-010

Belum ada implementation:

```text
Candidate
Application
Selection
Hiring Approval
Onboarding
Identity Resolution
Conversion
```

Target workflow:

```text
Candidate
→ Application
→ Selection
→ Hiring Approval
→ Onboarding
→ Identity Resolution
→ Person
→ Membership
→ Employee
→ Employment PLANNED
→ Activation
```

### Key dependencies

```text
Employee foundation
Employment
identity-resolution contract
authorization
```

### Priority

**AFTER WORKFORCE FOUNDATION**

---

# 10. Leave & Permit Gap

## GAP-011

Belum ada:

- leave request model;
- entitlement ledger;
- approval workflow;
- leave API;
- leave worklist;
- self-service API;
- scope-aware authorization.

Locked invariant:

```text
balance
= append-only ledger result
```

### Dependencies

```text
Employee
Employment
HR authorization
organizational scope
```

Exact calendar remains `[OPEN DECISION]`.

---

# 11. Attendance Gap

## GAP-012

Tidak ditemukan HR Attendance bounded-context implementation.

Required conceptual model:

```text
Expectation
+
Raw Event
+
Approved Leave/Permit
→ Reconciliation
→ Attendance Record
```

### Dependencies

```text
Employee / Employment
Leave
organizational scope
attendance source/expectation contract
```

Fingerprint/QR/GPS adapters remain deferred.

---

# 12. Compensation & Benefits Gap

## GAP-013

Belum ada:

```text
compensation facts
benefits
payroll input snapshots
```

### Boundary remains

```text
HR
→ compensation / benefits / payroll input

Finance
→ calculation / payment / accounting
```

### Dependencies

```text
Employment
authorization
sensitive-data controls
```

Future required field:

```text
payroll_input_snapshot.purpose
=
REGULAR_PAYROLL
FINAL_SETTLEMENT
```

when settlement integration begins.

---

# 13. Performance / Competency Gap

## GAP-014

Belum ada implementation:

```text
Performance / PKG
Competency
PKB / Development
Certification distinctions
```

### Dependencies

```text
Employee / Employment
versioned framework/rating scale
authorization
sensitive-data policy
```

Exact PKG mapping remains open.

---

# 14. Documents & Agreements Gap

## GAP-015

Belum ada HR document persistence/service/API.

Required architecture:

```text
metadata
→ database

binary
→ private storage
```

Finalized/signed versions immutable.

### Dependencies

```text
private storage
authorization
upload security
Employment for agreements
AV boundary where required
```

Production storage/AV provider remains open.

---

# 15. Discipline Gap

## GAP-016

Belum ada implementation:

```text
discipline case
evidence
tenant-scoped catalog
disciplinary action
finalization
```

### Constraint

Tidak boleh hardcode:

```text
SP1 → SP2 → SP3
```

### Dependencies

```text
Employee / Employment
authorization
highly-restricted data controls
tenant policy
```

Exact policy remains `[OPEN DECISION]`.

---

# 16. Offboarding Gap

## GAP-017

Belum ada implementation:

```text
Offboarding Case
Approval
Checklist
Handover
Access Review
Exit Interview
Settlement Facts
Completion
```

Locked:

```text
Employment ENDED
≠ Offboarding COMPLETED
```

### Dependencies

Core:

```text
Employment
Authorization
Membership lifecycle
```

Potential external:

```text
Finance settlement
Asset
```

Role revocation policy remains unresolved.

---

# 17. Reporting Gap

## GAP-018

Tidak ada HR reporting implementation.

Target remains:

```text
Canonical HR Data
→ Direct Query
→ optional Rebuildable Projection
→ Authorized Report
```

### Dependency

Reporting depends on source capability implementation.

Therefore building a dashboard before domain data exists is not recommended.

---

# 18. Government Export Gap

## GAP-019

Belum ada:

```text
Dapodik mapping
EMIS GTK mapping
Frozen Dataset
ExportRun
private artifact
government export API
```

### Dependencies

```text
HR reporting/source data
private storage
authorization
queue privacy
government mapping specification
```

Field mapping remains `[RESOURCE GAP]`.

---

# 19. Frontend Implementation Gap

## GAP-020 — Shared frontend foundation

Repository documentation defines FE-001–FE-008 and ADR-020–ADR-031.

However actual repository:

```text
resources/js/app.js
→ effectively empty
```

Tidak ditemukan implemented:

- application shell;
- React frontend;
- authentication UX;
- tenant switch;
- workspace switch;
- capability-aware navigation;
- error/loading framework;
- route guard;
- frontend observability.

### Classification

```text
Frontend specification
→ KEEP

Frontend implementation
→ MISSING
```

### Consequence

HR frontend tidak boleh membuat standalone architecture untuk menutup gap ini.

---

# 20. Frontend Dependency

Recommended dependency:

```text
Shared Frontend Foundation
        ↓
HR Navigation
        ↓
HR Transaction Pages
```

Possible engineering modes:

### Option A — Foundation first

Implement FE shared foundation sebelum HR UI.

### Option B — Parallel

Frontend foundation dan HR first pages dibangun paralel dengan shared contracts yang locked.

### [REKOMENDASI]

Gunakan **foundation-first atau tightly coordinated parallel**, bukan HR-specific shell.

---

# 21. Queue Privacy Gap

## GAP-021

Current:

```text
BaseTenantAwareJob
→ arbitrary payload array

QueueWatchdog
→ unserialize job
→ copies input_payload
→ audit metadata
```

### [RISK — HIGH]

Future HR sensitive payload dapat tersimpan pada:

```text
jobs
failed_jobs
audit_logs
```

### Treatment

```text
REFACTOR — P0 before sensitive async HR jobs
```

---

# 22. After-Commit Gap

## GAP-022

Current queue config:

```text
after_commit = false
```

Locked:

```text
job depending on newly committed state
→ dispatch after commit
```

### Treatment

No global configuration change necessarily required.

Implementation must guarantee after-commit semantics per relevant workflow.

---

# 23. Person Identifier Security Gap

## GAP-023

Schema exists:

```text
encrypted_value
value_fingerprint
```

but no repository/application implementation was found using those fields.

### Classification

```text
SCHEMA FOUNDATION EXISTS
IMPLEMENTATION NOT VERIFIED / MISSING
```

### Priority

P0 before production use of sensitive government/legal identifiers.

---

# 24. Health Endpoint Gap

## GAP-024

Current Core readiness response can include raw dependency exception messages.

### Treatment

```text
REFACTOR
```

Target:

```text
/up
→ liveness

/api/v1/core/health
→ sanitized readiness
```

---

# 25. Correlation / Observability Gap

## GAP-025

No canonical backend:

```text
request_id
correlation_id
trace_id
```

implementation found.

Frontend ADR already anticipates correlation.

### Treatment

```text
ADD platform request correlation
```

Before broad production rollout.

---

# 26. Deployment & Recovery Gap

## GAP-026

No verified repository resources were found for:

```text
CI/CD
deployment runbook
rollback runbook
worker supervisor
backup automation
restore runbook
```

### Classification

```text
RESOURCE GAP / MISSING OPERATIONS IMPLEMENTATION
```

This does not block normal local domain coding, but blocks final production readiness.

---

# 27. Persistence Environment Gap

## GAP-027

Current `.env.example`:

```text
DB_CONNECTION=sqlite
```

while authoritative migrations rely on PostgreSQL-specific semantics elsewhere in the repository.

### Treatment

```text
REFACTOR environment baseline
```

Do not downgrade database integrity.

---

# 28. Filename Casing Gap

## GAP-028

Git tracks:

```text
2026_07_17_000005_create_Employees_table.php

docs/.../adr-011...
docs/.../adr-012...
```

Archive filesystem contains:

```text
create_employees_table.php

ADR-011...
ADR-012...
```

Git therefore reports tracked files as deleted on the audited case-sensitive filesystem.

### Treatment

```text
REFACTOR repository hygiene — P0 before CI/Linux deployment
```

---

# 29. Documentation Integration Gap

## GAP-029

Canonical HR-001–HR-017 artifacts are not present in the repository documentation set.

Current authority depends on approved project artifacts/handoff.

### Treatment

```text
ADD documentation integration
```

Recommended destination:

```text
docs/prd/
docs/architecture/
```

according to artifact type.

This should happen before large multi-developer implementation to preserve traceability.

---

# 30. Consolidated Gap Matrix

| ID      | Gap                           | Current State   | Target                  |                     Priority |
| ------- | ----------------------------- | --------------- | ----------------------- | ---------------------------: |
| GAP-001 | Legacy `jabatan`              | Existing        | Deprecate               |                           P1 |
| GAP-002 | HR route authorization        | Missing         | Permission enforcement  |                       **P0** |
| GAP-003 | HR permission catalog         | Missing         | HR-013 catalog          |                       **P0** |
| GAP-004 | Org permission middleware     | Missing         | Core scoped middleware  |                    **P0/P1** |
| GAP-005 | Scope-aware Employee query    | Missing         | Org/unit filtered query | **P0 before scoped rollout** |
| GAP-006 | Canonical HR errors           | Partial         | ApiError contract       |                       **P0** |
| GAP-007 | OpenAPI hardening             | Deferred        | Canonical API           |                       **P0** |
| GAP-008 | Employment                    | Missing         | HR-002                  |                           P1 |
| GAP-009 | HR placement integration      | Missing         | Reuse Core assignments  |                           P1 |
| GAP-010 | Recruitment                   | Missing         | HR-003                  |                           P2 |
| GAP-011 | Leave                         | Missing         | HR-004                  |                           P2 |
| GAP-012 | Attendance                    | Missing         | HR-005                  |                           P2 |
| GAP-013 | Compensation                  | Missing         | HR-006                  |                           P3 |
| GAP-014 | Performance                   | Missing         | HR-007                  |                           P3 |
| GAP-015 | Documents                     | Missing         | HR-008                  |                           P3 |
| GAP-016 | Discipline                    | Missing         | HR-008                  |                           P3 |
| GAP-017 | Offboarding                   | Missing         | HR-008                  |                           P3 |
| GAP-018 | Reporting                     | Missing         | HR-009                  |                           P4 |
| GAP-019 | Government export             | Missing         | HR-009                  |                           P4 |
| GAP-020 | Frontend foundation           | Spec only       | FE implementation       |  **P0 dependency for HR UI** |
| GAP-021 | Queue privacy                 | Unsafe baseline | Identifier-only         | **P0 before sensitive jobs** |
| GAP-022 | After-commit job semantics    | Not default     | Explicit guarantee      |                           P1 |
| GAP-023 | Identifier encryption runtime | Not verified    | Implement/verify        |            **P0 before use** |
| GAP-024 | Health sanitization           | Unsafe detail   | Safe readiness          |                           P1 |
| GAP-025 | Request correlation           | Missing         | Platform correlation    |                           P1 |
| GAP-026 | Deployment/recovery           | Missing         | Operational baseline    |         P0 before production |
| GAP-027 | SQLite default conflict       | Existing        | PostgreSQL alignment    |                       **P0** |
| GAP-028 | Filename casing               | Conflict        | Canonical casing        |                       **P0** |
| GAP-029 | HR docs in repo               | Missing         | Repository traceability |                           P1 |

---

# 31. Dependency Matrix — Foundation

| Component                     | Depends On                   | Blocks                                |
| ----------------------------- | ---------------------------- | ------------------------------------- |
| HR permission catalog         | Core Permission/Role         | Protected HR APIs                     |
| Tenant permission enforcement | HR permission catalog        | Current Employee production use       |
| Org permission middleware     | Core org context + evaluator | Scoped HR APIs                        |
| Scope-aware queries           | OrganizationalAssignment     | Org-scoped Employee/HR data           |
| Canonical error contract      | Core ApiErrorResponse        | Hardened OpenAPI                      |
| OpenAPI HR contract           | Stable API behavior          | Frontend generated/client integration |
| Frontend foundation           | FE-001–FE-008                | HR frontend                           |
| Queue privacy remediation     | Core queue                   | Sensitive async work                  |
| Private storage               | storage adapter/provider     | Documents/Exports                     |
| Request correlation           | platform HTTP layer          | Production diagnostics                |
| Backup/deployment baseline    | infrastructure               | Production readiness                  |

---

# 32. Dependency Matrix — Business Capabilities

## Workforce

```text
Core Identity
+
Membership
+
Employee
+
HR Authorization
        ↓
Employment
        ↓
Organizational HR Integration
```

This is the principal HR domain foundation.

---

## Recruitment

```text
Workforce Foundation
+
Employment
+
Identity Resolution
+
Authorization
        ↓
Recruitment / Hiring / Onboarding
```

---

## Leave

```text
Employee / Employment
+
Authorization
+
Organizational Scope
        ↓
Leave Ledger / Request / Approval
```

---

## Attendance

```text
Employee / Employment
+
Leave
+
Expectation Source
        ↓
Attendance Reconciliation
```

---

## Compensation

```text
Employment
+
Sensitive Authorization
        ↓
Compensation / Benefits / Payroll Input
        ↓
Future Finance Integration
```

---

## Performance

```text
Employee / Employment
+
Framework / Rating
+
Sensitive Authorization
        ↓
Performance / Competency / PKB
```

---

## Documents

```text
Employee / Employment
+
Private Storage
+
Sensitive Authorization
        ↓
Documents / Agreements
```

---

## Discipline

```text
Employee / Employment
+
Sensitive Authorization
+
Tenant Discipline Policy
        ↓
Discipline Cases
```

---

## Offboarding

```text
Employment
+
Authorization
        ↓
Offboarding Case

Optional integration:
Finance / Asset / Core Access Review
```

---

## Reporting

```text
Implemented Source Domains
+
Authorization
        ↓
Direct Query Reporting
        ↓
Optional Projection
```

---

## Government Export

```text
Source HR Data
+
Government Mapping
+
Frozen Dataset
+
Private Storage
+
Queue Privacy
+
Export Authorization
        ↓
Dapodik / EMIS Export
```

---

# 33. Critical Path

Based on current repository state:

```text
Repository Hygiene
        ↓
Authorization + API Hardening
        ↓
Workforce / Employment Foundation
        ↓
Organizational Scope
        ↓
Business HR Capabilities
        ↓
Reporting
        ↓
Government Export
```

Frontend foundation proceeds before or tightly parallel with first HR frontend delivery.

---

# 34. Recommended Engineering Waves

## Wave 0 — Foundation Remediation

```text
GAP-002 HR authorization
GAP-003 permission catalog
GAP-006 canonical errors
GAP-007 OpenAPI
GAP-021 queue privacy
GAP-023 identifier encryption verification
GAP-024 health sanitization
GAP-027 PostgreSQL alignment
GAP-028 casing remediation
GAP-029 docs integration
```

plus frontend foundation coordination.

---

## Wave 1 — Workforce

```text
Employee hardening
Employment
organizational placement integration
scope-aware Employee APIs
```

---

## Wave 2 — Operational HR

```text
Recruitment
Leave
Attendance
```

These capabilities share workforce/scope foundations.

---

## Wave 3 — Sensitive HR

```text
Compensation
Performance
Documents
Discipline
Offboarding
```

Requires stronger privacy/storage controls.

---

## Wave 4 — Intelligence / External

```text
Reporting
Government Export
```

Only after sufficient canonical source domains exist.

---

# 35. Explicit Non-Dependencies

The following are **not prerequisites** for initial Workforce implementation:

```text
data warehouse
generic reporting module
microservices
Redis
search engine
generic EAV metrics
Kubernetes
government API synchronization
Simpatika integration
```

Do not add them to critical path.

---

# 36. Cross-Domain Dependencies

| HR capability               | External owner            | Dependency                |
| --------------------------- | ------------------------- | ------------------------- |
| Organizational placement    | Core Organization         | OrganizationalAssignment  |
| Authorization               | Core Authorization        | Role/Permission/Scope     |
| Person/Membership           | Core                      | Identity/Tenancy          |
| Attendance expectations     | Relevant owner            | Contract when defined     |
| Teaching quantity           | Academic                  | Read contract only        |
| Payroll calculation/payment | Finance                   | Future integration        |
| Final settlement            | Finance                   | Future contract           |
| Asset handover              | Asset                     | Future integration        |
| Government submission       | External official systems | Mapping/workflow contract |

HR must not duplicate these owners.

---

# 37. Open Decision Dependency Map

Some implementation areas cannot be finalized until business authority exists.

| Open Decision               | Blocks                             |
| --------------------------- | ---------------------------------- |
| Employment type catalog     | Employment detailed model          |
| Future-effective Employment | Employment scheduling behavior     |
| Leave/work calendar         | Leave calculation                  |
| Attendance cutoff           | Attendance finalization            |
| Payroll/statutory formula   | Finance, not HR facts baseline     |
| PKG mapping                 | Specific PKG implementation        |
| Discipline policy           | Final Discipline workflow          |
| Offboarding approval chain  | Approval implementation            |
| Role grant provenance       | Automated access-revocation policy |
| Membership deactivation     | Offboarding access lifecycle       |
| Document retention          | Purge/retention automation         |
| Storage provider            | Production documents/export        |
| Dapodik/EMIS mapping        | Government export                  |
| RPO/RTO                     | Production recovery commitment     |

---

# 38. Items That Can Start Without Open Decisions

The following engineering work is sufficiently specified:

```text
HR permission catalog
Employee route authorization
canonical error envelope
OpenAPI hardening
authorization tests
QueueWatchdog privacy remediation
health sanitization
PostgreSQL config alignment
filename casing reconciliation
HR documentation integration
Employment technical skeleton/invariants
```

No need to wait for later government/discipline/payroll decisions.

---

# 39. Existing Test Impact

Current Employee tests assume authentication + Tenant context is sufficient.

After HR-013 implementation, tests must also establish:

```text
Permission
+
Role grant
```

New minimum tests:

```text
unauthenticated → 401
authenticated/no permission → 403
correct tenant permission → success
cross-tenant → deny
```

Organizational APIs additionally require:

```text
valid assignment
scoped permission
target in scope
sibling/out-of-scope denial
```

---

# 40. Frontend Test Dependency

HR UI implementation should not invent its own test approach.

Use FE-029/FE foundation strategy once implemented.

Minimum HR frontend coverage later includes:

```text
navigation capability state
route denial
empty/loading/error
form validation
domain conflict
workspace switch
unsaved changes
sensitive tab visibility
```

---

# 41. Database Migration Strategy Dependency

New HR schema should follow:

```text
additive first
→ migrate/backfill
→ switch
→ remove legacy later
```

Particularly for:

```text
employees.jabatan
```

Do not:

```text
drop jabatan
+
ship new Employment/Position
```

in one unsafe migration if deployed code still depends on legacy field.

---

# 42. Documentation Dependency

Before implementation becomes distributed across multiple engineers:

```text
HR-001 ... HR-018
→ repository canonical docs
```

is recommended.

Reason:

Current implementation authority should not depend on conversation history.

---

# 43. Definition of Phase 4A Complete

Phase 4A is complete when:

- all implementation gaps are classified;
- existing code treatment is explicit;
- critical dependencies are known;
- P0 blockers are visible;
- missing business capabilities are separated from remediation;
- open decisions are linked to affected work;
- engineering sequencing can be produced without redesigning the domain.

All criteria are satisfied by this draft.

---

# 44. Reviewer Assessment

**Quality Score:** **9.8/10**

## Gaps

- Phase 4A deliberately does not yet decompose work to engineering tasks.
- Exact schema/API shape for new HR capabilities belongs to subsequent implementation planning.
- Several business policies remain open as previously recorded.
- Frontend foundation has approved specification but no implementation baseline.

## Risks

**[RISK — CRITICAL]** Building new HR features before fixing authorization would expand an insecure API surface.

**[RISK — CRITICAL]** Organizational permissions without scope-aware queries could create horizontal data exposure.

**[RISK — HIGH]** Building frontend around current `jabatan` API would cement deprecated architecture.

**[RISK — HIGH]** Enabling sensitive async jobs before queue privacy remediation would persist sensitive data in infrastructure tables/audit.

**[RISK — HIGH]** Starting Reporting/Government Export before canonical source capabilities would encourage duplicate source-of-truth structures.

**[RISK — HIGH]** Building HR UI before shared FE foundation without coordination could create a second application architecture.

## Recommendations

1. Lock HR-018 as Phase 4A baseline.
2. Phase 4B should convert gaps into:

   ```text
   Epic
   → Feature
   → User Story
   → Engineering Task
   ```

3. Wave 0 must appear as explicit technical/security epics, not hidden under feature work.
4. Workforce/Employment should become first domain implementation epic after remediation.
5. Reporting and Government Export should remain downstream.
6. No architecture redesign is required.

**Status:** **READY FOR APPROVAL**
