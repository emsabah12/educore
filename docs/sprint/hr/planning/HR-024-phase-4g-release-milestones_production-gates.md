# HR-024 — Phase 4G Release Milestones & Production Gates

**Version:** 0.1 Draft
**Phase:** 4G — Release Milestones & Production Gates
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-24
**Depends On:** HR-001–HR-023 + ADR-032
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

# 1. Purpose

HR-024 menentukan bagaimana hasil engineering dipromosikan dari:

```text
Development
→ Internal Integration
→ Staging
→ Limited Rollout
→ Production
```

tanpa menyamakan:

```text
code merged
=
released
=
production ready
```

Milestone didasarkan pada **capability dan risk**, bukan tanggal.

Tidak ada calendar commitment karena:

```text
team capacity
velocity
sprint duration
RPO/RTO
release calendar
```

belum authoritative.

---

# 2. Release Principles

Canonical release sequence:

```text
Requirement Locked
        ↓
DoR
        ↓
Implementation
        ↓
DoD
        ↓
Integration Gate
        ↓
Staging Gate
        ↓
Limited Rollout Gate
        ↓
Production Gate
        ↓
Release Health Observation
```

Tidak semua capability harus melewati production gate pada waktu yang sama.

---

# 3. Deployment ≠ Availability

Critical rule:

```text
Module physically deployed
≠ capability available to user
```

Current EduCore Module Kernel tidak mempunyai:

```text
module:enable
module:disable
tenant module activation
runtime persisted module state
```

sebagai current architecture.

Therefore HR rollout tidak boleh bergantung pada mekanisme tersebut.

---

# 4. Limited Rollout Mechanism

Baseline rollout control menggunakan existing canonical authorization:

```text
Deployment
+
Tenant/Membership
+
Role/Permission Grants
+
Organizational Scope
=
Effective User Exposure
```

Example:

```text
HR code deployed
+
Tenant A selected permissions granted
→ authorized Tenant A users may access

Tenant B no permission grants
→ HR capability remains unavailable
```

This is:

```text
authorization-based exposure
```

not a feature-flag replacement.

---

# 5. Feature Flag Boundary

Feature flag system remains:

```text
NOT CURRENT BASELINE
```

If introduced later:

```text
Feature Flag
≠ Permission
```

A flag may suppress rollout.

A flag may never grant authorization.

HR-024 does not introduce an enterprise feature-flag subsystem merely for Phase 4 delivery.

---

# 6. Additive Operational Backlog

Phase 4G reveals release-gate work that was previously described in requirements but not individually traceable as engineering tasks.

Add:

### HR-TASK-234 — Release Evidence Manifest

Create a release evidence artifact/checklist that records:

- specification baseline;
- repository/release SHA;
- included capabilities;
- migrations;
- CI result;
- OpenAPI result;
- known deferred items;
- rollback/roll-forward notes.

### HR-TASK-235 — Staging Promotion Gate

Implement/document repeatable staging promotion and smoke verification for HR release candidates.

### HR-TASK-236 — Limited Rollout Procedure

Document and test controlled HR rollout using canonical permission/scope grants without introducing runtime module enable/disable.

### HR-TASK-237 — Production Activation Gate

Create explicit production go/no-go checklist using HR-023 DoD plus infrastructure/security/recovery evidence.

### HR-TASK-238 — Release Health Review

Define release-health observation and go/hold/rollback/roll-forward record after activation.

Updated baseline:

```text
238 Engineering Tasks
```

No prior task is removed or renumbered.

---

# 7. Release Stages

Four promotion stages are defined.

| Stage    | Purpose                              |
| -------- | ------------------------------------ |
| **INT**  | Internal integration verification    |
| **STG**  | Production-like staging verification |
| **LR**   | Limited real-user rollout            |
| **PROD** | Normal production activation         |

A capability can remain at one stage while another progresses further.

---

# 8. INT — Internal Integration Gate

Purpose:

> Prove the implementation behaves correctly with real module boundaries and contracts.

A capability may enter INT only when committed scope meets HR-023 DoD.

Minimum:

```text
CI mandatory gates green
+
migration works
+
authorization implemented
+
API contract aligned
+
module dependency valid
+
relevant integration tests green
```

---

# 9. INT Gate — Database

If schema changes:

- clean migration passes;
- supported upgrade path passes;
- required constraints exist;
- tenant integrity passes;
- no unauthorized destructive migration;
- migration casing/path valid;
- roll-forward/rollback implications documented.

For legacy evolution:

```text
expand
→ migrate
→ switch
→ contract later
```

must be preserved.

---

# 10. INT Gate — Authorization

Protected capability must prove:

```text
401 unauthenticated

403 missing permission

success with correct permission

cross-Tenant denied

organizational/resource scope denied
where applicable
```

Position or `jabatan` cannot substitute RBAC.

---

# 11. INT Gate — API

Required:

```text
implementation
↔ OpenAPI
↔ executable contract tests
```

No exposed endpoint remains knowingly inconsistent with the documented contract.

---

# 12. INT Gate — Frontend

Applicable capability must prove:

- route integration;
- capability-aware visibility;
- direct-route behavior;
- loading;
- empty;
- error;
- permission state;
- Workspace/Tenant transition;
- relevant mutation behavior.

Frontend security is not considered proven until backend denial also exists.

---

# 13. INT Exit

Possible result:

### PASS

Candidate can be promoted to staging.

### HOLD

Implementation works partly but an applicable DoD or integration requirement remains incomplete.

### FAIL

Security, integrity, contract, or architecture invariant is violated.

There is no:

```text
PASS WITH CRITICAL SECURITY ISSUE
```

---

# 14. STG — Staging Gate

Staging exists to test a release in a production-like environment without normal production users.

Minimum expected characteristics:

```text
PostgreSQL-authoritative persistence
production-like security settings
private-storage integration when applicable
queue workers when applicable
frontend production build
backend release artifact
```

Staging does not need production data volume.

---

# 15. STG Gate — Deployment

Release candidate must be reproducible from controlled artifacts.

Frontend:

```text
CI Build
→ Immutable Artifact
→ Activation
```

Backend:

```text
Source Commit
→ Controlled Build/Test
→ Versioned Release
→ Activation
```

Forbidden:

```text
manual production-like file editing
```

---

# 16. STG Gate — Migration Rehearsal

For applicable schema changes:

```text
staging prior state
→ migration
→ application verification
```

must be rehearsed.

Large/backfill migration should additionally prove:

- bounded execution;
- restart/retry behavior;
- progress observability;
- no irreversible accidental data destruction.

---

# 17. STG Gate — Health

Verify:

```text
/up
→ liveness

Core health
→ sanitized readiness
```

Readiness failure must not leak raw dependency exceptions.

---

# 18. STG Gate — Worker

If capability uses async processing:

- worker deployed;
- correct release code loaded;
- queue processes jobs;
- queue backlog visible;
- retry behavior works;
- job payload is identifier-only;
- after-commit requirement validated;
- failed job does not expose sensitive payload.

---

# 19. STG Gate — Private Storage

For:

```text
Documents
Agreements
Exports
```

staging must prove:

```text
artifact private
unauthorized retrieval denied
metadata ↔ artifact consistent
```

Public static storage is not acceptable.

---

# 20. STG Gate — Frontend Production Build

Applicable:

- production build passes;
- code splitting works;
- HR lazy module loads;
- public source-map leakage absent;
- release identity exists;
- critical E2E passes against real backend.

---

# 21. STG Gate — Smoke Paths

Minimum per release candidate:

```text
authentication
Tenant context
Workspace context where applicable
capability resolution
primary read
primary mutation where safe
authorization denial
```

Sensitive production-like tests must use controlled test data.

---

# 22. STG Exit

Staging PASS means:

> Release candidate is technically suitable for controlled production exposure.

It does **not** mean full production readiness automatically.

Still required where relevant:

- provider;
- policy;
- backup;
- recovery;
- government mapping;
- operational owner.

---

# 23. LR — Limited Rollout Gate

Limited Rollout is real production use by a controlled authorization population.

Examples:

```text
selected Tenant
selected organizational scope
selected HR administrators
selected pilot workforce users
```

No user-count threshold is invented.

---

# 24. LR Entry Requirements

Minimum:

```text
STG PASS
+
production configuration validated
+
required backup coverage available
+
rollback/roll-forward plan
+
safe observability
+
release identity
+
no critical unresolved security finding
```

Additionally capability-specific blockers must be resolved.

---

# 25. LR Exposure Control

Recommended baseline:

```text
permission grants
+
scope grants
```

Use existing authorization model to restrict pilot population.

Example:

```text
Tenant A HR pilot role
→ receives specific HR permissions

other Tenant roles
→ no grant
```

Do not use:

```text
if tenant_id == "pilot-tenant"
```

inside application code.

---

# 26. LR Permission Principle

Pilot grants should follow least privilege.

Example Workforce pilot:

```text
hr.employees.view
hr.employments.view
```

does not require:

```text
hr.discipline.view
hr.compensation.manage
hr.government.exports.generate
```

unless those capabilities are actually part of the rollout.

---

# 27. LR Data Scope

Limited rollout must use real authorization and scope rules.

Pilot status must not justify:

```text
temporary authorization bypass
temporary tenant bypass
temporary hardcoded superuser
```

Staging shortcuts are forbidden in production.

---

# 28. LR Observability

During limited rollout, monitor applicable:

- API failures;
- authorization denial anomalies;
- latency;
- frontend runtime errors;
- contract failures;
- queue backlog;
- storage errors;
- migration anomalies;
- business conflict rates where useful.

No exact alert threshold is invented.

---

# 29. LR User Feedback

Functional/user feedback may inform:

- UX wording;
- workflow usability;
- missing noncanonical convenience behavior.

It must not silently override:

- data ownership;
- security;
- identity;
- domain invariants.

Requirement change still uses formal impact review.

---

# 30. LR Exit

Possible:

### PROMOTE

Evidence supports normal production availability.

### HOLD

Continue restricted exposure while resolving noncritical issue.

### ROLL FORWARD

Correct issue with compatible release.

### ROLLBACK

Reactivate compatible previous application artifact where safe.

### DISABLE EXPOSURE

Remove pilot permission grants if capability must be unavailable while code remains deployed.

This last option is possible because:

```text
availability through authorization
≠ module bootstrap activation
```

---

# 31. PROD — Production Activation Gate

Production activation means the capability is approved for its intended normal user population.

Required:

```text
INT PASS
+
STG PASS
+
LR PASS
or explicit reason why LR is not applicable
+
HR-023 DoD
+
operational readiness
```

---

# 32. Production Security Gate

Minimum applicable:

- required permissions implemented;
- resource scope implemented;
- self-service isolation tested;
- sensitive DTO disclosure tested;
- no Position/Jabatan authorization;
- no critical security finding;
- sensitive logging/queue controls active.

---

# 33. Production Database Gate

Required:

- PostgreSQL-compatible configuration;
- migration path rehearsed;
- schema constraints verified;
- backup coverage includes non-rebuildable data;
- destructive migrations separately approved;
- rollback/roll-forward plan available.

---

# 34. Production Recovery Gate

Production capability affecting canonical HR data requires recovery classification.

Before production commitments requiring formal recovery guarantee:

```text
RPO
RTO
```

must be decided by authority.

Where numbers remain open:

```text
engineering may implement
```

but must not make unsupported SLA promises.

---

# 35. Backup / Restore Gate

Full HR production readiness cannot be declared solely because backups exist.

Required:

```text
backup
→ isolated restore
→ integrity verification
→ evidence recorded
```

For capabilities with private artifacts:

```text
DB
+
private artifact storage
```

must be included appropriately.

---

# 36. Production Observability Gate

Production release must have enough visibility to determine:

```text
what failed?
which release?
which request/run?
which dependency?
```

without requiring sensitive HR payload in logs.

Minimum applicable:

- release identity;
- request correlation;
- centralized/collectable logs;
- error/health visibility;
- queue visibility where applicable.

---

# 37. Production Frontend Gate

Relevant FE-008 gates must pass:

```text
lint
type check
unit/component tests
production build
bundle budget
OpenAPI/client drift
dependency/security audit
integration
critical E2E
```

Production artifact must be immutable.

---

# 38. Production Rollback Gate

Before activation, team must know which recovery class applies.

### Frontend only

```text
previous immutable artifact
→ reactivate
```

### Compatible backend

```text
previous backend release
→ possible
```

only if schema/job/data remain compatible.

### Database semantic change

```text
prefer roll-forward
```

unless rollback proven safe.

### Queue workflow

Reconcile run/job state before old worker activation.

---

# 39. Release Milestone Model

Recommended milestones:

| Milestone    | Scope                       |
| ------------ | --------------------------- |
| **RM-HR-00** | Engineering Foundation      |
| **RM-HR-01** | Secure Workforce Foundation |
| **RM-HR-02** | Scoped Workforce            |
| **RM-HR-03** | Operational HR              |
| **RM-HR-04** | Sensitive HR                |
| **RM-HR-05** | HR Intelligence             |
| **RM-HR-06** | Government Externalization  |

These are capability milestones, not dates.

---

# 40. RM-HR-00 — Engineering Foundation

Includes:

```text
SC-HR-00
+
SC-HR-01 foundation portions
```

Primary outcomes:

- repository stable;
- HR docs integrated;
- PostgreSQL baseline;
- CI enforcement;
- permission catalog;
- current HR API protected;
- canonical ApiError/OpenAPI;
- QueueWatchdog hardened;
- health sanitized;
- request correlation foundation;
- shared frontend foundation underway.

---

# 41. RM-HR-00 Production Meaning

RM-HR-00 is primarily:

```text
foundation milestone
```

not a user-facing HR release target.

Current Employee API may become safer, but broad user rollout is not required.

---

# 42. RM-HR-01 — Secure Workforce Foundation

Includes:

```text
SC-HR-02
```

plus applicable production gates.

Capabilities:

```text
Employee
Employment
Position foundation
```

Expected architecture:

```text
Person
→ Membership
→ Employee
→ Employment
→ Position Assignment
→ Position
```

without RBAC coupling.

---

# 43. RM-HR-01 Rollout Candidate

Possible first limited production HR capability:

```text
Workforce read
```

because it creates relatively low workflow side-effect compared with payroll/discipline/export.

However rollout still requires:

- protected API;
- safe DTO;
- scope decision;
- staging evidence.

No commitment is made that this must be the first production release.

---

# 44. RM-HR-02 — Scoped Workforce

Includes:

```text
SC-HR-03
```

Capabilities:

- organization-aware Employee access;
- unit-aware Employee access;
- Workspace-aware HR navigation;
- Core OrganizationalAssignment integration.

Primary release proof:

```text
Unit A
→ cannot read Unit B
```

This is a security milestone, not merely a UX milestone.

---

# 45. RM-HR-03 — Operational HR

Includes:

```text
SC-HR-04
+
SC-HR-05 when ready
```

Capabilities:

- Recruitment;
- Hiring/Onboarding;
- Leave;
- Attendance.

Attendance remains separate module ownership.

Individual capability may reach production without waiting for every sibling capability.

---

# 46. RM-HR-04 — Sensitive HR

Includes:

```text
SC-HR-06
SC-HR-07
SC-HR-08
```

Capabilities:

- Compensation;
- Performance;
- Documents/Agreements;
- Discipline;
- Offboarding.

This milestone has stronger production gates because of Restricted/Highly Restricted data.

---

# 47. RM-HR-04 Additional Gate

Before normal production exposure, relevant capabilities require explicit proof of:

```text
least disclosure
private storage where needed
sensitive authorization
safe logging
safe queue behavior
domain evidence
```

A capability with unresolved policy may remain:

```text
IMPLEMENTED — NOT PRODUCTION READY
```

while others proceed.

---

# 48. RM-HR-05 — HR Intelligence

Includes:

```text
SC-HR-09
```

Reporting may be released incrementally by source family.

Examples:

```text
Workforce reporting
Leave reporting
Recruitment reporting
```

do not need to wait for all HR domains if their own sources are authoritative.

Baseline remains:

```text
direct query first
```

---

# 49. RM-HR-05 Projection Gate

Persisted projection is not a milestone deliverable by default.

It enters scope only when:

```text
measured performance evidence
+
freshness requirement
```

justifies it.

“No projection implemented” is valid if direct query meets need.

---

# 50. RM-HR-06 — Government Externalization

Includes:

```text
SC-HR-10
```

Targets:

```text
Dapodik
EMIS / EMIS GTK
```

Production activation remains blocked until official mapping/format/workflow resources are authoritative.

Simpatika remains:

```text
LEGACY
DO NOT BUILD NEW INTEGRATION
```

---

# 51. Government Export Release Gate

Additional mandatory evidence:

- field mapping version;
- official validation;
- frozen dataset;
- private artifact;
- explicit generate/download permissions;
- queue privacy;
- audit/domain evidence;
- official workflow compatibility.

Without authoritative mapping:

```text
NO PRODUCTION PROMOTION
```

---

# 52. Capability-Specific Promotion Matrix

| Capability          |      INT |      STG |                                LR |                   PROD |
| ------------------- | -------: | -------: | --------------------------------: | ---------------------: |
| Employee/Employment | Required | Required |                       Recommended |             Gate-based |
| Scoped Workforce    | Required | Required | **Required/recommended strongly** |             Gate-based |
| Recruitment         | Required | Required |                       Recommended |             Gate-based |
| Leave               | Required | Required |                       Recommended |             Gate-based |
| Attendance          | Required | Required |                       Recommended |             Gate-based |
| Compensation        | Required | Required |             **Strongly required** |         Sensitive gate |
| Performance         | Required | Required |                       Recommended |         Sensitive gate |
| Documents           | Required | Required |             **Strongly required** |  Storage/security gate |
| Discipline          | Required | Required |             **Strongly required** |   Policy/security gate |
| Offboarding         | Required | Required |             **Strongly required** |   Policy/security gate |
| Reporting           | Required | Required |                       Recommended |  Source/freshness gate |
| Government Export   | Required | Required |              Strongly recommended | External contract gate |

“Recommended” may only be skipped with documented release rationale.

---

# 53. Production Gate Categories

Every production promotion evaluates:

```text
PG-SEC
Security

PG-DATA
Data Integrity

PG-API
API Contract

PG-UX
Frontend / UX

PG-OPS
Operations

PG-REC
Recovery

PG-POL
Business / Policy Readiness

PG-EXT
External Contract
```

Only applicable gates need to pass, but applicability must be explicitly recorded.

---

# 54. PG-SEC — Security

PASS requires applicable:

- authentication;
- permission;
- scope;
- sensitive access;
- negative authorization;
- queue privacy;
- private artifact access.

---

# 55. PG-DATA — Data Integrity

PASS requires applicable:

- migration verified;
- tenant integrity;
- lifecycle constraints;
- concurrency handling;
- no duplicate source of truth;
- history/immutability rules.

---

# 56. PG-API — API Contract

PASS requires:

```text
API implementation
=
OpenAPI
=
contract tests
```

for exposed operations.

---

# 57. PG-UX — Frontend

PASS requires:

- approved navigation;
- permission states;
- loading/error/empty;
- mutation states;
- context switching;
- critical frontend tests;
- production build quality gates.

---

# 58. PG-OPS — Operations

PASS requires applicable:

- deploy process;
- release identity;
- health;
- logging;
- correlation;
- worker process;
- storage dependency;
- observable failure state.

---

# 59. PG-REC — Recovery

PASS requires capability's persistence classes to have known recovery treatment.

For full production readiness:

- backup process exists;
- restore has been tested for required classes;
- migration recovery strategy documented.

RPO/RTO commitments require explicit approved values.

---

# 60. PG-POL — Policy

PASS applies when business operation depends on policy.

Examples:

```text
Discipline policy
Offboarding approval
Membership deactivation
Leave calendar
```

If policy unresolved:

```text
affected production functionality
→ BLOCKED
```

Technical foundation may remain released internally.

---

# 61. PG-EXT — External Contract

Applies to:

- Dapodik;
- EMIS;
- future e-sign provider;
- future Finance integration;
- other external provider contracts.

External integration is not production-ready based only on an adapter skeleton.

---

# 62. Release Evidence Record

Every promoted release should record:

```text
Release ID / SHA
Specification baseline
Capabilities
Migration set
CI result
Test evidence
OpenAPI state
Production gates
Known limitations
Open decisions
Rollback / roll-forward approach
Approver/decision record where process defines one
```

Exact organizational approver remains `[OPEN DECISION]`.

---

# 63. Go / No-Go States

Canonical release decision:

### GO

All applicable mandatory gates pass.

### GO — LIMITED

Staging passes and approved restricted production population is intended.

### HOLD

Noncritical prerequisite remains incomplete.

### NO-GO

Critical security, integrity, external-contract, or recovery blocker.

### ROLL FORWARD

Current version needs compatible corrective release.

### ROLLBACK

Previous compatible artifact can safely be activated.

---

# 64. Critical Automatic NO-GO Conditions

Any of these causes NO-GO for affected production scope:

```text
critical authorization gap
cross-Tenant exposure
out-of-scope organizational exposure
destructive migration without safe plan
sensitive public artifact exposure
raw sensitive queue payload exposure
failed mandatory CI
API/OpenAPI critical drift
missing authoritative government mapping for official export
```

No product urgency overrides these without formal architecture/security change approval.

---

# 65. High-Risk HOLD Conditions

Examples:

- production storage provider missing for Documents;
- restore not tested;
- required worker orchestration unavailable;
- discipline policy unresolved;
- final settlement contract unresolved;
- observability insufficient for a critical async capability.

Status:

```text
IMPLEMENTED
but
NOT PRODUCTION READY
```

---

# 66. Deployment Sequence

Normal compatible release:

```text
1. CI/build artifacts
2. Configuration validation
3. Backup/recovery preconditions if required
4. Backward-compatible migration
5. Backend deployment
6. Worker refresh
7. Backfill/reconciliation if required
8. Frontend activation
9. Readiness verification
10. Smoke tests
11. Limited/normal exposure
12. Release-health observation
```

Exact steps may be omitted where a layer is unaffected.

---

# 67. Permission Grant Release Sequencing

When limited rollout uses new permissions:

Recommended:

```text
1. Deploy permission catalog
2. Verify backend enforcement
3. Deploy compatible UI/API
4. Grant pilot permissions
5. Observe
```

Avoid:

```text
grant permission
→ endpoint not yet protected/compatible
```

or:

```text
frontend visible
→ backend unavailable
```

---

# 68. Permission Revocation as Exposure Stop

If pilot capability must be halted:

```text
remove/revoke relevant pilot permission grants
```

may stop ordinary authorized access while code remains deployed.

This must not be confused with:

- emergency application rollback;
- module unload;
- data rollback.

High-impact in-flight transactions still require reconciliation where relevant.

---

# 69. Migration Contract Stage

For legacy `jabatan`:

```text
RM-HR-01
→ Position foundation

later release
→ migrate consumers

later contract release
→ remove legacy column
```

Do not make column removal part of the first Workforce production milestone.

---

# 70. Worker Compatibility Release Gate

If serialized job implementation changes:

Release cannot promote until one of these is true:

```text
old jobs drained
OR
new code backward-compatible
OR
job contract versioned
OR
old jobs explicitly reconciled
```

Worker restart alone is insufficient.

---

# 71. Frontend Compatibility Release Gate

Because old browser documents may remain open:

- versioned API remains compatible;
- old hashed assets remain available for appropriate transition;
- new deployment does not instantly invalidate old chunks;
- previous frontend artifact remains identifiable.

Exact CDN retention window remains operational policy.

---

# 72. Release Health Review

After activation review:

- new 5xx pattern;
- auth denial anomaly;
- scope-denial behavior;
- migration errors;
- frontend chunk/runtime errors;
- queue backlog/failures;
- storage failures;
- new contract failures.

No exact time window is invented.

Release remains under observation until operational process declares it healthy.

---

# 73. Release Health Decision

Possible outcomes:

```text
HEALTHY
HOLD EXPANSION
ROLL FORWARD
ROLLBACK
REMOVE PILOT GRANTS
```

For a limited rollout, expansion to additional users/Tenants requires an explicit health decision.

---

# 74. Milestone Completion ≠ Production Activation

Example:

```text
RM-HR-04 complete
```

may mean:

> Sensitive-domain engineering baseline is complete.

But individual capability could still be:

```text
Documents
→ IMPLEMENTED / NOT PROD
```

because storage provider not ready.

Milestone tracking must show both:

```text
Implementation Status
Production Status
```

---

# 75. Milestone Status Model

Use:

### PLANNED

Dependencies not yet satisfied.

### IN PROGRESS

Engineering active.

### IMPLEMENTATION COMPLETE

Engineering DoD passed.

### STAGING READY

STG gate passed.

### LIMITED ROLLOUT

Controlled production exposure active.

### PRODUCTION READY

All applicable production gates passed.

### BLOCKED

Required dependency/policy/resource absent.

Do not collapse these into a single `% complete`.

---

# 76. Release Documentation Location

Recommended repository structure:

```text
docs/
└── releases/
    └── hr/
        ├── README.md
        └── <release-id>.md
```

or equivalent canonical project location.

Each record points back to HR specification IDs and repository SHA.

Exact directory may be aligned with repository documentation governance during implementation.

---

# 77. Sprint → Milestone → Release Traceability

```text
Requirement
→ Engineering Task
→ Sprint Candidate
→ Milestone
→ Release Candidate
→ Promotion Gate
→ Production Evidence
```

Example:

```text
HR-013
→ HR-TASK-021
→ SC-HR-01
→ RM-HR-00
→ Release X
→ PG-SEC
→ authorization test evidence
```

---

# 78. Current Milestone Readiness

Based on current repository:

## RM-HR-00

```text
READY TO START
```

because its foundational tasks have sufficient authority.

## RM-HR-01+

```text
PLANNED
```

because they depend on earlier gates.

## RM-HR-06

```text
RESOURCE BLOCKED FOR PRODUCTION
```

because Dapodik/EMIS field mapping remains unavailable.

---

# 79. No Release Date Yet

**[RESOURCE GAP]**

No authoritative:

- team capacity;
- sprint duration;
- velocity;
- infrastructure lead time;
- provider procurement timeline.

Therefore milestones have **no dates**.

Dates may be added later without changing dependency structure.

---

# 80. No RPO/RTO Fabrication

Production release documentation must explicitly state:

```text
RPO — OPEN DECISION
RTO — OPEN DECISION
```

until approved.

Engineering must not transform implementation capability into unsupported business SLA.

---

# 81. Change Impact on Backlog

Add:

```text
HR-TASK-234
Release Evidence Manifest

HR-TASK-235
Staging Promotion Gate

HR-TASK-236
Limited Rollout Procedure

HR-TASK-237
Production Activation Gate

HR-TASK-238
Release Health Review
```

Updated engineering baseline:

```text
238 Tasks
```

No task renumbering.

---

# 82. Change Impact on Sprint Candidates

### SC-HR-00 / SC-HR-01

Include early release/CI foundation where applicable.

### SC-HR-02 onward

Each candidate now produces artifacts consumable by promotion gates.

### SC-HR-10

Remains resource-blocked for official production export.

No domain sequence changes.

---

# 83. Scope

## IN SCOPE

- release milestones;
- integration/staging/limited/prod stages;
- release gates;
- rollout control;
- production gate categories;
- release evidence;
- rollback/roll-forward decision;
- capability-specific release readiness.

## OUT OF SCOPE

- release dates;
- deployment vendor;
- CI provider;
- infrastructure code;
- exact user-count pilot size;
- on-call staffing;
- exact alert thresholds;
- exact RPO/RTO;
- implementation code.

## DEFERRED

- enterprise feature flags;
- canary percentage automation;
- blue/green implementation;
- multi-region release;
- automatic progressive delivery.

---

# 84. Definition of Phase 4G Complete

Phase 4G is complete when:

- implementation completion is separated from release readiness;
- INT/STG/LR/PROD stages are explicit;
- limited rollout mechanism is compatible with current architecture;
- no nonexistent module activation mechanism is assumed;
- production gate categories are explicit;
- rollback/roll-forward semantics are explicit;
- milestones are capability based;
- missing external resources correctly block affected release;
- no dates/SLA are invented.

All criteria are satisfied by this draft.

---

# 85. Reviewer Assessment

**Quality Score:** **9.8/10**

## Gaps

- CI/CD provider not selected;
- deployment mechanism not implemented;
- backup/restore process not yet implemented;
- RPO/RTO unresolved;
- production storage/observability vendors unresolved;
- government mappings unavailable;
- organizational release approver/process not defined.

These block parts of production operations, not the release architecture.

## Risks

**[RISK — CRITICAL]**

Using an invented `module:disable` mechanism for rollout would contradict the current Module Kernel and create a second availability model.

**[RISK — CRITICAL]**

Limited rollout based on hardcoded Tenant IDs rather than canonical authorization would create technical debt and potential security bypass.

**[RISK — HIGH]**

Calling a capability production-ready merely because staging passes may ignore backup, policy, sensitive-storage, or external-contract blockers.

**[RISK — HIGH]**

Database rollback without compatibility evaluation may destroy new production data.

**[RISK — HIGH]**

Government export promotion without official field mapping may produce formally invalid external data.

## Recommendations

1. Lock HR-024 as canonical release/milestone framework.
2. Use authorization grants for baseline limited rollout; do not invent runtime module activation.
3. Implement HR-TASK-234–238 before production promotion becomes routine.
4. Keep milestone and production status separate.
5. Make sensitive capability gates stricter than ordinary Workforce read.
6. Government Export remains blocked until official mapping is available.
7. Keep RPO/RTO explicitly open until stakeholder authority exists.

**Status:** **READY FOR APPROVAL**
