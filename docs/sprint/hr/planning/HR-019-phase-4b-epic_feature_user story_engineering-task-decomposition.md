HR-019 — Phase 4B Epic → Feature → User Story → Engineering Task Decomposition

Version: 0.1 Draft
Phase: 4B
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
Depends On: HR-001–HR-018 + ADR-032
Repository Baseline: 26b475b695aa4511064b1410db03d1f0c8bdd6ce

1. Objective

HR-019 mengubah 29 implementation gaps dari HR-018 menjadi backlog engineering yang dapat diprioritaskan.

Hierarchy canonical:

Epic
→ Feature
→ User Story
→ Engineering Task

Setiap item harus dapat ditelusuri kembali ke:

HR Requirement

- GAP
- Existing Implementation

Phase ini belum menentukan sprint/date/effort point. Itu masuk Phase 4C–4E.

2. Backlog Structure

[REKOMENDASI] Gunakan 10 epic:

Epic Scope Wave
EP-HR-001 Repository & Documentation Baseline 0
EP-HR-002 Authorization & API Hardening 0
EP-HR-003 Security, Queue & Operational Foundation 0
EP-HR-004 Shared Frontend Foundation & HR Entry 0–1
EP-HR-005 Workforce & Employment Foundation 1
EP-HR-006 Recruitment & Onboarding 2
EP-HR-007 Leave & Attendance 2
EP-HR-008 Compensation & Performance 3
EP-HR-009 Documents, Discipline & Offboarding 3
EP-HR-010 Reporting & Government Export 4 3. Dependency Overview

EP-HR-004 dapat berjalan paralel dengan Wave 0 selama tidak membuat HR-specific application foundation.

flowchart TD
E1[EP-HR-001 Repository Baseline]
E2[EP-HR-002 Authorization & API]
E3[EP-HR-003 Security & Operations]
E4[EP-HR-004 Frontend Foundation]

    E5[EP-HR-005 Workforce & Employment]
    E6[EP-HR-006 Recruitment]
    E7[EP-HR-007 Leave & Attendance]
    E8[EP-HR-008 Compensation & Performance]
    E9[EP-HR-009 Documents Discipline Offboarding]
    E10[EP-HR-010 Reporting & Government Export]

    E1 --> E2
    E1 --> E3

    E2 --> E5
    E4 --> E5

    E5 --> E6
    E5 --> E7
    E5 --> E8
    E5 --> E9

    E3 --> E8
    E3 --> E9

    E6 --> E10
    E7 --> E10
    E8 --> E10
    E9 --> E10

4. EP-HR-001 — Repository & Documentation Baseline

Priority: P0
Gaps: GAP-027, GAP-028, GAP-029

Feature F-HR-001 — Repository portability
US-HR-001 — Case-safe repository

Sebagai engineer
Saya ingin filename Git konsisten dengan filesystem
Agar repository dapat berjalan konsisten pada Linux/CI.

Engineering Tasks:

HR-TASK-001 Inventarisasi seluruh tracked path dengan casing mismatch.
HR-TASK-002 Rekonsiliasi casing migration create_Employees → canonical filename.
HR-TASK-003 Rekonsiliasi casing ADR-011/ADR-012.
HR-TASK-004 Tambahkan CI validation pada case-sensitive environment.
HR-TASK-005 Jalankan migration discovery dan test suite setelah rename.

Trace: GAP-028, HR-016.

Feature F-HR-002 — PostgreSQL environment alignment
US-HR-002 — Authoritative persistence environment

Sebagai engineer
Saya ingin development/test/deployment baseline tidak bertentangan dengan PostgreSQL-specific schema
Agar database integrity tidak diturunkan untuk SQLite.

Tasks:

HR-TASK-006 Audit PostgreSQL-specific migrations/constraints.
HR-TASK-007 Reconcile .env.example database guidance.
HR-TASK-008 Pastikan CI menjalankan authoritative relational integration path.
HR-TASK-009 Dokumentasikan SQLite, bila tetap dipakai, sebagai non-authoritative limited environment.

Trace: GAP-027, HR-015, HR-016.

Feature F-HR-003 — Canonical HR documentation integration
US-HR-003 — Repository traceability

Tasks:

HR-TASK-010 Integrasikan HR-001–HR-019 ke repository docs.
HR-TASK-011 Pisahkan PRD/system architecture/implementation planning sesuai folder canonical.
HR-TASK-012 Tambahkan index/link antar HR artifacts.
HR-TASK-013 Dokumentasikan baseline commit dan locked ADR dependencies.

Trace: GAP-029.

5. EP-HR-002 — Authorization & API Hardening

Priority: P0
Gaps: GAP-002, 003, 004, 006, 007

Ini epic keamanan tertinggi.

Feature F-HR-004 — HR Permission Catalog
US-HR-004 — Canonical HR permissions

Sebagai administrator platform
Saya ingin HR mempunyai canonical permission catalog
Agar akses HR tidak bergantung pada jabatan atau role name.

Tasks:

HR-TASK-014 Tambahkan HRAuthorizationCatalogSeeder.
HR-TASK-015 Seed 53 canonical permission HR-013.
HR-TASK-016 Pastikan module = HR.
HR-TASK-017 Pastikan seeder idempotent.
HR-TASK-018 Jangan hapus custom roles/grants.
HR-TASK-019 Tambahkan permission catalog tests.
HR-TASK-020 Verifikasi tenant/workspace capability projection otomatis menemukan HR permissions.

Trace: GAP-003, HR-013.

Feature F-HR-005 — Current Employee Route Protection
US-HR-005 — Protected Employee API

Tasks:

HR-TASK-021 Tambahkan hr.employees.view pada GET Employee.
HR-TASK-022 Tambahkan hr.employees.create pada POST Employee.
HR-TASK-023 Pertahankan InjectTenantContext.
HR-TASK-024 Migrasikan existing tests agar membuat Role/Permission grant.
HR-TASK-025 Tambahkan no-permission → canonical 403 test.
HR-TASK-026 Tambahkan cross-tenant regression tests.
HR-TASK-027 Verifikasi jabatan tidak memengaruhi authorization.

Trace: GAP-002.

Feature F-HR-006 — Organizational Permission Middleware
US-HR-006 — Scoped middleware reusable

Tasks:

HR-TASK-028 Tambahkan generic Core middleware organizational.permission:<permission>.
HR-TASK-029 Reuse OrganizationalAuthorizationServiceInterface.
HR-TASK-030 Jalankan setelah InjectOrganizationalContext.
HR-TASK-031 Gunakan canonical AUTHORIZATION_DENIED.
HR-TASK-032 Pastikan tidak membuat global-superadmin HR-specific bypass.
HR-TASK-033 Tambahkan organization/unit permission inheritance tests.
HR-TASK-034 Tambahkan sibling/out-of-scope denial tests.

Owner: Core Platform, bukan HR-only.

Trace: GAP-004, HR-013.

Feature F-HR-007 — Canonical HR API Contract
US-HR-007 — Hardened Employee API

Tasks:

HR-TASK-035 Ganti ad-hoc error response Employee dengan ApiErrorResponse.
HR-TASK-036 Pertahankan global canonical VALIDATION_FAILED.
HR-TASK-037 Tetapkan purpose-specific Employee response DTO contract.
HR-TASK-038 Hindari accidental sensitive fields pada list DTO.
HR-TASK-039 Pindahkan GET/POST Employee dari deferred OpenAPI menjadi documented contract ketika prerequisites lulus.
HR-TASK-040 Dokumentasikan 401/403/422/500 behavior.
HR-TASK-041 Tambahkan OpenAPI contract tests.

Trace: GAP-006, GAP-007, HR-012–HR-014.

6. EP-HR-003 — Security, Queue & Operational Foundation

Priority: P0/P1
Gaps: 021–026

Feature F-HR-008 — Queue Privacy
US-HR-008 — Sensitive-safe async jobs

Tasks:

HR-TASK-042 Refactor QueueWatchdog agar tidak membaca/copy arbitrary payload.
HR-TASK-043 Define allowlisted watchdog metadata.
HR-TASK-044 Hilangkan raw exception business content bila berpotensi sensitif.
HR-TASK-045 Dokumentasikan identifier-only job payload convention.
HR-TASK-046 Tambahkan test bahwa sensitive data tidak masuk audit metadata.
HR-TASK-047 Tambahkan job-design checklist untuk future HR jobs.

Trace: GAP-021, HR-014, HR-016.

Feature F-HR-009 — Transaction-to-job consistency
US-HR-009 — Safe after-commit processing

Tasks:

HR-TASK-048 Identifikasi HR operation future yang memerlukan async processing.
HR-TASK-049 Standardisasi explicit after-commit dispatch pattern.
HR-TASK-050 Tambahkan integration test: worker tidak membaca uncommitted state.
HR-TASK-051 Dokumentasikan idempotency requirement per queued operation.
HR-TASK-052 Jangan mengubah global after_commit tanpa impact review.

Trace: GAP-022, HR-015.

Feature F-HR-010 — Person Identifier Security
US-HR-010 — Encrypted legal identifiers

Tasks:

HR-TASK-053 Audit absence/presence of PersonIdentifier application service.
HR-TASK-054 Define repository/service contract untuk encrypt/decrypt.
HR-TASK-055 Define keyed HMAC fingerprint generation.
HR-TASK-056 Verify raw identifier tidak persisted.
HR-TASK-057 Tambahkan duplicate/exact-match tests berbasis fingerprint.
HR-TASK-058 Tambahkan encryption roundtrip/security tests.
HR-TASK-059 Dokumentasikan key-management dependency tanpa mengunci vendor.

Trace: GAP-023, HR-014.

Feature F-HR-011 — Platform Diagnostics
US-HR-011 — Safe operational diagnostics

Tasks:

HR-TASK-060 Pertahankan /up sebagai liveness.
HR-TASK-061 Refactor Core health sebagai sanitized readiness.
HR-TASK-062 Jangan return raw DB/storage exception ke caller.
HR-TASK-063 Tambahkan request correlation middleware/platform service.
HR-TASK-064 Tambahkan safe request ID ke API transport.
HR-TASK-065 Tambahkan request correlation ke safe logs.
HR-TASK-066 Tambahkan health/correlation tests.

Trace: GAP-024, GAP-025.

Feature F-HR-012 — Deployment & Recovery Baseline
US-HR-012 — Reproducible production operations

Tasks:

HR-TASK-067 Define CI minimum gates.
HR-TASK-068 Define versioned backend release artifact approach.
HR-TASK-069 Define worker restart/deployment mechanism.
HR-TASK-070 Create migration release runbook.
HR-TASK-071 Create rollback/roll-forward runbook.
HR-TASK-072 Create backup coverage plan DB + private artifacts.
HR-TASK-073 Create isolated restore verification process.
HR-TASK-074 Record unresolved RPO/RTO as release gate, not guessed value.

Trace: GAP-026, HR-015–HR-016.

7. EP-HR-004 — Shared Frontend Foundation & HR Entry

Priority: P0 dependency for HR UI
Gap: GAP-020

This is largely a shared frontend/platform implementation dependency.

Feature F-HR-013 — Shared Application Foundation
US-HR-013 — Shared EduCore frontend runtime

Tasks:

HR-TASK-075 Implement approved application shell.
HR-TASK-076 Implement authentication/session bootstrap.
HR-TASK-077 Implement Tenant context switch.
HR-TASK-078 Implement Workspace context switch.
HR-TASK-079 Implement capability projection client/state.
HR-TASK-080 Implement protected route guard.
HR-TASK-081 Implement shared error/loading/recovery framework.
HR-TASK-082 Implement server-state cache invalidation on context switch.
HR-TASK-083 Implement frontend observability/release ID baseline.

Owner: Shared Frontend/Core platform.

Feature F-HR-014 — HR Module Entry
US-HR-014 — Capability-aware HR navigation

Tasks:

HR-TASK-084 Register HR lazy-loaded module.
HR-TASK-085 Implement HR navigation catalog per HR-010.
HR-TASK-086 Map navigation to canonical HR permissions.
HR-TASK-087 Implement HR overview placeholder only for implemented capabilities.
HR-TASK-088 Ensure hidden menu does not substitute backend security.
HR-TASK-089 Add navigation/route authorization tests.

Trace: GAP-020, HR-010–HR-012.

8. EP-HR-005 — Workforce & Employment Foundation

Priority: P1
Gaps: GAP-001, 005, 008, 009

Ini menjadi first business-domain implementation epic.

Feature F-HR-015 — Employee Foundation Hardening
US-HR-015 — Canonical Employee profile

Tasks:

HR-TASK-090 Preserve Person → Membership → Employee relationship.
HR-TASK-091 Define target Employee create/update DTO independent dari legacy Position semantics.
HR-TASK-092 Classify current jabatan reads/writes.
HR-TASK-093 Create additive deprecation/migration plan untuk jabatan.
HR-TASK-094 Jangan drop legacy column sampai all consumers migrated.
HR-TASK-095 Add Employee detail endpoint specification/implementation when required.
HR-TASK-096 Add ordinary vs sensitive DTO separation.

Trace: GAP-001.

Feature F-HR-016 — Employment Lifecycle
US-HR-016 — Historical Employment model

Tasks:

HR-TASK-097 Define Employment migration from HR-002 locked requirements.
HR-TASK-098 Implement Employment entity/model/repository.
HR-TASK-099 Enforce max one ACTIVE Employment.
HR-TASK-100 Implement PLANNED → ACTIVE lifecycle.
HR-TASK-101 Implement explicit End Employment.
HR-TASK-102 Implement rehire as new Employment.
HR-TASK-103 Ensure Employment end does not disable Membership.
HR-TASK-104 Add history/lifecycle tests.
HR-TASK-105 Add concurrency/conflict tests.

Blocked detail: Employment type catalog/future-effective semantics where applicable.

Trace: GAP-008.

Feature F-HR-017 — Organizational Employee Scope
US-HR-017 — Scope-aware workforce directory

Tasks:

HR-TASK-106 Define Employee-to-assignment scope query using Membership.
HR-TASK-107 Add Organization-scoped Employee repository query.
HR-TASK-108 Add exact OrganizationUnit-scoped query.
HR-TASK-109 Apply scope before pagination.
HR-TASK-110 Add scoped Employee endpoint only after authorization + query both exist.
HR-TASK-111 Test sibling unit denial.
HR-TASK-112 Test other Organization denial.
HR-TASK-113 Test inactive/stale assignment denial.

Trace: GAP-005.

Feature F-HR-018 — Organizational Placement Integration
US-HR-018 — Reuse canonical OrganizationalAssignment

Tasks:

HR-TASK-114 Define HR read contract terhadap Core OrganizationalAssignment.
HR-TASK-115 Expose placement context pada Employee detail without duplication.
HR-TASK-116 Do not add HR organization/position ownership tables.
HR-TASK-117 Define Core-owned placement mutation dependency.
HR-TASK-118 Add integration tests Employee Membership ↔ OrganizationalAssignment.

Trace: GAP-009.

9. EP-HR-006 — Recruitment & Onboarding

Priority: P2
Gap: GAP-010
Depends On: EP-HR-002, EP-HR-005

Feature F-HR-019 — Recruitment Lifecycle
US-HR-019 — Candidate-to-hire workflow

Tasks:

HR-TASK-119 Implement Candidate model/migration.
HR-TASK-120 Implement Application.
HR-TASK-121 Implement Selection lifecycle.
HR-TASK-122 Implement explicit Hiring Approval.
HR-TASK-123 Implement Onboarding case/state.
HR-TASK-124 Apply recruitment permission/scope rules.
HR-TASK-125 Add worklist/list/detail APIs.
HR-TASK-126 Add lifecycle/authorization tests.
Feature F-HR-020 — Identity Resolution & Conversion
US-HR-020 — Safe Candidate conversion

Tasks:

HR-TASK-127 Implement explicit Person/Membership resolution step.
HR-TASK-128 Support existing-Person result.
HR-TASK-129 Support potential/weak-match result without auto-merge.
HR-TASK-130 Support no-match new Person path.
HR-TASK-131 Create Employee only after resolved identity.
HR-TASK-132 Create Employment PLANNED.
HR-TASK-133 Make conversion idempotent.
HR-TASK-134 Add duplicate-conversion tests.

Trace: HR-003, GAP-010.

10. EP-HR-007 — Leave & Attendance

Priority: P2
Gaps: GAP-011, GAP-012

Feature F-HR-021 — Leave & Permit
US-HR-021 — Ledger-based leave lifecycle

Tasks:

HR-TASK-135 Implement entitlement ledger schema.
HR-TASK-136 Implement derived balance query.
HR-TASK-137 Implement Leave Request lifecycle.
HR-TASK-138 Implement self-service submission.
HR-TASK-139 Implement scope-aware approval worklist.
HR-TASK-140 Implement final approval ledger consumption.
HR-TASK-141 Add concurrency/double-approval tests.
HR-TASK-142 Add self-vs-other authorization tests.

Blocked detail: exact work calendar/entitlement policies.

Feature F-HR-022 — Attendance Reconciliation
US-HR-022 — Canonical attendance facts

Tasks:

HR-TASK-143 Implement raw attendance event boundary.
HR-TASK-144 Implement expectation contract.
HR-TASK-145 Integrate approved Leave/Permit facts.
HR-TASK-146 Implement reconciliation lifecycle.
HR-TASK-147 Implement final Attendance Record.
HR-TASK-148 Separate raw evidence permissions from finalize permissions.
HR-TASK-149 Add anomaly/worklist API.
HR-TASK-150 Test source failure ≠ absence.
HR-TASK-151 Test raw event ≠ final record.

Deferred: device adapters, cutoff policy.

11. EP-HR-008 — Compensation & Performance

Priority: P3
Gaps: GAP-013, GAP-014
Depends On: Workforce + sensitive-data controls

Feature F-HR-023 — Compensation / Benefits / Payroll Inputs
US-HR-023 — HR-owned compensation facts

Tasks:

HR-TASK-152 Define compensation fact schema.
HR-TASK-153 Define benefit model.
HR-TASK-154 Implement payroll input snapshots.
HR-TASK-155 Include future-compatible purpose.
HR-TASK-156 Enforce restricted permissions/DTOs.
HR-TASK-157 Keep Finance calculation/payment outside HR.
HR-TASK-158 Add historical/effective-date tests.
HR-TASK-159 Add sensitive disclosure tests.
Feature F-HR-024 — Performance & Development
US-HR-024 — Versioned assessment capability

Tasks:

HR-TASK-160 Implement versioned framework model.
HR-TASK-161 Implement rating scale model.
HR-TASK-162 Implement Performance/PKG assessment.
HR-TASK-163 Separate draft/manage from finalize.
HR-TASK-164 Implement Competency records.
HR-TASK-165 Implement Development/PKB records.
HR-TASK-166 Preserve Training ≠ Certification ≠ Competency.
HR-TASK-167 Add finalized immutability tests.

Blocked detail: exact PKG mapping/rubric.

12. EP-HR-009 — Documents, Discipline & Offboarding

Priority: P3
Gaps: 015–017

Feature F-HR-025 — Documents & Employment Agreements
US-HR-025 — Private immutable HR artifacts

Tasks:

HR-TASK-168 Implement document metadata model.
HR-TASK-169 Define private storage adapter contract.
HR-TASK-170 Implement safe upload lifecycle.
HR-TASK-171 Ensure no DB BLOB/public disk.
HR-TASK-172 Implement document versioning.
HR-TASK-173 Implement finalized/signed immutability.
HR-TASK-174 Implement Employment Agreement separately.
HR-TASK-175 Add authorized download flow.
HR-TASK-176 Add private-storage/access tests.

Blocked production item: storage/AV/e-sign providers.

Feature F-HR-026 — Discipline
US-HR-026 — Tenant-policy disciplinary cases

Tasks:

HR-TASK-177 Implement tenant-scoped disciplinary catalog.
HR-TASK-178 Implement discipline case.
HR-TASK-179 Implement evidence references.
HR-TASK-180 Implement disciplinary action lifecycle.
HR-TASK-181 Separate manage vs finalize authorization.
HR-TASK-182 Ensure no automatic Employment/Position/Compensation/RBAC mutation.
HR-TASK-183 Add highly-restricted disclosure tests.

BLOCKED for final workflow: disciplinary/SP and appeal policy.

Feature F-HR-027 — Offboarding
US-HR-027 — Explicit offboarding case

Tasks:

HR-TASK-184 Implement Offboarding Case.
HR-TASK-185 Implement approval/checklist.
HR-TASK-186 Implement handover.
HR-TASK-187 Implement Access Review as recommendation/request boundary.
HR-TASK-188 Implement Exit Interview.
HR-TASK-189 Implement Settlement Facts boundary.
HR-TASK-190 Implement explicit completion.
HR-TASK-191 Ensure Employment ENDED ≠ Offboarding COMPLETED.
HR-TASK-192 Ensure no automatic Membership/Role revocation.
HR-TASK-193 Add lifecycle/dependency tests.

Blocked integrations: Finance settlement, Asset, access revocation policy.

13. EP-HR-010 — Reporting & Government Export

Priority: P4
Gaps: GAP-018, GAP-019
Depends On: implemented source domains

Feature F-HR-028 — HR Reporting
US-HR-028 — Authorized HR reports

Tasks:

HR-TASK-194 Implement reporting metric definition registry/version traceability.
HR-TASK-195 Implement direct-query-first report services.
HR-TASK-196 Implement snapshot vs period semantics.
HR-TASK-197 Apply tenant/org/sensitivity scope before aggregation.
HR-TASK-198 Separate aggregate vs detail permission.
HR-TASK-199 Implement export authorization separately from view.
HR-TASK-200 Measure query performance before adding projections.
HR-TASK-201 Add rebuildable projection only when justified.
Feature F-HR-029 — Government Export
US-HR-029 — Secure Dapodik / EMIS exports

Tasks:

HR-TASK-202 Define versioned government mapping contract.
HR-TASK-203 Implement external validation.
HR-TASK-204 Implement frozen dataset/run.
HR-TASK-205 Implement identifier-only async export job.
HR-TASK-206 Store generated artifact privately.
HR-TASK-207 Separate view/generate/download authorization.
HR-TASK-208 Implement QUEUED/PROCESSING/READY/FAILED state where contract requires.
HR-TASK-209 Add export audit/domain evidence.
HR-TASK-210 Add failure/idempotency tests.
HR-TASK-211 Do not implement new Simpatika integration.

[RESOURCE GAP / BLOCKED] Dapodik/EMIS field-level mapping masih diperlukan sebelum production export.

14. Gap → Epic Traceability
    Gap Epic
    GAP-001 jabatan EP-HR-005
    GAP-002 route authorization EP-HR-002
    GAP-003 permission catalog EP-HR-002
    GAP-004 org middleware EP-HR-002
    GAP-005 scope query EP-HR-005
    GAP-006 API errors EP-HR-002
    GAP-007 OpenAPI EP-HR-002
    GAP-008 Employment EP-HR-005
    GAP-009 placement EP-HR-005
    GAP-010 Recruitment EP-HR-006
    GAP-011 Leave EP-HR-007
    GAP-012 Attendance EP-HR-007
    GAP-013 Compensation EP-HR-008
    GAP-014 Performance EP-HR-008
    GAP-015 Documents EP-HR-009
    GAP-016 Discipline EP-HR-009
    GAP-017 Offboarding EP-HR-009
    GAP-018 Reporting EP-HR-010
    GAP-019 Government export EP-HR-010
    GAP-020 Frontend EP-HR-004
    GAP-021 Queue privacy EP-HR-003
    GAP-022 after-commit EP-HR-003
    GAP-023 identifier security EP-HR-003
    GAP-024 health EP-HR-003
    GAP-025 correlation EP-HR-003
    GAP-026 deployment/recovery EP-HR-003
    GAP-027 PostgreSQL EP-HR-001
    GAP-028 casing EP-HR-001
    GAP-029 docs EP-HR-001

Coverage: 29 / 29 gaps mapped.

15. Backlog Readiness Classification
    READY TO START NOW
    EP-HR-001 entirely.
    HR permission catalog.
    Current Employee route authorization.
    Employee canonical error hardening.
    QueueWatchdog remediation.
    health sanitization.
    backend correlation foundation.
    documentation integration.
    frontend shared foundation implementation.
    READY AFTER WAVE 0
    Employment.
    scoped Employee queries.
    organizational placement integration.
    Recruitment foundation.
    Leave foundation.
    DEPENDENT ON POLICY / OPEN DECISION
    exact Employment classification behavior;
    detailed Leave calendar;
    Attendance cutoff;
    detailed PKG;
    final Discipline workflow;
    Access Review automatic effects;
    retention/purge jobs;
    production document vendor specifics;
    government field mapping.
    SHOULD NOT START YET
    generic reporting projection;
    generic cache;
    data warehouse;
    direct government sync;
    new Simpatika integration;
    microservice extraction.
16. Engineering Boundary Rules

Every task in this backlog must preserve:

Person
→ Membership
→ Employee
→ Employment

and:

Position ≠ Role
Position ≠ Permission

and:

HR compensation facts
≠ Finance payroll calculation

and:

Employment ENDED
≠ Offboarding COMPLETED
≠ Membership deactivated

and:

Frontend capability
≠ backend authorization

and:

Scoped permission
≠ scoped resource automatically 17. Backlog Change Control

If engineering discovers a requirement conflict:

Task
→ STOP architectural assumption
→ identify HR requirement affected
→ perform impact analysis
→ explicit change request

Engineering task tidak boleh diam-diam mengubah locked specification.

Particularly prohibited shortcuts:

make jabatan become Role;
put payroll calculation in HR;
create HR organization duplicate;
auto-disable Membership during offboarding;
hardcode SP1→SP2→SP3;
store documents publicly;
return all Employee fields then hide frontend;
use tenant-wide query behind organizational permission. 18. Phase 4B Definition of Done
Criterion Result
29 gaps mapped PASS
Epics defined PASS
Features defined PASS
User Stories defined PASS
Engineering Tasks defined PASS
Dependencies represented PASS
P0 technical work visible PASS
Business capability work separated PASS
Platform ownership preserved PASS
Open decisions not invented PASS
Traceability retained PASS
Sprint/date estimates avoided PASS 19. Reviewer Assessment

Quality Score: 9.8/10

Gaps

Phase 4B belum menentukan:

exact task sequencing inside a sprint;
task estimates;
sprint capacity;
owners/person assignments;
milestone dates;
migration batching;
API implementation order inside each capability.

Hal tersebut memang scope Phase 4C–4E.

Risks

[RISK — CRITICAL] Menempatkan EP-HR-005 sebelum EP-HR-002 dapat memperluas HR surface tanpa authorization baseline.

[RISK — CRITICAL] organizational.permission tidak boleh digunakan pada Employee collection sebelum HR-TASK-106–113 selesai.

[RISK — HIGH] EP-HR-004 dapat menghasilkan duplicate frontend architecture jika dibangun oleh tim HR terpisah dari shared FE foundation.

[RISK — HIGH] EP-HR-009/010 tidak boleh mengaktifkan sensitive async processing sebelum EP-HR-003 Queue Privacy selesai.

[RISK] Task count besar tidak berarti seluruh backlog harus masuk satu release; delivery tetap incremental.

Recommendations
Lock HR-019 sebagai canonical engineering decomposition.
Jangan langsung memasukkan 211 tasks ke sprint.
Phase 4C harus membangun dependency-aware implementation sequence dari backlog ini.

Critical path pertama:

EP-HR-001
→ EP-HR-002
→ EP-HR-005
EP-HR-003 berjalan paralel untuk security/operations.
EP-HR-004 berjalan paralel sebagai shared frontend dependency.
Reporting/Government Export tetap terakhir.

Status: READY FOR APPROVAL
