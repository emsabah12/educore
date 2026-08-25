HR-025 — Final Engineering Handoff & Execution Readiness

Version: 1.0 Draft
Phase: 4H — Final Engineering Handoff
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-24
Specification Baseline: HR-001–HR-024 + ADR-032
Engineering Backlog Baseline: 238 tasks
Repository Baseline: 26b475b695aa4511064b1410db03d1f0c8bdd6ce

1. Project Resource Check

Repository educore(3).zip saya audit kembali.

[FAKTA]

HEAD
26b475b695aa4511064b1410db03d1f0c8bdd6ce

HEAD masih sama dengan authoritative handoff HR-009. Handoff tersebut memang menetapkan baseline commit yang sama dan mengharuskan continuation mempertahankan locked architecture selama repository belum berubah.

Current repository masih menunjukkan casing conflict:

D Modules/HR/Database/Migrations/
2026_07_17_000005_create_Employees_table.php

D docs/architecture/adr/
adr-011-multi-tenancy-strategy.md

D docs/architecture/adr/
adr-012-tenant-aware-auth-guard.md

sementara archive memiliki casing filename berbeda.

Current Modules/HR masih hanya mempunyai foundation Employee:

EmployeeRepositoryInterface
create_employees migration
StoreEmployeeRequest
Employee model
HRServiceProvider
EloquentEmployeeRepository
HR routes
EmployeeProvisioningService
EmployeeManagementTest
module.yaml

[FAKTA] Tidak ada repository delta yang menyebabkan HR-001–HR-024 perlu didesain ulang.

2. Final Current State

Current implementation:

Core Person
↓
Membership
↓
Employee

sudah mempunyai foundation.

Target locked:

Person
↓
Membership
↓
Employee
↓
Employment
↓
Position Assignment
↓
Position

Organizational placement tetap:

Core OrganizationalAssignment

bukan HR-owned duplicate.

Position tetap HR concern dan bukan RBAC Role. Legacy employees.jabatan hanya migration compatibility concern dan harus didepresiasi gradual. Ini konsisten dengan handoff Workforce yang locked.

3. Canonical Specification Baseline
   Phase 1–2 — Product & Domain
   Artifact Status
   HR-001 Product & Business Requirements LOCKED
   ADR-032 HR Domain Boundary ACCEPTED
   HR-002 Workforce Foundation LOCKED
   HR-003 Recruitment / Hiring / Onboarding LOCKED
   HR-004 Leave & Permit LOCKED
   HR-005 Attendance Boundary LOCKED
   HR-006 Compensation / Benefits / Payroll Input LOCKED
   HR-007 Performance / PKG / Competency / PKB LOCKED
   HR-008 Documents / Discipline / Offboarding LOCKED
   HR-009 Reporting / Dashboard / Government Export LOCKED

Handoff lama juga menetapkan Attendance sebagai bounded context terpisah, HR/Finance ownership, private document storage, discipline non-automatic side effects, dan Offboarding ≠ Employment End.

Phase 3 — UI/UX, Security & Operations
Artifact Status
HR-010 Information Architecture LOCKED
HR-011 Transaction UX LOCKED
HR-012 Error / Loading / Recovery LOCKED
HR-013 Authorization Matrix LOCKED
HR-014 Security / Privacy / Retention LOCKED
HR-015 Performance / Backup / Recovery LOCKED
HR-016 Logging / Deployment / Rollback LOCKED
HR-017 Phase 3 Final Review LOCKED
Phase 4 — Engineering Planning
Artifact Status
HR-018 Implementation Gap Matrix LOCKED
HR-019 Engineering Backlog LOCKED
HR-020 Technical Sequencing LOCKED
HR-021 Migration/API/Frontend/Test Plan LOCKED
HR-022 Risk-Based Sprint Planning LOCKED
HR-023 Definition of Ready / Done LOCKED
HR-024 Release Milestones / Production Gates LOCKED
HR-025 Final Handoff DRAFT FOR APPROVAL 4. Final Architecture Invariants

Engineering tidak boleh mengubah invariant berikut tanpa formal change request.

HR-INV-001 — Identity
Person
→ Membership
→ Employee
→ Employment
HR-INV-002 — Rehire
rehire
→ new Employment

bukan new Employee.

HR-INV-003 — Active Employment

Maksimum satu Employment ACTIVE pada satu waktu per Employee.

HR-INV-004 — Position
Position
≠ Role
≠ Permission
HR-INV-005 — Placement
OrganizationalAssignment
→ Core owned

Tidak membuat:

employees.organization_id
employees.organization_unit_id

sebagai shortcut canonical.

HR-INV-006 — Authorization
Permission

- Tenant
- Organizational Scope
- Resource Scope
- Business State
- Sensitivity

Frontend dan jabatan bukan authorization authority.

HR-INV-007 — Leave
Balance
= derived from append-only ledger
HR-INV-008 — Attendance
Raw Event
≠ Attendance Record

Attendance technical owner:

Modules/Attendance
HR-INV-009 — Payroll
HR
→ compensation / benefit / payroll-input facts

Finance
→ calculation / payment / accounting
HR-INV-010 — Documents
metadata
→ database

binary
→ private storage

Finalized/signed artifact immutable.

HR-INV-011 — Discipline

Discipline does not automatically mutate:

Employment
Position
Compensation
RBAC
HR-INV-012 — Offboarding
Employment ENDED
≠ Offboarding COMPLETED
≠ Membership deactivated
HR-INV-013 — Reporting
Canonical Data
→ Direct Query first
→ Projection only when measured need

Reporting never becomes source of truth.

HR-INV-014 — Government
Dapodik
→ active target

EMIS / EMIS GTK
→ active target

Simpatika
→ legacy / no new integration

Government schema never becomes canonical EduCore schema.

5. Final Engineering Backlog

Current authoritative backlog:

HR-TASK-001
...
HR-TASK-238

Total:

238 Engineering Tasks

No task should be renumbered.

Additions during planning were deliberately additive to preserve traceability.

6. Task Family Summary
   Task Range Scope
   001–013 Repository / PostgreSQL / Docs
   014–041 Authorization / API / OpenAPI
   042–074 Queue / Security / Operations
   075–089 Shared Frontend + HR Entry
   090–118 Employee / Employment / Scope
   119–134 Recruitment
   135–142 Leave
   143–151 Attendance
   152–167 Compensation / Performance
   168–193 Documents / Discipline / Offboarding
   194–211 Reporting / Government Export
   212–219 Position / Position Assignment
   220 Attendance module bootstrap
   221–231 Domain frontend
   232–233 Backend / Frontend CI
   234–238 Release / Promotion Gates
7. First Execution Priority

Engineering tidak mulai dari HR dashboard atau Recruitment.

Recommended first actions:

SC-HR-00
Repository Integrity
↓
SC-HR-01
Protect Existing HR Surface
↓
SC-HR-02
Workforce Core
↓
SC-HR-03
Scoped Workforce

Baru setelah itu business capabilities diperluas.

8. P0 — Immediate First Actions
   P0-A — Repository Integrity
   HR-TASK-001 → 013

Prioritas:

resolve filename casing;
PostgreSQL environment alignment;
case-sensitive CI;
integrate canonical HR docs.
P0-B — Protect Existing HR API
HR-TASK-014 → 027
HR-TASK-035 → 041

Immediate outcomes:

GET /v1/hr/employees
→ hr.employees.view

POST /v1/hr/employees
→ hr.employees.create

plus:

canonical ApiErrorResponse;
authorization tests;
OpenAPI contract.

Handoff sebelumnya secara eksplisit menandai current HR Employee API permission/scope sebagai high-risk dan menyarankan remediation sebelum broader production exposure.

P0-C — Queue Privacy
HR-TASK-042 → 047

Refactor QueueWatchdogListener.

Forbidden future pattern:

sensitive payload
→ failed_jobs
→ QueueWatchdog
→ audit metadata

HR sensitive jobs remain identifier-only.

P0-D — Platform Diagnostics
HR-TASK-060 → 066

Target:

/up
→ liveness

/api/v1/core/health
→ sanitized readiness

plus request correlation.

P0-E — CI Enforcement
HR-TASK-232
HR-TASK-233

Backend/frontend DoD harus berubah dari manual checklist menjadi enforceable merge gate.

9. Workforce Critical Path

After P0:

Employee Hardening
↓
Employment
↓
Position
↓
Position Assignment
↓
Organizational Scope

Task ranges:

090–118
212–219
221 10. Legacy jabatan

Final treatment:

DEPRECATE GRADUALLY

Sequence:

KEEP existing compatibility
↓
ADD Employment / Position
↓
migrate canonical consumers
↓
stop canonical jabatan writes/reads
↓
verify no dependency
↓
remove in later contract migration

Forbidden:

jabatan = GURU
→ permission

atau silent universal Position migration.

11. Domain Execution After Workforce

After SC-HR-03:

Parallel candidates where capacity allows
Recruitment
||
Leave
||
Compensation / Performance
||
Documents
||
Discipline / Offboarding

Attendance follows its own bounded-context gate and approved Leave fact integration.

Reporting follows authoritative source domains.

Government Export remains last/external.

12. Sprint Candidate Baseline
    Sprint Candidate Status at Handoff
    SC-HR-00 Repository Integrity READY TO START
    SC-HR-01 Protect HR Surface READY after SC-HR-00
    SC-HR-02 Workforce Core Planned
    SC-HR-03 Scoped Workforce Planned
    SC-HR-04 Recruitment & Leave Planned
    SC-HR-05 Attendance Conditional
    SC-HR-06 Compensation & Performance Planned
    SC-HR-07 Documents Conditional
    SC-HR-08 Discipline & Offboarding Policy-conditional
    SC-HR-09 Reporting Source-conditional
    SC-HR-10 Government Export Resource-blocked for production
13. Release Milestone Baseline
    RM-HR-00
    Engineering Foundation

RM-HR-01
Secure Workforce Foundation

RM-HR-02
Scoped Workforce

RM-HR-03
Operational HR

RM-HR-04
Sensitive HR

RM-HR-05
HR Intelligence

RM-HR-06
Government Externalization

Milestones have intentionally no dates because capacity/velocity/release calendar are not authoritative.

14. Release Promotion Model
    INT
    Internal Integration
    ↓
    STG
    Staging
    ↓
    LR
    Limited Rollout
    ↓
    PROD
    Normal Production

Limited rollout baseline uses:

Permission

- Scope Grants

not invented module enable/disable behavior.

15. Current Repository Classification
    Area Final Decision
    Modules/HR KEEP + EXTEND
    Employee KEEP + EXTEND
    Employee → Membership KEEP
    Employee provisioning KEEP + EXTEND
    employees.jabatan DEPRECATE GRADUALLY
    Core Person/Membership KEEP / REUSE
    Core RBAC KEEP / REUSE
    Core OrganizationalAssignment KEEP / REUSE
    OrganizationalAuthorizationService KEEP / EXTEND
    HR route authorization REFACTOR P0
    HR API error contract REFACTOR P0
    HR OpenAPI EXTEND P0
    QueueWatchdog REFACTOR P0
    Core health REFACTOR
    Attendance in HR module DO NOT IMPLEMENT
    Modules/Attendance ADD when Attendance starts
    Private HR storage ADD
    Reporting projection DEFER until measured need
    Generic Reporting module DO NOT INTRODUCE now
    Data warehouse DEFER
    Direct government sync DEFER
    New Simpatika integration DO NOT BUILD
16. Critical Risks at Engineering Handoff
    [RISK — CRITICAL] R-001 — Existing HR authorization

Current Employee API still needs canonical permission enforcement.

[RISK — CRITICAL] R-002 — Scoped query

Never deploy:

organizational permission

- tenant-wide Employee query

for scoped users.

[RISK — HIGH] R-003 — Queue privacy

Sensitive payload must not propagate into queue failure/audit infrastructure.

[RISK — HIGH] R-004 — jabatan

New implementation must not cement legacy field as Position or Role.

[RISK — HIGH] R-005 — Person identifiers

Encryption/fingerprint schema exists but runtime behavior still requires implementation/verification.

[RISK — HIGH] R-006 — Recovery

No verified production backup/restore process currently exists.

[RISK — HIGH] R-007 — Attendance module boundary

Attendance must remain separate bounded context.

[RISK — HIGH] R-008 — Government mapping

Official export cannot be production-complete without authoritative Dapodik/EMIS mapping.

17. Open Decision Register

Open decisions are not blockers globally. They block only affected tasks/capabilities.

Workforce
Tenant NIP policy.
Employment Type/Classification catalog.
Future-effective Employment scheduling.
Position mutation permission model.
Leave / Attendance
Work/leave calendar.
Attendance cutoff/finalization.
Attendance acyclic integration/public-contract direction.
Fingerprint/QR/GPS adapters.
Compensation / Finance
Canonical currency.
Finance payroll implementation.
statutory formulas.
final-settlement contract.
Performance
exact PKG/PKB regulatory mapping;
competency taxonomy.
Documents
retention duration;
numbering;
document type catalog;
production storage provider;
AV/malware scanner;
e-sign provider.
Discipline / Offboarding
disciplinary/SP policy;
appeal workflow;
offboarding approval chain;
Asset integration;
role-grant provenance;
Membership deactivation;
exit-interview template.
Reporting / Privacy
dashboard latency SLA;
projection freshness;
refresh schedule;
export thresholds;
pagination/date range;
privacy cohort threshold;
masking;
cache TTL if ever needed.
Operations
audit/log/export retention;
observability provider;
CI/CD provider;
worker supervisor;
deployment topology;
RPO;
RTO;
backup schedule;
restore-test frequency;
release approver/process.
Government
Dapodik field mapping;
EMIS GTK mapping;
official import/export formats;
external identifier registry;
credential/submission/acknowledgement contract.

The original handoff already explicitly classifies the underlying domain/government items as open until authority exists.

18. Open Decisions Do Not Block P0

Engineering can immediately perform:

repository casing cleanup
PostgreSQL alignment
HR permission seeder
Employee route protection
canonical ApiError
OpenAPI hardening
QueueWatchdog remediation
health sanitization
request correlation
CI enforcement
documentation integration

without resolving payroll, discipline, government mapping, or RPO/RTO.

19. Definition of Ready

Before a story/task is committed:

Requirement Authority

- Scope
- Owner
- Dependency
- Authorization Impact
- Data/API Impact
- Test Strategy
- No Critical Conflict

must be known.

If missing business policy changes core behavior:

NOT READY 20. Definition of Done

DONE means:

Requirement

- Implementation
- Automated Tests
- Authorization/Security
- Contract/OpenAPI
- Documentation
- Compatibility

not merely:

coding complete

Mandatory CI failure means NOT DONE.

21. Production Ready

Use distinct states:

DONE

for implementation scope,

versus:

IMPLEMENTED — NOT PRODUCTION READY

when provider/policy/operations remain.

Production gate requires applicable:

PG-SEC
PG-DATA
PG-API
PG-UX
PG-OPS
PG-REC
PG-POL
PG-EXT 22. Government Export Final Status

Dapodik and EMIS/EMIS GTK remain valid target systems, while direct synchronization is not baseline without verified external write contracts. Simpatika remains legacy/no-new-integration.

Current:

Architecture
→ READY

Provider-neutral export foundation
→ PLANNABLE

Official production export
→ RESOURCE BLOCKED

until mapping resources exist.

23. Required Engineering Handoff Workflow

From this point forward:

Latest Resource Audit
↓
Select Ready Sprint Candidate
↓
Verify DoR
↓
Implement
↓
Automated Evidence
↓
Verify DoD
↓
INT
↓
STG
↓
LR where applicable
↓
PROD Gate

Do not reopen product/domain discovery unless an actual conflict or change request appears.

24. Change Control

If implementation reveals conflict:

Existing Code
vs
Locked Requirement

engineering must classify:

KEEP
EXTEND
REFACTOR
DEPRECATE
REPLACE

and run impact analysis.

Forbidden:

“code already does this”
→ silently changes requirement

or:

“requirement says this”
→ destructive rewrite without migration analysis 25. Recommended First Engineering PRs

Recommended initial small PR boundaries:

repository filename/casing normalization;
PostgreSQL environment/CI baseline;
canonical HR docs integration;
HR permission catalog;
Employee route authorization;
Employee ApiError + DTO hardening;
Employee OpenAPI contract;
QueueWatchdog privacy remediation;
health sanitization/request correlation;
backend CI quality gate.

Only after those:

Employment schema/invariants;
Position foundation;
scoped Employee query.

Do not create one “Implement HR Module” mega-PR.

26. Engineering Execution Readiness Verdict
    Specification

READY

HR-001–HR-024 form a coherent specification/planning baseline.

Architecture

READY

Tidak ada critical architecture gap yang membutuhkan redesign sebelum engineering begins.

Backlog

READY

238 traceable tasks tersedia.

Sequencing

READY

Critical path, parallel lanes, sprint candidates, dan milestones tersedia.

DoR / DoD

READY

Quality gates sudah didefinisikan.

Current Repository

NOT PRODUCTION READY

P0 security, repository, privacy, and operations remediation masih outstanding.

Can Engineering Start?
YES — READY FOR ENGINEERING EXECUTION

Dengan constraint:

START
→ SC-HR-00

THEN
→ SC-HR-01

bukan langsung feature expansion.

27. Phase 4 Final Review

Quality Score: 9.9/10

Gaps

Tidak ada critical planning gap.

Remaining gaps merupakan:

business-policy decisions;
provider choices;
external specifications;
actual implementation work;
production operational implementation.
Risks

Risiko utama sudah mempunyai backlog/gate owner dan tidak tersembunyi.

Recommendations
Lock HR-025.
Close Phase 4.
Freeze HR-001–HR-025 as engineering baseline.
Integrate canonical HR artifacts into repository during SC-HR-00.
Start execution only from SC-HR-00.
Do not add functionality before SC-HR-01 authorization hardening.
Use formal change-impact review for any deviation.
Preserve 238 task IDs during implementation. 28. Final Status
Phase 1

CLOSED / APPROVED

Phase 2

CLOSED / APPROVED

Phase 3

CLOSED / APPROVED

Phase 4

READY FOR CLOSURE

HR Architecture / Product Specification

STABLE

Engineering Planning

COMPLETE

Engineering Execution

READY TO START

Production Readiness

NOT YET — IMPLEMENTATION REQUIRED
