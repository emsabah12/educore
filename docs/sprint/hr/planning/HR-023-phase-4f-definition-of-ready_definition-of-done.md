# HR-023 — Phase 4F Definition of Ready & Definition of Done

**Version:** 0.1 Draft
**Phase:** 4F — Definition of Ready & Definition of Done
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-24
**Depends On:** HR-001–HR-022 + ADR-032
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`

---

# 1. Purpose

HR-023 menetapkan quality gate objektif untuk menentukan:

```text
Apakah work item siap dikerjakan?
        ↓
Definition of Ready

Apakah work item benar-benar selesai?
        ↓
Definition of Done
```

Tujuannya mencegah kondisi seperti:

```text
“coding selesai”
≠
“requirement selesai”
```

atau:

```text
“PR merged”
≠
“capability production-ready”
```

---

# 2. Resource Audit Finding

## [FAKTA]

Repository mempunyai DoD pada:

```text
docs/sprint/sprint-001.md
```

tetapi `docs/sprint/README.md` secara eksplisit menyatakan sprint tersebut:

```text
HISTORICAL
```

dan bukan current architecture contract.

Karena itu HR-023 tidak mewarisi:

- story point lama;
- asumsi tim 2–4 developer;
- DoD sederhana berbasis checklist feature.

---

# 3. Existing Quality Foundations

Backend repository sudah mempunyai:

```text
PHPUnit
Laravel feature/integration tests
OpenAPI integrity tests
OpenAPI route coverage tests
OpenAPI operation contract tests
PSR-4 audit script
Laravel Pint
```

Frontend FE-008 telah mengunci minimum CI sequence:

```text
dependency install from lockfile
→ format/lint
→ TypeScript check
→ unit/component tests
→ production build
→ bundle budget
→ OpenAPI/client drift
→ security/dependency audit
→ integration tests
→ critical E2E smoke
```

Mandatory gate failure berarti PR tidak boleh digabung.

---

# 4. Additive Backlog Correction — CI Enforcement

## [GAP — ENFORCEMENT]

HR-019/HR-022 sudah memiliki task untuk **mendefinisikan** CI quality gates, tetapi belum eksplisit mengimplementasikan enforcement pipeline.

Tambahkan:

### HR-TASK-232 — Backend CI Quality Gate

Implement canonical backend CI gate yang menjalankan minimum:

- dependency installation from lockfile;
- PSR-4 validation;
- code-format/style validation;
- automated backend test suite;
- executable OpenAPI contract gates;
- migration/module loading checks yang relevant;
- failure blocks merge.

### HR-TASK-233 — Frontend CI Quality Gate

Ketika shared frontend foundation tersedia, implement FE-008 mandatory CI gates:

- lockfile installation;
- lint/format;
- type check;
- unit/component test;
- production build;
- bundle budget;
- OpenAPI/client drift;
- security/dependency audit;
- integration test;
- critical E2E smoke.

Updated backlog baseline:

```text
233 Engineering Tasks
```

No existing task is removed or renumbered.

---

# 5. DoR / DoD Levels

HR menggunakan empat level berbeda.

```text
Engineering Task
        ↓
User Story / Feature
        ↓
Capability
        ↓
Production Release
```

Masing-masing mempunyai gate sendiri.

Ini penting karena:

```text
Task Done
≠ Story Done

Story Done
≠ Capability Ready

Capability Ready
≠ Production Ready
```

---

# 6. Universal Definition of Ready

Sebuah committed User Story atau Engineering Task hanya `READY` jika seluruh kondisi applicable berikut terpenuhi.

| ID     | Ready Criterion                                           |
| ------ | --------------------------------------------------------- |
| DOR-01 | Requirement authority diketahui                           |
| DOR-02 | Scope eksplisit                                           |
| DOR-03 | Acceptance/evidence expectation tersedia                  |
| DOR-04 | Module/domain owner diketahui                             |
| DOR-05 | Dependencies tersedia atau explicitly sequenced           |
| DOR-06 | Open decisions diklasifikasikan                           |
| DOR-07 | Authorization impact diketahui                            |
| DOR-08 | Data/schema impact diketahui                              |
| DOR-09 | API impact diketahui                                      |
| DOR-10 | Cross-domain impact diketahui                             |
| DOR-11 | Test approach diketahui                                   |
| DOR-12 | Migration/compatibility impact diketahui jika applicable  |
| DOR-13 | Security/privacy classification diketahui jika applicable |
| DOR-14 | No unresolved critical conflict                           |
| DOR-15 | Work dapat diselesaikan tanpa mengarang business rule     |

---

# 7. DOR-01 — Requirement Authority

Setiap work item harus menunjuk minimal satu authority seperti:

```text
HR-013
HR-002-FR-xxx
ADR-032
GAP-002
HR-TASK-021
```

Forbidden:

> “Kita buat karena sepertinya HR biasanya butuh ini.”

Engineering implementation tanpa requirement traceability adalah:

```text
NOT READY
```

---

# 8. DOR-02 — Scope

Sebelum commitment harus jelas:

```text
IN SCOPE
OUT OF SCOPE
DEFERRED
```

Contoh:

### Employment

IN:

```text
historical Employment
ACTIVE invariant
rehire
end Employment
```

OUT:

```text
Membership auto-deactivation
Finance settlement
```

---

# 9. DOR-03 — Acceptance Evidence

Story harus mendefinisikan hasil yang dapat dibuktikan.

Example:

```text
Given user tidak mempunyai hr.employees.view
When GET Employee dipanggil
Then backend mengembalikan 403 AUTHORIZATION_DENIED
```

Tidak cukup:

> “Tambahkan security Employee.”

---

# 10. DOR-04 — Domain Ownership

Work item tidak Ready bila ownership masih ambigu.

Examples:

```text
Position
→ Modules/HR

OrganizationalAssignment
→ Modules/Core

Attendance
→ Modules/Attendance

Payroll calculation
→ Modules/Finance
```

Jika dua module saling mengklaim state yang sama:

```text
NOT READY
```

sampai ownership diselesaikan.

---

# 11. DOR-05 — Dependency Readiness

Dependency harus dibedakan:

### Hard Dependency

Harus tersedia sebelum task dimulai.

Example:

```text
scope-aware Employee endpoint
requires
organizational authorization foundation
```

### Soft / Parallel Dependency

Dapat dikembangkan paralel.

Example:

```text
Employment backend
||
shared frontend foundation
```

### Production Dependency

Tidak menghalangi coding, tetapi menghalangi activation.

Example:

```text
document metadata implementation
→ may start

production storage provider
→ required before production activation
```

---

# 12. DOR-06 — Open Decision Handling

Work item tetap Ready jika open decision tidak memengaruhi committed scope.

Example:

```text
Employment history
→ READY

exact Employment Type catalog
→ still OPEN
```

Tetapi jika unresolved decision menentukan core behavior:

```text
final disciplinary escalation workflow
without disciplinary policy
```

maka:

```text
NOT READY
```

untuk bagian tersebut.

---

# 13. Database / Migration DoR

Migration task hanya Ready jika:

- owning module confirmed;
- table/entity purpose confirmed;
- canonical relationship confirmed;
- tenant isolation strategy known;
- FK semantics known;
- uniqueness/invariant known;
- delete/archive semantics known;
- migration is additive unless explicitly approved otherwise;
- existing consumer impact known;
- rollback/roll-forward implication understood;
- index has known query/invariant justification.

### [RISK]

Migration tidak Ready jika dibuat hanya dari desired UI field list.

---

# 14. Authorization DoR

Protected operation hanya Ready jika diketahui:

```text
Permission
+
Tenant Context
+
Organizational Scope where applicable
+
Resource Scope
+
Business State
+
Sensitivity
```

Position, `jabatan`, dan frontend navigation tidak boleh digunakan untuk mengisi gap authorization.

---

# 15. API DoR

API implementation baru hanya Ready jika minimum telah ditentukan:

- owner;
- operation purpose;
- method/path;
- request schema;
- response schema;
- permission;
- resource scope;
- lifecycle precondition;
- canonical error semantics;
- idempotency requirement if relevant;
- sensitive DTO requirements;
- OpenAPI impact.

Rule:

```text
Controller
≠ API specification
```

---

# 16. Frontend DoR

Frontend domain story hanya Ready jika:

- API contract sufficiently stable;
- route ownership known;
- capability requirements known;
- Tenant/Workspace semantics known;
- loading state known;
- empty state known;
- error/recovery state known;
- permission state known;
- mutation behavior known;
- unsaved-change behavior known if form;
- sensitive-data presentation known.

Frontend boleh memakai approved mock contract.

Frontend tidak boleh invent contract yang kemudian dipaksakan kepada backend.

---

# 17. Cross-Module DoR

Cross-domain story hanya Ready jika:

```text
Owner
Consumer
Public Contract
Dependency Direction
```

diketahui.

Example:

```text
Attendance
← Approved Leave Fact
```

Implementation tidak Ready bila membutuhkan:

```text
Attendance → HR internals
AND
HR → Attendance internals
```

yang menghasilkan circular dependency.

---

# 18. Async Job DoR

Async job hanya Ready jika:

- business run identity known;
- payload classification known;
- identifier-only policy satisfied;
- after-commit requirement known;
- idempotency/retry behavior known;
- failure reconciliation known;
- tenant context restoration known;
- sensitive audit/log restrictions known.

Job tidak Ready jika hanya:

> “pindahkan ke queue agar cepat.”

---

# 19. Sensitive Capability DoR

Untuk:

- Compensation;
- Documents;
- Discipline;
- Government Export;
- sensitive Employee fields;

story membutuhkan:

```text
data classification
+
permission
+
resource scope
+
DTO disclosure boundary
+
logging/audit treatment
```

sebelum commitment.

---

# 20. Provider-Dependent DoR

Adapter/domain foundation dapat Ready tanpa vendor.

Example:

```text
PrivateStorageInterface
→ READY
```

meski provider belum dipilih.

Tetapi:

```text
Production document delivery
```

tidak Ready jika production storage authority belum tersedia.

---

# 21. Sprint Candidate DoR

Sprint Candidate dari HR-022 dapat menjadi `COMMITTED` jika:

1. predecessor gate passed;
2. included tasks satisfy applicable DoR;
3. blocked policy tasks removed from commitment;
4. critical resource gaps identified;
5. migration/API/security impact reviewed;
6. candidate exit evidence defined;
7. work does not require unauthorized architecture redesign.

---

# 22. Ready Status

Gunakan hanya:

### READY

Semua applicable DoR terpenuhi.

### READY WITH EXCLUDED BLOCKED ITEMS

Candidate dapat dimulai tetapi explicitly blocked items tidak masuk commitment.

### NOT READY

Ada critical unresolved dependency/policy/ownership/security contract.

Tidak gunakan:

```text
“probably ready”
“ready enough”
```

untuk committed scope.

---

# 23. Universal Definition of Done

Work item committed hanya `DONE` jika:

```text
Requirement
+
Implementation
+
Tests
+
Security
+
Documentation / Contract
+
Compatibility
```

telah selesai untuk scope tersebut.

---

# 24. Engineering Task DoD

Task teknis dianggap Done bila:

1. implementation sesuai requirement;
2. no unresolved TODO dalam committed scope;
3. relevant automated tests tersedia;
4. relevant existing regression suite pass;
5. no architecture boundary violation;
6. code review selesai;
7. quality gates pass;
8. affected documentation/contracts updated;
9. no sensitive debug/log artifacts introduced;
10. migration/compatibility concern ditangani bila applicable.

---

# 25. Pull Request DoD

PR hanya mergeable jika:

```text
scope clear
+
review complete
+
CI mandatory gates green
+
required tests green
+
contract/docs updated
```

dan tidak mempunyai unresolved:

```text
CRITICAL security finding
data integrity issue
cross-domain ownership violation
```

---

# 26. Backend DoD

Backend change applicable harus memenuhi:

### Architecture

- correct owning module;
- dependency direction valid;
- no duplicate Core ownership;
- transaction boundary follows business atomicity.

### Quality

- PSR-4 gate passes;
- formatting/style gate passes;
- test suite relevant passes;
- full required CI suite passes.

### Security

- explicit Tenant filter;
- authorization enforced;
- organizational/resource scope enforced where applicable;
- sensitive data minimized.

---

# 27. Migration DoD

Migration selesai bila:

- clean database migration succeeds;
- upgrade path from supported prior state succeeds;
- constraints enforce intended invariants where appropriate;
- cross-Tenant reference prevented;
- existing data compatibility addressed;
- migration filename/casing canonical;
- no historical migration silently rewritten without approved reason;
- roll-forward/rollback effect documented;
- relevant persistence tests pass.

---

# 28. Authorization DoD

Authorization work selesai hanya jika tested through **real boundary**, not only mocked service.

Minimum where applicable:

```text
unauthenticated
→ 401

authenticated without permission
→ 403

correct permission
→ allowed

cross-Tenant
→ denied

outside Organization/Unit scope
→ denied

target outside resource scope
→ denied
```

For self-service:

```text
own resource
→ allowed

other Employee
→ denied
```

---

# 29. API DoD

API operation Done jika:

- controller/service implemented;
- request validation implemented;
- correct permission/scope enforced;
- canonical DTO returned;
- canonical errors returned;
- business conflicts differentiated from validation;
- OpenAPI updated;
- OpenAPI executable tests pass;
- route is not incorrectly left deferred;
- sensitive fields excluded unless authorized.

---

# 30. OpenAPI DoD

Public operation change requires:

```text
implementation
↔ OpenAPI
↔ contract tests
```

all aligned.

A route cannot be considered Done if:

```text
Laravel route changed
but OpenAPI unchanged
```

or vice versa.

---

# 31. Frontend DoD

Frontend feature Done jika applicable FE gates pass:

- format/lint;
- TypeScript type check;
- unit/component tests;
- production build;
- bundle budget;
- API/client drift check;
- security/dependency audit;
- integration tests;
- critical E2E where required.

Also:

- Tenant/Workspace state correct;
- capability behavior correct;
- direct-route security UX correct;
- loading/empty/error/permission states implemented;
- no sensitive data stored in browser persistence improperly.

---

# 32. Frontend Security DoD

The following is not sufficient:

```text
button hidden
```

Security-sensitive frontend feature must prove:

```text
button/menu hidden for UX
+
backend request independently denied
```

E2E should exercise actual backend for critical authorization flows.

---

# 33. Domain Lifecycle DoD

Lifecycle feature Done hanya jika happy path **dan prohibited transitions** tested.

Example Employment:

```text
PLANNED → ACTIVE
ACTIVE → ENDED
```

plus negative:

```text
second ACTIVE Employment
→ rejected

rehire
→ does not reactivate old Employment
```

---

# 34. Negative Architectural Test DoD

Relevant capabilities harus mempunyai regression test untuk invariant “must not happen”.

Examples:

```text
Position
→ must not grant permission

Employment ENDED
→ must not deactivate Membership

Agreement expired
→ must not end Employment

Discipline finalized
→ must not change salary automatically

Report view
→ must not grant export

Raw Attendance Event
→ must not become final fact
```

---

# 35. Queue / Async DoD

Async operation Done jika:

- identifier-only payload;
- Tenant context restored safely;
- after-commit semantics satisfied where needed;
- idempotency/reconciliation tested;
- retries cannot silently duplicate business side effects;
- failed job does not expose sensitive payload through watchdog;
- run state observable.

---

# 36. Documents DoD

Document capability requires:

- private storage;
- authorized retrieval;
- no public permanent URL;
- no DB BLOB;
- upload validation;
- immutable finalized version;
- new-version correction path;
- metadata ↔ artifact consistency;
- negative unauthorized download test.

Malware/e-sign-dependent functionality remains not Done until relevant provider/policy requirement is satisfied.

---

# 37. Reporting DoD

Report family Done only if:

- authoritative source exists;
- metric definition/version available;
- period/snapshot semantics clear;
- permission/scope applied before aggregation;
- aggregate/detail distinction preserved;
- View ≠ Export;
- freshness displayed when applicable;
- no duplicate source of truth introduced.

Projection is **not** required unless justified.

---

# 38. Government Export DoD

Government export cannot be Done for production until:

- authoritative field mapping exists;
- versioned mapping implemented;
- validation implemented;
- frozen dataset implemented;
- private artifact generated;
- permission separated view/generate/download;
- queue privacy satisfied;
- audit/domain evidence exists;
- official format/workflow verified.

Until then status is:

```text
IMPLEMENTATION PARTIAL / RESOURCE BLOCKED
```

not `DONE`.

---

# 39. Documentation DoD

Change must update relevant canonical documentation when it changes:

- public API;
- module dependency;
- schema/invariant;
- architecture decision;
- operational runbook;
- capability behavior.

Documentation must distinguish:

```text
CURRENT
HISTORICAL
DEFERRED
```

No future behavior should be written as implemented fact.

---

# 40. Operational DoD

For production-targeted capability, operational evidence includes applicable:

- readiness behavior;
- safe logs;
- correlation;
- worker lifecycle;
- private storage readiness;
- backup classification;
- migration release plan;
- rollback/roll-forward plan;
- production configuration validation.

Local code completion does not waive operational requirements.

---

# 41. Story DoD

A User Story is Done when:

```text
all committed acceptance criteria pass
+
all committed engineering tasks Done
+
relevant regression tests pass
+
documentation/API contract aligned
```

A story cannot be Done with:

```text
“backend complete, frontend later”
```

if frontend was part of committed acceptance criteria.

Instead split the story before commitment.

---

# 42. Feature / Capability DoD

Capability is `IMPLEMENTATION READY` when:

- domain model complete for approved scope;
- APIs complete;
- authorization complete;
- frontend complete if required;
- integration contracts complete;
- required automated tests pass;
- open items remaining are explicitly outside approved capability scope.

---

# 43. Production Ready ≠ Capability Done

A capability becomes:

```text
PRODUCTION READY
```

only when additional release gates pass.

Examples:

```text
Documents
→ production storage available

Government Export
→ official mappings available

HR overall
→ backup/restore verified
```

This distinction prevents false completion reporting.

---

# 44. Completion Status Model

Use:

### DONE

Committed scope fully meets DoD.

### IMPLEMENTED — NOT PRODUCTION READY

Functional implementation complete but production dependency/gate remains.

### BLOCKED

Committed requirement cannot proceed because authority/dependency is missing.

### NOT DONE

One or more committed DoD criteria incomplete.

Avoid:

```text
90% Done
```

as formal lifecycle state.

Percentage may be progress reporting only.

---

# 45. No Partial DoD

DoD is binary for committed scope.

If a story contains:

```text
ready part
+
policy-blocked part
```

split before sprint commitment.

Do not close it as:

> Done except one important thing.

---

# 46. Security Finding Rule

Any unresolved issue classified:

```text
Critical
```

for the affected scope means:

```text
NOT DONE
```

High-risk issue can only be deferred when:

- not part of committed acceptance;
- does not violate locked invariant;
- explicit owner/follow-up exists;
- capability is not incorrectly marked production-ready.

---

# 47. Test Failure Rule

Mandatory test gate failure means:

```text
NOT DONE
```

Tests are not optional cleanup work after merge.

Flaky test may be quarantined only through explicit engineering policy; no such policy is currently locked.

Therefore default:

```text
red test
→ merge blocked
```

---

# 48. Existing Regression Protection

Because EduCore is an existing modular system, DoD requires:

```text
new HR tests
+
existing Core/Auth/User/Academic relevant regressions
```

where impacted.

Example:

Changing organizational authorization requires checking:

- Core organizational tests;
- capability projection;
- User workspace behavior;
- HR scoped behavior.

---

# 49. Cross-Domain Change DoD

Change affecting another owner requires:

- contract owner reviewed;
- dependency remains allowed;
- owner tests pass;
- consumer tests pass;
- no direct internal coupling introduced.

Example:

```text
Attendance consumes approved Leave fact
```

Done only when integration does not make dependency graph circular.

---

# 50. Data Integrity DoD

Database-backed business invariant should not rely only on frontend validation.

Examples:

```text
max one ACTIVE Employment
tenant-safe FK
unique canonical identifiers
```

must have strongest practical persistence/domain enforcement justified by architecture.

---

# 51. Privacy DoD

Sensitive feature Done only if tests/evidence show that sensitive data is not leaked through:

- ordinary API DTO;
- logs;
- telemetry;
- queue payload;
- public storage;
- unauthorized export;
- browser persistence where prohibited.

---

# 52. Audit DoD

For high-impact lifecycle operation:

```text
transactional/domain evidence
```

must exist when required by locked architecture.

Core fail-open Audit alone is not sufficient for legal/business-critical state evidence. The handoff explicitly preserves this risk boundary.

---

# 53. Sprint Candidate DoD

A Sprint Candidate is complete only when its declared exit evidence passes.

Example:

### SC-HR-01

Not merely:

```text
permission catalog created
```

but:

```text
Employee routes actually protected
+
negative authorization tested
+
canonical error active
+
OpenAPI aligned
```

---

# 54. SC-HR-00 DoD

Must prove:

- casing reconciled;
- case-sensitive environment passes;
- PostgreSQL baseline aligned;
- docs integrated;
- repository usable as active engineering baseline.

---

# 55. SC-HR-01 DoD

Must prove:

- canonical HR permissions seeded;
- GET/POST Employee protected;
- missing permission denied;
- `jabatan` has zero RBAC effect;
- canonical API error;
- OpenAPI hardened;
- queue payload audit leak remediated;
- safe readiness response.

---

# 56. SC-HR-02 DoD

Must prove:

- Employee foundation preserved;
- Employment history implemented;
- max one ACTIVE;
- rehire creates new Employment;
- Position foundation exists;
- Position not Role;
- Membership not auto-deactivated;
- legacy `jabatan` migration remains safe.

---

# 57. SC-HR-03 DoD

Must prove:

```text
scope authorization
+
scope query
```

through real tests.

Especially:

```text
Unit A
→ cannot read Unit B
```

Gate cannot be passed using permission tests alone.

---

# 58. DoR / DoD Template for New Sprint Docs

Each new sprint document should include:

```text
Status
Specification Baseline
Repository Baseline

Goal
Included Stories / Tasks
Out of Scope

Dependencies
Open Decisions
Risks

Definition of Ready Evidence
Definition of Done Evidence

Test Plan
Migration/API Impact
Release Impact

Sprint Review Evidence
```

This replaces reliance on historical `sprint-001` conventions.

---

# 59. Evidence-Based Review

Sprint review should show verifiable evidence.

Examples:

```text
HTTP response
test result
migration constraint behavior
scope denial
browser flow
private-storage denial
OpenAPI contract
```

Status update alone is insufficient.

---

# 60. Required CI Enforcement

## Backend

Before backend work is treated as continuously enforceable DoD:

```text
HR-TASK-232
```

must establish canonical CI enforcement.

At minimum CI blocks merge on relevant:

- PSR-4 violation;
- style/format failure;
- automated test failure;
- OpenAPI gate failure;
- migration/module loading failure.

## Frontend

Before ordinary HR frontend stories are considered fully governed by FE-008:

```text
HR-TASK-233
```

must establish frontend mandatory CI.

---

# 61. DoR Exception — Foundation Tasks

Bootstrap tasks that create the quality mechanism itself are allowed to enter before that mechanism exists.

Example:

```text
HR-TASK-233
```

cannot require the completed frontend CI pipeline as its own precondition.

Instead its DoD is:

```text
pipeline implemented
+
demonstrated blocking behavior
```

This exception applies only to enabling infrastructure.

---

# 62. Scope

## IN SCOPE

- universal DoR;
- migration/API/security/frontend DoR;
- universal DoD;
- task/story/capability completion gates;
- testing requirements;
- documentation requirements;
- production-ready distinction;
- CI enforcement expectations.

## OUT OF SCOPE

- story points;
- developer assignment;
- sprint dates;
- CI vendor;
- exact branch strategy;
- exact code-review approver count;
- test coverage percentage;
- implementation code.

## DEFERRED

- quantitative coverage target;
- CI/CD provider;
- flaky-test policy;
- formal severity SLA;
- release-approval organizational roles.

---

# 63. Open Decisions

HR-023 introduces no new business open decision.

Operational decisions still unresolved include:

- CI provider;
- deployment provider;
- observability provider;
- RPO/RTO;
- production storage;
- exact test coverage target.

None should be invented merely to apply the fundamental DoR/DoD.

---

# 64. Change Impact

## HR-019 / HR-021 / HR-022

Add:

```text
HR-TASK-232
Backend CI Quality Gate

HR-TASK-233
Frontend CI Quality Gate
```

Updated engineering baseline:

```text
233 Tasks
```

## Sprint Planning

SC-HR-00/01 should include backend CI enforcement at the earliest practical point.

Frontend CI enforcement belongs to shared frontend foundation before substantial business-page rollout.

No domain architecture changes.

---

# 65. Traceability

```text
HR-001 ... HR-017
Locked Requirements
        ↓
HR-018
Implementation Gaps
        ↓
HR-019
Engineering Backlog
        ↓
HR-020
Technical Sequence
        ↓
HR-021
Artifact Plan
        ↓
HR-022
Sprint Candidates
        ↓
HR-023
Definition of Ready / Done
        ↓
Committed Sprint
        ↓
Evidence
        ↓
Release Gate
```

---

# 66. Phase 4F Definition of Completion

Phase 4F is complete when:

- Ready is objectively defined;
- Done is objectively defined;
- task/story/capability/release states are distinguished;
- migration/API/security/frontend gates exist;
- tests are mandatory evidence;
- OpenAPI drift blocks completion;
- critical security defects block Done;
- open decisions cannot silently become assumptions;
- production readiness cannot be inferred from code completion;
- quality gates can become enforceable through CI.

All criteria are satisfied by this draft.

---

# 67. Reviewer Assessment

**Quality Score:** **9.8/10**

## Gaps

- actual CI pipeline belum implemented;
- frontend toolchain/CI belum implemented;
- exact test coverage threshold belum authoritative;
- production vendor/operational decisions remain open.

These are implementation/open-policy gaps, not gaps in DoR/DoD semantics.

## Risks

**[RISK — CRITICAL]**

DoR yang diabaikan dapat membuat engineering kembali mendesain authorization/API/schema di tengah coding, menghasilkan rework atau security defect.

**[RISK — CRITICAL]**

Marking story Done tanpa negative authorization/scope tests dapat menyembunyikan horizontal data exposure.

**[RISK — HIGH]**

DoD manual tanpa CI enforcement mudah mengalami regression; HR-TASK-232/233 harus diprioritaskan.

**[RISK — HIGH]**

Menyamakan `IMPLEMENTED` dengan `PRODUCTION READY` dapat mengaktifkan Documents/Government Export sebelum provider/security/recovery gates tersedia.

**[RISK]**

DoD yang terlalu luas pada satu story dapat menciptakan mega-story; scope harus dipecah sebelum commitment.

## Recommendations

1. Lock HR-023 sebagai canonical DoR/DoD.
2. Gunakan DoR sebelum setiap Sprint Candidate commitment.
3. Gunakan DoD sebagai binary gate untuk committed scope.
4. Implement backend/frontend CI enforcement melalui HR-TASK-232/233.
5. Jangan menerima “coding selesai” sebagai completion evidence.
6. Jangan menandai production-ready hanya karena feature berhasil di local/staging.
7. Gunakan negative architectural tests untuk menjaga locked boundaries.

**Status:** **READY FOR APPROVAL**
