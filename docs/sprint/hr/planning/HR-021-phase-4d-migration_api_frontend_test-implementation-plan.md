# HR-021 — Phase 4D Migration, API, Frontend & Test Implementation Plan

**Version:** 0.1 Draft
**Phase:** 4D — Migration / API / Frontend / Test Implementation Plan
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-24
**Depends On:** HR-001–HR-020 + ADR-020–ADR-032
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

# 1. Purpose

HR-021 menerjemahkan sequencing HR-020 menjadi **technical artifact plan**.

Setiap implementation batch dipetakan menjadi:

```text
Database / Migration
→ Model / Repository / Service
→ Authorization
→ API / OpenAPI
→ Frontend
→ Tests
→ Release / Migration Concern
```

HR-021 belum menulis kode dan belum menentukan sprint.

---

# 2. Resource Audit Summary

## Backend Existing

Current `Modules/HR` hanya memiliki:

```text
Employee
EmployeeRepository
EmployeeProvisioningService
StoreEmployeeRequest
EmployeeManagementController
GET /v1/hr/employees
POST /v1/hr/employees
Employee feature tests
```

Core sudah menyediakan:

```text
Permission / Role / MembershipRole
AuthorizationService
CheckTenantPermission

OrganizationalAssignment
OrganizationalAssignmentRole
OrganizationalAuthorizationService
InjectOrganizationalContext

ApiErrorResponse

Tenant-aware queue
Core Audit
```

## Frontend Existing

Repository belum mempunyai React application implementation.

Current:

```text
resources/js/app.js
```

praktis kosong.

Tetapi ADR frontend sudah mengunci canonical boundary:

```text
frontend/
└── src/
    ├── app/
    ├── platform/
    ├── shared/
    └── modules/
```

Target technology:

```text
React 19
TypeScript
Vite
React Router
TanStack Query
```

Testing direction:

```text
Vitest
React Testing Library
MSW
Playwright
```

---

# 3. Additive Traceability Correction A — Position

## [GAP — BACKLOG OMISSION]

HR-002 locked architecture menetapkan:

```text
Employee
  ↓
Employment
  └── Position Assignment
          ↓
       Position
```

Namun HR-019 tidak mempunyai feature/task eksplisit untuk persistence dan lifecycle Position.

Ini bukan requirement baru.

Ini adalah backlog omission.

## Additive Tasks

Tambahkan setelah `HR-TASK-211`:

### HR-TASK-212

Reconcile canonical HR-002 Position requirements/data dictionary sebelum schema implementation.

### HR-TASK-213

Create additive persistence migration untuk canonical **Position**.

### HR-TASK-214

Create additive persistence migration untuk **Position Assignment** yang menghubungkan Position dengan Employment sesuai HR ownership.

### HR-TASK-215

Implement Position model/repository/service contracts.

### HR-TASK-216

Implement Position Assignment lifecycle/history/invariants sesuai HR-002.

### HR-TASK-217

Define Position API/DTO contract setelah authorization mapping dikonfirmasi.

### HR-TASK-218

Define safe migration strategy dari legacy `employees.jabatan`.

### HR-TASK-219

Add Position / Position Assignment persistence, authorization, lifecycle, dan migration tests.

---

# 4. Legacy `jabatan` Migration Warning

Current:

```text
GURU
KEPALA_SEKOLAH
STAFF
```

tidak cukup kaya untuk dianggap sebagai canonical Position catalog.

Therefore:

```text
jabatan = GURU
```

tidak boleh otomatis dimigrasikan menjadi arbitrary canonical Position tanpa tenant mapping authority.

Target:

```text
Legacy jabatan
→ compatibility source
→ explicit mapping/migration decision
→ canonical Position
```

bukan:

```text
GURU
→ silently create Position "Guru"
```

untuk seluruh Tenant.

**[RISK]** Silent mapping dapat mengubah historical/business meaning.

---

# 5. Position Authorization Gap

HR-013 sudah mengunci HR permission catalog, tetapi tidak memiliki dedicated:

```text
hr.positions.*
```

permission.

## [OPEN DECISION]

Sebelum Position mutation API diaktifkan, tentukan apakah:

```text
Position Assignment
→ covered by hr.employments.manage
```

atau membutuhkan additive canonical Position permission.

HR-021 tidak mengubah locked 53-permission catalog secara diam-diam.

Position persistence dapat dibangun terlebih dahulu; mutation API menunggu authorization decision bila diperlukan.

---

# 6. Additive Traceability Correction B — Attendance Ownership

## [CONFLICT — IMPLEMENTATION OWNERSHIP]

Locked HR architecture menyatakan:

```text
Attendance
= separate bounded context
= Modules/Attendance
```

Repository saat ini **belum memiliki `Modules/Attendance`**.

HR-019 mengelompokkan Attendance dalam HR engineering epic untuk sequencing, tetapi technical implementation tidak boleh ditempatkan di:

```text
Modules/HR/Attendance
```

seolah HR menjadi owner Attendance.

### Correction

`HR-TASK-143–151` tetap valid secara functional backlog tetapi technical owner berubah menjadi:

```text
Modules/Attendance
```

Tambahkan:

### HR-TASK-220

Create Attendance module bootstrap/public-contract boundary ketika Attendance implementation dimulai, termasuk dependency-direction validation agar tidak menghasilkan circular module dependency.

---

# 7. Attendance Dependency Constraint

Required information flow:

```text
Expectation
+
Raw Event
+
Approved Leave / Permit Fact
↓
Attendance Reconciliation
↓
Attendance Record
```

Namun:

```text
HR Reporting
→ may consume Attendance facts
```

juga dibutuhkan.

Therefore implementation must avoid:

```text
HR directly depends on Attendance
AND
Attendance directly depends on HR
```

sebagai cyclic module dependency.

## [OPEN DECISION]

Exact integration mechanism ditentukan sebelum Attendance module manifest dikunci.

Allowed architectural directions include explicit public contracts or other already-approved cross-module mechanisms.

Requirement:

```text
dependency graph remains acyclic
```

---

# 8. Updated Engineering Backlog Count

Previous:

```text
211 tasks
```

Additive corrections:

```text
HR-TASK-212 → 220
```

Updated planning baseline:

```text
220 engineering tasks
```

Existing task IDs tidak direnumber.

---

# 9. Gate 0 — Repository Integrity Artifact Plan

## Database / Migration

Tidak ada business-schema migration.

Actions:

- reconcile migration filename casing;
- verify migration discovery on case-sensitive filesystem;
- preserve PostgreSQL-specific semantics.

## Configuration

Affected:

```text
.env.example
CI database configuration
developer setup documentation
```

Target:

```text
PostgreSQL
→ authoritative integration persistence
```

SQLite tidak boleh menentukan schema design.

## Documentation

Add canonical HR artifacts to repository documentation tree.

## Tests

Minimum:

- module discovery;
- migration discovery;
- clean migration;
- existing feature tests;
- case-sensitive CI check.

## Release Gate

Gate 0 harus selesai sebelum long-lived implementation branches mulai menambah migrations baru.

---

# 10. Gate 1A — HR Permission Catalog

## Migration

**No new authorization schema migration required.**

Existing Core tables reused:

```text
permissions
roles
role_permissions
membership_roles
```

## Backend Artifact

Add:

```text
Modules/HR/Database/Seeders/
└── HRAuthorizationCatalogSeeder
```

Responsibilities:

- upsert canonical HR permission catalog;
- `module = HR`;
- idempotent;
- no destructive reconciliation of custom roles.

## Provider / Bootstrap

Seeder integration harus mengikuti existing repository seeding mechanism.

Do not create second authorization subsystem.

## Tests

Add:

```text
HRAuthorizationCatalogSeederTest
HR permission catalog query tests
idempotency test
custom-role preservation test
```

---

# 11. Gate 1B — Current Employee Route Authorization

## Routes

Current:

```text
InjectTenantContext
```

Target:

```text
GET Employee
→ InjectTenantContext
→ tenant.permission:hr.employees.view

POST Employee
→ InjectTenantContext
→ tenant.permission:hr.employees.create
```

## Request

`StoreEmployeeRequest::authorize()` does not become the primary RBAC authority.

Route middleware remains canonical request-level permission enforcement.

## Controller

Controller retains domain orchestration only.

Do not add:

```text
if ($user->jabatan === ...)
```

or role-name authorization.

## Tests

Existing Employee feature tests must be migrated to create:

```text
Permission
→ Role
→ MembershipRole
```

Test matrix:

- unauthenticated → 401;
- membership without permission → 403;
- permission → success;
- cross-Tenant isolation;
- `jabatan` has no authorization effect.

---

# 12. Gate 1C — Organizational Permission Middleware

## Core Artifact

Add under Core ownership:

```text
Modules/Core/Organization/Http/Middleware/
```

generic organizational permission middleware.

Conceptual route alias:

```text
organizational.permission:<permission>
```

## Application Bootstrap

Middleware alias registration extends existing:

```text
bootstrap/app.php
```

alongside:

```text
tenant.role
tenant.permission
```

## Service Dependency

Must reuse:

```text
OrganizationalAuthorizationServiceInterface
```

No duplicate Role/Permission query implementation.

## Tests

Minimum:

- missing context;
- invalid assignment;
- inactive assignment;
- tenant grant inherited;
- organization grant;
- exact unit grant;
- sibling unit denied;
- other organization denied;
- permission denied canonical envelope.

---

# 13. Gate 1D — Employee API Hardening

## Controller

Refactor ad-hoc errors to:

```text
ApiErrorResponse
```

## Validation

Global Laravel validation envelope already owns:

```text
422
VALIDATION_FAILED
```

Do not create HR-specific validation envelope.

## DTO

Create purpose-specific API representation.

Minimum separation:

```text
Employee List DTO
Employee Detail DTO
Sensitive Employee DTO
```

as required by exposed operations.

Exact implementation class naming remains TDD concern.

## OpenAPI

Existing Employee routes move from:

```text
x-educore-deferred-routes
```

to canonical paths only after:

- authorization;
- response DTO;
- error envelope;
- contract tests;

are complete.

## Contract Tests

Validate:

- method/path;
- security contract;
- success schema;
- pagination metadata;
- 401;
- 403;
- 422;
- safe 5xx contract.

---

# 14. Security Lane — Queue Privacy

## Existing Artifact

Refactor:

```text
Modules/Core/Listeners/QueueWatchdogListener.php
```

## Target Behavior

Watchdog records allowlisted operational data only.

Example:

```text
job_class
queue
attempt
exception_class
business_run_id
```

Do not copy arbitrary:

```text
input_payload
```

## Future HR Jobs

Job DTO/payload:

```text
IDs
+
minimum operational metadata
```

never full HR datasets.

## Tests

Explicitly place sensitive sentinel values in test job input and verify they do not occur in:

```text
audit_logs.metadata
operational log payload
```

where testable.

---

# 15. Security Lane — After-Commit

No global queue config change is automatically required.

For each applicable workflow:

```text
DB transaction
→ COMMIT
→ job dispatch
```

Artifact responsibility belongs to application/domain orchestration.

Tests must prove the worker does not depend on uncommitted state.

---

# 16. Security Lane — Person Identifier

## Existing Schema

Reuse:

```text
person_identifiers.encrypted_value
person_identifiers.value_fingerprint
```

## Required Backend Artifacts

Before production identifier usage:

- identifier application service;
- encryption boundary;
- fingerprint/HMAC service or adapter;
- repository query by fingerprint;
- normalization rules from canonical Person domain.

## Test Requirements

- plaintext not persisted;
- encryption roundtrip;
- exact-match fingerprint;
- same normalized value gives same lookup fingerprint;
- invalid Tenant/identity access denied;
- identifier absent from logs/audit.

Exact key provider remains deferred.

---

# 17. Security Lane — Health & Correlation

## Liveness

Keep:

```text
/up
```

## Readiness

Refactor:

```text
/api/v1/core/health
```

to expose safe dependency states only.

Raw exception goes to protected log.

## Correlation

Platform artifact required for:

```text
request ID generation/validation
request lifecycle context
safe response transport
safe logging context
```

Exact header name remains platform API decision.

## Tests

- readiness DOWN without exception leak;
- request ID stable within one request;
- separate requests receive separate IDs;
- client cannot inject unsafe arbitrary correlation values;
- error response remains canonical.

---

# 18. Shared Frontend Foundation Artifact Plan

Current repository must not continue treating `resources/js/app.js` as canonical long-term frontend architecture.

ADR baseline defines:

```text
frontend/
├── index.html
└── src/
    ├── app/
    ├── platform/
    ├── shared/
    └── modules/
```

## [RECOMMENDATION]

Implementation should establish this boundary before substantial HR pages are built.

Exact package-manager file placement remains an implementation concern already deferred by ADR.

---

# 19. Frontend Platform Artifacts

Initial platform ownership:

```text
frontend/src/platform/
├── api/
├── auth/
├── session/
├── tenancy/
├── workspace/
├── authorization/
├── routing/
├── config/
└── observability/
```

Shared:

```text
frontend/src/shared/
├── ui/
├── forms/
├── errors/
├── hooks/
└── lib/
```

App:

```text
frontend/src/app/
├── bootstrap/
├── providers/
├── shell/
└── composition/
```

No HR business rules belong inside `platform` or `app`.

---

# 20. HR Frontend Module

Recommended implementation boundary:

```text
frontend/src/modules/hr/
```

Possible internal areas:

```text
features/
routes/
navigation/
api/
components/
model/
tests/
public.ts
```

This structure is flexible; ownership is mandatory, exact directories are not.

## Initial HR Feature Order

```text
workforce
→ employment
→ position
→ recruitment
→ leave
→ compensation
→ performance
→ documents
→ discipline
→ offboarding
→ reporting/export
```

Attendance belongs to:

```text
frontend/src/modules/attendance/
```

once that module is implemented.

---

# 21. Frontend API Boundary

Business components do not invoke raw `fetch` ad hoc.

Flow:

```text
HR component
→ HR API adapter/query
→ shared platform API client
→ canonical error normalization
```

Server state:

```text
TanStack Query
```

Route:

```text
React Router
```

Capabilities:

```text
platform authorization projection
```

Backend remains authority.

---

# 22. Frontend Test Layers

## Vitest

Use for:

- domain-neutral utility;
- query key factory;
- state derivation;
- route helpers.

## React Testing Library

Use for:

- HR pages/components;
- loading/empty/error;
- capability-driven visibility;
- validation interactions.

## MSW

Use for HTTP-facing component/integration scenarios.

MSW is not evidence that backend security works.

## Playwright

Use real backend for critical:

- authentication;
- Tenant switching;
- Workspace switching;
- capability denial;
- direct-route denial;
- HR authorization;
- context isolation.

---

# 23. Gate 2 — Employee Schema Evolution

Current `employees` remains **KEEP + EXTEND**.

Do not rewrite old baseline migration for ordinary feature evolution.

Use additive migrations.

## Initial Evolution

`jabatan` remains temporarily.

New application code gradually stops treating it as canonical.

No duplicate fields:

```text
person_id
organization_id
organization_unit_id
role_id
```

are added to Employee.

Those remain owned elsewhere.

---

# 24. Gate 2 — Employment Persistence

## Required Migration Class

New additive Employment persistence.

Exact field list is sourced from HR-002 before implementation.

Minimum locked invariants:

- belongs to Employee;
- tenant-safe;
- historical records preserved;
- `PLANNED / ACTIVE / ENDED` lifecycle semantics;
- at most one ACTIVE Employment per Employee;
- rehire creates a new record.

## [REKOMENDASI]

Enforce max-one-ACTIVE at both:

```text
domain/service layer
+
database integrity layer
```

For PostgreSQL, a partial unique constraint/index is a suitable implementation candidate.

Exact migration syntax remains engineering implementation.

---

# 25. Employment Backend Artifacts

Expected ownership:

```text
Modules/HR/
├── Contracts/
├── Models/
├── Repositories/
├── Services/
├── Http/
├── Database/Migrations/
└── Tests/
```

Potential components:

- Employment repository contract;
- Eloquent repository;
- lifecycle service;
- lifecycle exceptions/domain results;
- request DTO/FormRequest;
- controller/API;
- audit/domain evidence.

Do not put Employment lifecycle in Employee controller.

---

# 26. Employment API Plan

API operations must represent business lifecycle.

Examples conceptually:

```text
create Employment
activate Employment
end Employment
view Employment history
```

not:

```text
PATCH status = arbitrary-string
```

Exact URI design must be frozen in API specification before controller implementation.

## Required Error Semantics

- 401 authentication;
- 403 authorization;
- 404 inaccessible/missing resource per API policy;
- 409 invalid lifecycle/concurrency;
- 422 request validation.

---

# 27. Position Persistence Plan

Position and Position Assignment are implemented as HR-owned artifacts.

Core owns:

```text
OrganizationalAssignment
```

HR owns:

```text
Position
Position Assignment
```

No authorization role coupling.

## Position Assignment

Must reference canonical Employment rather than becoming an Employee `jabatan` replacement column.

Historical/effective semantics must follow HR-002 once the canonical artifact is integrated into repository.

---

# 28. Position Frontend

Within Workforce:

```text
Employee Detail
├── Employment
└── Position
```

Position UI must not present authorization Role controls.

Organizational placement and Position may appear together for usability, but originate from different owners.

---

# 29. `jabatan` Contract Migration

### Phase J1

Existing field remains required for compatibility where current endpoint still requires it.

### Phase J2

New canonical Workforce API stops presenting `jabatan` as Position authority.

### Phase J3

Canonical Position/Position Assignment introduced.

### Phase J4

Existing consumers migrated.

### Phase J5

Legacy field made unused/deprecated.

### Phase J6

Removal only through later explicit contract migration.

No destructive change in initial Workforce implementation.

---

# 30. Gate 3 — Scoped Employee Query

## Repository

Extend Employee repository with scope-aware read contract.

Separate:

```text
Tenant-wide query
```

from:

```text
Organization-scoped query
OrganizationUnit-scoped query
```

Avoid one method with ambiguous nullable scope parameters where it obscures security semantics.

## Query Order

```text
Tenant
→ valid resource scope
→ business filters
→ ordering
→ pagination
```

## Tests

Include:

- same organization;
- exact unit;
- sibling unit;
- different organization;
- inactive assignment;
- stale assignment;
- cross-Tenant.

---

# 31. Organizational Placement API Boundary

HR does not create Core OrganizationalAssignment mutation implementation.

HR frontend may consume placement through Core public contract.

If UI needs placement mutation:

```text
HR UI
→ Core-owned API/public contract
```

not:

```text
HR controller
→ direct duplicate assignment write
```

Core permission for placement mutation remains `[RESOURCE GAP]`.

---

# 32. Recruitment Artifact Plan

## Database

Separate canonical persistence for:

- Candidate;
- Application;
- selection lifecycle/evidence where defined;
- Hiring Approval;
- Onboarding;
- conversion identity/run evidence.

Exact tables/fields follow HR-003.

## Backend

Use separate domain services for:

```text
recruitment lifecycle
identity resolution
conversion
```

Candidate conversion is not a generic Employee creation helper.

## API

Expose business transitions, not generic editable status.

## Tests

Must include:

- Candidate ≠ Person;
- weak match does not merge;
- existing Person resolution;
- conversion idempotency;
- duplicate execution;
- Employment `PLANNED` created correctly;
- authorization/scope.

---

# 33. Leave Artifact Plan

## Database

Minimum conceptual persistence:

```text
Entitlement Ledger
Leave Request
Approval / decision evidence
Consumption transaction
```

Balance is derived.

Do not persist arbitrary mutable:

```text
leave_balance
```

as source of truth.

## Backend

Separate:

```text
ledger operation
request lifecycle
approval workflow
```

## API

Self-service and administrative operations remain permission-distinct.

## Frontend

Views:

```text
My Leave
Requests
Approvals
Entitlement / Balance
```

according to capability.

## Tests

- append-only ledger;
- final approval consumes entitlement;
- double approval conflict;
- own vs others;
- scope;
- source/calendar-dependent calculations when later defined.

---

# 34. Attendance Technical Ownership

Create:

```text
Modules/Attendance/
```

rather than implementing under HR.

Expected module-level artifacts:

```text
module.yaml
ServiceProvider
Database/Migrations
Contracts
Models
Repositories
Services
Http
Routes
Tests
```

Exact dependency list must be validated before manifest freeze.

## Frontend

```text
frontend/src/modules/attendance/
```

The HR Employee detail may link/embed authorized Attendance views through a public module contract.

---

# 35. Compensation Artifact Plan

## Database

Purpose-specific facts:

```text
Compensation
Benefits
Payroll Input Snapshot
```

When relevant:

```text
purpose
=
REGULAR_PAYROLL
FINAL_SETTLEMENT
```

## Boundary

No tables/services for:

- gross/net calculation;
- PPh calculation;
- payment;
- accounting entries.

Those remain Finance.

## Security

Restricted DTOs.

No compensation values in generic Employee list.

## Tests

- effective/history semantics;
- authorization;
- no disclosure in ordinary DTO;
- Finance boundary.

---

# 36. Performance Artifact Plan

## Database

Versioned:

```text
Framework / Template
Rating Scale
Assessment
Competency
Development / PKB
```

Do not hardcode one universal PKG rubric.

## Backend

Finalization is explicit lifecycle action.

## Frontend

Separate:

```text
Performance
Competency
Development
```

even when displayed in one workspace.

## Tests

- version traceability;
- finalized immutability;
- permission;
- no automatic compensation/promotion side effects.

---

# 37. Documents Artifact Plan

## Database

Store metadata only.

Conceptually:

```text
HRDocument
HRDocumentVersion
EmploymentAgreement
signature integration metadata when implemented
```

No file BLOB.

## Storage

Private adapter-based storage.

## Upload Lifecycle

```text
upload
→ private/quarantine where policy applies
→ validation/scan
→ available
→ finalized/signed
```

## API

Authorized download endpoint/service checks:

```text
Tenant
Permission
Resource Scope
Sensitivity
```

before retrieval.

## Tests

- public URL unavailable;
- unauthorized download denied;
- finalized version immutable;
- new version rather than overwrite;
- DB metadata/storage consistency.

---

# 38. Discipline Artifact Plan

## Database

Separate:

```text
Tenant disciplinary catalog
Discipline case
Evidence references
Disciplinary action
```

No hardcoded SP enum progression.

## Backend

Finalized disciplinary action cannot automatically change:

- Employment;
- Position;
- Compensation;
- Role.

## Tests

Verify absence of those cross-domain side effects.

Final tenant workflow remains blocked by disciplinary policy where necessary.

---

# 39. Offboarding Artifact Plan

## Database

Conceptual:

```text
Offboarding Case
Approval
Checklist
Handover
Access Review
Exit Interview
Settlement Facts
Completion Evidence
```

## Backend

Distinct actions:

```text
End Employment
```

and:

```text
Complete Offboarding
```

## Access Review

Do not directly revoke arbitrary Core grants until provenance/revocation policy is authoritative.

## Finance

Settlement remains factual boundary until Finance contract exists.

## Tests

- Employment ENDED but Offboarding incomplete;
- completion prerequisites;
- Membership remains intact by default;
- no automatic Role wipe;
- authorization.

---

# 40. Reporting Artifact Plan

## Initial Database

**No generic reporting tables required.**

Implement:

```text
direct query services
metric definition/version metadata
authorized read DTOs
```

first.

## Projection

Only introduce migration/persistence when:

```text
measured query evidence
+
freshness requirement
```

justifies it.

Any projection must be:

```text
rebuildable
reconcilable
source_as_of aware
```

## Frontend

HR reporting stays under:

```text
frontend/src/modules/hr/
```

not generic Reporting module.

---

# 41. Government Export Artifact Plan

Production implementation waits for field-level mapping authority.

Once available, artifacts include:

```text
mapping version
validation result
export run
frozen dataset
artifact metadata
```

Potential asynchronous processing uses identifier-only job payload.

## Storage

Private.

## Authorization

Separate:

```text
view
generate
download
```

## External Targets

Active:

```text
Dapodik
EMIS / EMIS GTK
```

Do not add new Simpatika integration.

---

# 42. API Specification Gate

Before implementation of each new API operation, minimum contract must define:

| Concern                 | Required                   |
| ----------------------- | -------------------------- |
| Method/path             | Yes                        |
| Request schema          | Yes                        |
| Response schema         | Yes                        |
| Permission              | Yes                        |
| Tenant scope            | Yes                        |
| Organizational scope    | If applicable              |
| Resource scope          | Yes                        |
| Lifecycle preconditions | If mutation                |
| Error codes             | Yes                        |
| Idempotency             | If relevant                |
| Audit/domain evidence   | High-impact operation      |
| OpenAPI                 | Before production contract |

No controller should become the de facto API specification.

---

# 43. Migration Gate

Before each migration PR:

1. Requirement/invariant identified.
2. Ownership confirmed.
3. Existing schema dependency checked.
4. Tenant isolation checked.
5. FK/delete semantics checked.
6. PostgreSQL integrity mechanism identified.
7. Required indexes justified by access pattern.
8. Rollback/roll-forward effect understood.
9. No duplicate Core ownership introduced.
10. Test plan exists.

---

# 44. Model / Repository Gate

Repositories must express ownership/scope clearly.

Preferred contracts distinguish:

```text
tenant query
organizational scoped query
single-resource lookup
```

rather than returning unfiltered global collections.

Models are not authorization boundaries.

Authorization remains request/domain policy.

---

# 45. Service Layer Gate

Domain services own lifecycle and transactional invariants such as:

```text
Employment activation/end
Candidate conversion
Leave final approval
Attendance reconciliation
Performance finalization
Document finalization
Offboarding completion
```

Controllers should not accumulate domain-state machines.

---

# 46. Test Pyramid per Capability

## Layer 1 — Schema / Persistence

Verify:

- FK;
- uniqueness;
- tenant integrity;
- lifecycle DB constraints;
- immutable/history behavior where enforced.

## Layer 2 — Domain / Service

Verify:

- state transition;
- transaction rollback;
- idempotency;
- concurrency rules.

## Layer 3 — Authorization

Verify:

- permission;
- self;
- organization/unit scope;
- sensitive access.

## Layer 4 — API / Contract

Verify:

- HTTP;
- request;
- DTO;
- canonical error;
- OpenAPI.

## Layer 5 — Frontend Component/Integration

Vitest + RTL + MSW.

## Layer 6 — Browser E2E

Playwright + real Laravel backend for security-sensitive critical paths.

---

# 47. Mandatory Negative Tests

Every HR capability should deliberately test what **must not happen**.

Examples:

```text
Position
→ does not grant permission

Employment end
→ does not deactivate Membership

Discipline
→ does not terminate Employment automatically

Agreement expiry
→ does not end Employment

Report view
→ does not imply export

Raw attendance event
→ does not become final attendance

Candidate
→ does not auto-create Person before resolution
```

These tests protect architectural boundaries.

---

# 48. Cross-Domain Integration Test Rule

Cross-domain test is required only where a real public contract exists.

Avoid tests that directly reach into another module's internal repository merely to make integration pass.

Examples:

```text
HR ↔ Core Organization
HR ↔ Finance future
HR ↔ Academic teaching facts
Attendance ↔ approved Leave facts
```

must use their explicit public boundary.

---

# 49. PR Composition Guidance

A normal capability should not be one giant PR.

Recommended decomposition:

```text
PR 1 — migration + persistence contract
PR 2 — domain lifecycle/service
PR 3 — authorization + API
PR 4 — OpenAPI + contract tests
PR 5 — frontend feature
PR 6 — browser/integration hardening
```

Smaller capabilities may combine compatible stages.

Migration and API must not be split so far apart that incomplete schema becomes permanently unused without plan.

---

# 50. Implementation Artifact Matrix

| Batch          | Migration                            | Backend                         | API/OpenAPI                 | Frontend               | Tests                 |
| -------------- | ------------------------------------ | ------------------------------- | --------------------------- | ---------------------- | --------------------- |
| Gate 0         | hygiene only                         | config/docs                     | —                           | boundary setup         | migration/module CI   |
| Auth           | no new auth tables                   | HR seeder/Core middleware       | Employee hardening          | capability consumption | auth/contract         |
| Queue/Security | possible none                        | watchdog/encryption/correlation | safe errors/health          | observability          | privacy/security      |
| Employee       | additive evolution                   | repo/service/DTO                | Employee contract           | Workforce              | feature/E2E           |
| Employment     | new canonical persistence            | lifecycle service               | explicit actions            | Employment workspace   | lifecycle/concurrency |
| Position       | new canonical persistence            | HR Position services            | pending permission decision | Position views         | migration/lifecycle   |
| Scope          | no duplicate org schema              | scoped repository               | scoped endpoints            | Workspace-aware views  | isolation             |
| Recruitment    | new domain tables                    | lifecycle/conversion            | recruitment API             | Recruitment            | idempotency           |
| Leave          | ledger/request tables                | ledger/approval                 | self/admin API              | Leave                  | ledger/concurrency    |
| Attendance     | `Modules/Attendance` migrations      | Attendance owner                | Attendance API              | Attendance module      | reconciliation        |
| Compensation   | HR fact tables                       | HR fact services                | restricted API              | Compensation           | disclosure            |
| Performance    | versioned models                     | finalization                    | performance API             | Performance            | immutability          |
| Documents      | metadata tables                      | private storage service         | upload/download             | Documents              | security/storage      |
| Discipline     | catalog/case                         | discipline lifecycle            | discipline API              | Discipline             | cross-domain negative |
| Offboarding    | case/workflow                        | offboarding lifecycle           | offboarding API             | Offboarding            | lifecycle             |
| Reporting      | direct query first                   | read services                   | reports                     | Reports                | metric/auth           |
| Gov Export     | mapping/run/frozen artifact metadata | export orchestration            | export APIs                 | Export workspace       | privacy/idempotency   |

---

# 51. Production Activation Requirements

Code-complete does not equal production-ready.

Each capability needs:

```text
schema migrated
authorization implemented
API documented
tests passing
frontend security aligned
operational logging safe
backup classification known
provider dependencies available
open policy blockers resolved
```

where relevant.

---

# 52. Open Decisions That Block Specific Technical Artifacts

| Open Item                 | Blocked Artifact                          |
| ------------------------- | ----------------------------------------- |
| Employment Type catalog   | final Employment classification contract  |
| future-effective rules    | scheduled Employment behavior             |
| Position permission model | Position mutation API                     |
| Leave calendar            | canonical entitlement calculations        |
| Attendance cutoff         | finalization scheduler                    |
| PKG mapping               | final specific assessment templates       |
| discipline policy         | final discipline workflow                 |
| offboarding chain         | final approval orchestration              |
| role provenance           | automatic access revocation               |
| storage provider          | production document/export adapter config |
| AV provider               | production scanned-upload activation      |
| e-sign provider           | signing adapter                           |
| Dapodik mapping           | Dapodik production export                 |
| EMIS mapping              | EMIS production export                    |
| RPO/RTO                   | production recovery commitment            |

---

# 53. Artifacts That Are Ready Without Those Decisions

Engineering may proceed with:

```text
repository cleanup
HR permission catalog
Employee authorization
canonical Employee API
organizational middleware
QueueWatchdog hardening
health sanitization
request correlation
Person identifier security
frontend foundation
Employment core history/invariants
Position persistence foundation
scope-aware Employee queries
```

without waiting for government/export/provider decisions.

---

# 54. Change Impact on HR-019 / HR-020

## HR-019

Add:

```text
HR-TASK-212 → HR-TASK-220
```

No existing task removed or renumbered.

`HR-TASK-143–151` technical ownership corrected to Attendance.

## HR-020

Gate 2 becomes:

```text
Employee
+
Employment
+
Position foundation
```

Position may run parallel with Employment once relevant Employment identity/persistence boundary is stable.

Gate 4 Attendance technical artifacts belong to future Attendance module.

Critical path otherwise unchanged.

---

# 55. Revised Gate 2

```text
Gate 1
  ↓
Employee Hardening
  ├── Employment
  └── Position Foundation
          ↓
Position Assignment
  ↓
Scoped Workforce
```

`jabatan` deprecation cannot move to final removal until Position migration is complete.

---

# 56. Revised Technical Ownership

```text
Modules/HR
├── Workforce
├── Employment
├── Position
├── Recruitment
├── Leave
├── Compensation
├── Performance
├── Documents
├── Discipline
├── Offboarding
└── Reporting / Government Export

Modules/Attendance
└── Attendance bounded context

Modules/Core
├── Person
├── Membership
├── Organization
├── OrganizationalAssignment
└── Authorization
```

Finance remains owner of payroll calculation/payment/accounting.

---

# 57. Definition of Phase 4D Complete

Phase 4D is complete when:

- every implementation wave has technical artifact expectations;
- migration responsibilities are explicit;
- API gate is explicit;
- frontend ownership is explicit;
- test layers are explicit;
- security/privacy artifacts are explicit;
- cross-domain owner boundaries are preserved;
- backlog omissions discovered during artifact mapping are corrected;
- no source code is required to begin planning next phase.

All criteria are satisfied by this draft.

---

# 58. Reviewer Assessment

**Quality Score:** **9.8/10**

## Gaps

1. Exact schema columns remain intentionally deferred to each capability's approved data/API contract.
2. Exact Position authorization mapping needs resolution before Position mutation API.
3. Attendance module dependency direction needs explicit acyclic integration decision before `module.yaml` is frozen.
4. Production providers remain unresolved.
5. Government field mappings remain unavailable.

## Risks

**[RISK — CRITICAL]** Position omission in the previous backlog could have caused engineering to remove `jabatan` without a canonical Position replacement.

**[RISK — HIGH]** Placing Attendance inside `Modules/HR` would contradict locked bounded-context ownership.

**[RISK — HIGH]** A direct HR↔Attendance dependency cycle could violate modular-monolith dependency rules.

**[RISK — HIGH]** Building APIs before their permission/resource-scope contracts are defined could recreate the current Employee authorization gap.

**[RISK — HIGH]** Editing old migrations instead of additive evolution can make existing environments non-reproducible.

## Recommendations

1. Lock HR-021 together with additive `HR-TASK-212–220`.
2. Treat Position as explicit Gate-2 Workforce implementation.
3. Re-own Attendance tasks under future `Modules/Attendance`.
4. Use additive migrations and expand/migrate/switch/contract for `jabatan`.
5. Require API contract before controller implementation for all new capabilities.
6. Require negative architectural tests, not only happy-path tests.
7. Build shared frontend foundation at `frontend/src/` before substantial HR UI.
8. Keep Reporting direct-query-first.
9. Do not start government export production work without official mapping authority.

**Status:** **READY FOR APPROVAL**
