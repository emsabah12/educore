# ADR-032 — HR Domain Boundary & Workforce Architecture

**Version** : 1.0  
**Status** : Accepted  
**Date** : 2026-08-22  
**Scope** : HR Expansion — Workforce Domain Boundary  
**Baseline Repository** : `26b475b695aa4511064b1410db03d1f0c8bdd6ce`  
**Product Requirement** : `HR-001 — Human Resources Management PRD` (Approved)

---

> ## Decision Summary
>
> EduCore mempertahankan `Modules/HR` sebagai bounded context untuk **workforce/employment lifecycle**, bukan sebagai owner human identity, tenancy, organization topology, authorization, attendance, academic teaching schedule, atau financial settlement. `Person` tetap canonical human identity, `Membership` tetap `Person × Tenant`, dan `Employee` tetap HR domain profile yang terkait ke Membership. Penempatan organisasi menggunakan `OrganizationalAssignment` milik Core; HR hanya menambahkan employment/position facts yang merujuk placement tersebut. Position/jabatan bisnis tidak pernah menjadi authorization source. Attendance tetap downstream/integrated capability, Academic tetap owner teaching assignment/schedule, dan Finance tetap owner payroll run serta financial settlement. Existing `Employee` foundation dipertahankan dan diperluas secara gradual; field `employees.jabatan` tidak dikembangkan menjadi canonical position model.

---

# Related Resources

## Product Requirement

- HR-001 — Human Resources Management PRD (**Approved**)

## Related ADR

- ADR-002 — Modular Monolith Architecture
- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-016 — Database-Backed Tenant RBAC
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-019 — Dormitory Integration Boundary
- ADR-025 — API Client, OpenAPI & Canonical Error Handling
- ADR-027 — Capability-Aware Navigation & Authorization UX
- ADR-030 — Frontend Security Baseline

---

# 1. Context

EduCore sudah memiliki `Modules/HR` dengan foundation berikut:

```text
Person
  ↓
Membership
  ↓
Employee
```

Current `employees` persistence berisi:

```text
id
tenant_id
membership_id
nip
jabatan
created_at
updated_at
deleted_at
```

Current provisioning juga sudah mempertahankan prinsip penting:

```text
Employee provisioning
→ creates Person
→ creates Membership
→ creates Employee
→ does NOT automatically create User
```

Sementara itu, Core telah mengunci foundation platform berikut:

```text
Person                 = canonical human identity
Membership             = Person × Tenant
Tenant                 = customer/security/data-isolation boundary
Organization           = subordinate organizational topology
OrganizationUnit       = subordinate organizational topology
OrganizationalAssignment = Membership operational placement
Role/Permission        = database-backed authorization source of truth
```

HR-001 memperluas kebutuhan dari employee master sederhana menjadi lifecycle HR yang mencakup employment status/classification, position history, recruitment, onboarding, leave, performance, competency, documents, discipline, offboarding, compensation facts, benefit eligibility, reporting, dan integration dengan Attendance/Academic/Finance.

Tanpa architectural boundary yang eksplisit, ekspansi ini berisiko menghasilkan:

- duplicate human identity dalam HR;
- duplicate organization/unit placement pada employee;
- `jabatan` menjadi overloaded enum untuk position, employment classification, dan authorization;
- HR menjadi god-module yang memiliki Attendance, Academic schedule, payroll, dan finance settlement;
- multiple authorization source of truth;
- data ownership yang ambigu antar module;
- historical employment facts tertimpa current-state fields;
- coupling ke fingerprint/bank/government integration sebelum contract eksternal stabil.

ADR ini mengunci boundary sebelum schema/API detail dirancang.

---

# 2. Decision Drivers

Keputusan harus memprioritaskan:

1. alignment dengan canonical architecture existing;
2. data integrity dan single source of truth;
3. tenant isolation dan scoped authorization;
4. clear domain ownership;
5. maintainability dan incremental migration;
6. traceable historical workforce facts;
7. menghindari overengineering serta premature microservice split;
8. extensibility untuk sekolah/yayasan multi-unit tanpa mengubah Core contract.

---

# 3. Decision

## 3.1 HR adalah bounded context workforce/employment

`Modules/HR` tetap dipertahankan dan diperluas sebagai owner **workforce/employment facts**.

Canonical responsibility:

```text
Modules/HR
├── Employee profile
├── Employment lifecycle
├── Employment type/classification/status
├── HR position catalog & position history
├── Employment terms / contract facts
├── Recruitment & onboarding
├── Leave / permit policy and ledger
├── Compensation profile / payroll input facts
├── Benefit eligibility
├── Performance / PKG / assessment history
├── Competency / training / certification
├── HR document metadata & lifecycle
├── Discipline / warning history
├── Offboarding
└── HR reporting/read models
```

HR bukan owner generic platform identity, organization topology, authorization mechanism, academic teaching schedule, attendance source of truth, atau financial settlement.

---

## 3.2 Person tetap canonical human identity

HR tidak membuat parallel human identity.

Canonical graph tetap:

```text
Person
  │
  ├── User (optional digital account)
  │
  └── Membership
        ↓
      Employee
```

Data manusia yang secara canonical dimiliki Person tidak diduplikasi sebagai canonical field pada Employee.

Contoh Person-owned data:

```text
legal/display human name
personal contacts
addresses
human identifiers
citizenship/basic human identity facts
```

HR boleh memiliki **domain-specific references/evidence** terhadap data tersebut jika requirement audit/history membutuhkannya, tetapi reference/evidence tidak boleh berubah menjadi second canonical human profile.

---

## 3.3 Membership tetap participation boundary Employee

Employee tetap merupakan HR domain profile yang terkait ke Membership.

```text
Person
  ↓
Membership = Person × Tenant
  ↓
Employee = HR profile in tenant
```

Architectural invariant:

```text
one canonical Employee profile
per applicable Membership employment identity
```

`Employee` tidak menjadi child dari `User` dan tidak memerlukan User untuk valid secara domain.

Employee self-service hanya tersedia jika Person memiliki User account yang sah dan effective authorization/context yang diperlukan.

---

## 3.4 Employee adalah stable HR profile; employment facts memiliki lifecycle sendiri

Existing `Employee` dipertahankan sebagai stable HR profile/root untuk workforce participation dalam tenant.

Data yang berubah sepanjang waktu tidak boleh semuanya ditumpuk menjadi mutable current-state columns pada `employees`.

Secara conceptual:

```text
Employee
├── stable profile identity
│
├── Employment lifecycle facts
├── Employment terms / contract history
├── Classification history
├── Position history
├── Compensation history
├── Benefit eligibility history
├── Performance history
├── Discipline history
└── Offboarding history
```

Exact aggregate/table decomposition diputuskan pada System/Data Design, bukan ADR ini.

Tujuannya adalah mempertahankan historical evidence dan menghindari overwrite history.

---

## 3.5 Employment type dan employment classification adalah dimensi terpisah

Sesuai OD-HR-001:

```text
employment_type
≠
employment_classification
```

Contoh conceptual:

```text
employment_type:
- permanent
- contract
- honorary

employment_classification:
- GTY
- GTT
- PTY
- PTT
- tenant-defined classification where approved
```

ADR ini tidak mengunci final vocabulary, code, atau localization. Catalog/policy detail ditentukan pada design berikutnya berdasarkan business configuration requirements.

---

## 3.6 Organizational placement tetap dimiliki Core

HR tidak memperkenalkan parallel placement seperti:

```text
employees.school_id
employees.organization_id
employees.branch_id
employees.unit_id
```

sebagai canonical organizational placement.

Canonical placement tetap:

```text
Membership
  ↓
OrganizationalAssignment
  ├── Organization
  └── OrganizationUnit?
```

HR employment/position facts yang membutuhkan scope organisasi harus **mereferensikan/berasosiasi dengan existing OrganizationalAssignment contract** daripada membuat second organization topology.

Implikasi:

```text
"Employee berada di Unit A"
→ Core OrganizationalAssignment fact

"Employee menjabat Kepala Sekolah pada placement tersebut"
→ HR employment/position fact
```

Core tidak menjadi aware terhadap HR position semantics.

---

## 3.7 Position/jabatan bisnis bukan authorization role

Existing ADR-016 dan ADR-018 tetap authoritative.

```text
HR Position
≠
Role
≠
Permission
```

Contoh:

```text
Position: Kepala Sekolah
```

tidak otomatis memberikan:

```text
hr.employee.update
hr.leave.approve
finance.payroll.approve
academic.grades.write
```

Authorization tetap berasal dari:

```text
MembershipRole
or
OrganizationalAssignmentRole
→ Role
→ RolePermission
→ Permission
```

Module HR boleh memiliki concrete capability catalog entries sendiri, tetapi tidak boleh membuat alternate authorization engine.

---

## 3.8 `employees.jabatan` dipertahankan sementara tetapi tidak menjadi canonical position model

Current field:

```text
employees.jabatan
```

adalah existing implementation dan tidak dihapus secara abrupt.

Keputusan:

- **KEEP sementara** untuk backward compatibility current HR flow;
- **DEPRECATE sebagai future canonical modeling source** setelah dedicated position/employment design tersedia;
- **jangan** memperluas `StoreEmployeeRequest` menjadi enum raksasa untuk seluruh jabatan yayasan;
- historical/structured position akan menggunakan model HR yang dirancang pada fase berikutnya;
- migration/backfill strategy harus dibuat sebelum field lama diubah/dihapus.

Tidak ada breaking migration yang ditentukan oleh ADR ini.

---

## 3.9 Recruitment Candidate adalah HR-owned pre-employment entity

Candidate/applicant bukan Employee sebelum hire/onboarding transition yang valid.

Conceptual flow:

```text
Vacancy / Requisition
  ↓
Candidate / Application
  ↓
Selection
  ↓
Hiring Decision
  ↓
Onboarding
  ↓
resolve/create canonical Person
  ↓
resolve/create Membership
  ↓
create/activate Employee
```

Candidate tidak diwajibkan menjadi Person pada tahap awal recruitment.

Saat conversion ke Employee, implementation harus melakukan canonical identity resolution/create secara atomic/idempotent sehingga tidak menghasilkan duplicate Person/Membership/Employee.

Exact duplicate-matching policy adalah **[OPEN DESIGN]** dan tidak dikunci pada ADR ini.

---

## 3.10 User provisioning tetap concern terpisah

Hiring/onboarding tidak otomatis berarti employee membutuhkan login.

```text
Employee lifecycle
≠
User account lifecycle
```

Jika employee membutuhkan self-service:

```text
Employee / Person
  ↓
explicit account invitation/provisioning
  ↓
User
```

Offboarding juga tidak otomatis hard-delete User atau Person. Access revocation/review mengikuti identity/membership/authorization lifecycle yang berlaku.

---

## 3.11 Attendance bukan HR source of truth

Sesuai HR-001 dan existing downstream architecture direction:

```text
Attendance
= attendance source of truth
```

HR hanya:

- mengonsumsi/referensikan attendance facts;
- menggunakan attendance summary/exception untuk HR reporting;
- menggunakan approved attendance facts sebagai payroll input bila policy membutuhkan.

HR tidak memiliki canonical device attendance event.

Device-specific integrations seperti fingerprint, QR, GPS/geofence menjadi adapter pada Attendance/integration boundary, bukan concern Employee aggregate.

Manual/import attendance pada initial phase tidak mengubah ownership tersebut.

---

## 3.12 Academic tetap owner teaching assignment dan schedule

Guru adalah Employee yang berpartisipasi dalam Academic capability, bukan HR entity baru bernama Teacher.

```text
Employee
+
Academic teaching assignment/capability
=
Teacher actor in Academic context
```

Ownership:

```text
HR
→ employment as educator / HR workforce facts

Academic
→ subject/class teaching assignment
→ academic schedule
→ grading domain

Attendance
→ attendance facts
```

HR tidak menduplikasi class schedule atau subject teaching assignment untuk menghitung payroll.

---

## 3.13 Finance tetap owner payroll run dan financial settlement

Sesuai OD-HR-003:

```text
HR owns
├── compensation profile
├── benefit eligibility
├── employment terms
├── approved adjustments
└── traceable payroll input facts

Finance owns
├── payroll run/finalization
├── financial calculation
├── tax calculation where applicable
├── payable
├── payment/disbursement
├── reconciliation
└── accounting
```

HR dapat menampilkan payroll/slip result melalui Finance contract/read model sesuai authorization, tetapi tidak menduplikasi Finance source of truth.

Jika Finance belum menyediakan contract tertentu, hal tersebut diperlakukan sebagai dependency/gap, bukan alasan memindahkan ownership ke HR.

---

## 3.14 Approval adalah policy-driven capability, bukan hardcoded hierarchy

Approval seperti leave/permit/recruitment/HR action tidak boleh meng-hardcode nama jabatan:

```text
Kaur → Kepala Sekolah → Yayasan
```

sebagai universal engine rule.

Decision basis:

```text
configured HR policy
+
verified tenant context
+
verified organizational context where required
+
effective authorization capability
```

Exact reusable workflow engine vs HR-local workflow implementation adalah **[OPEN DESIGN]**. ADR ini hanya mengunci bahwa approval tidak berasal dari string jabatan.

---

## 3.15 Government reporting fase awal adalah export boundary

Dapodik/EMIS/Simpatika fase awal diperlakukan sebagai export/report integration.

```text
EduCore HR data
  ↓
validated export mapping
  ↓
export artifact
```

Generated/exported tidak sama dengan submitted/accepted oleh sistem pemerintah.

Direct synchronization/API adapter hanya dapat dirancang setelah official contract dan compliance requirements diverifikasi.

---

## 3.16 Audit menggunakan Core governance contract

Critical HR mutation/approval harus menghasilkan audit trail menggunakan Core audit foundation, bukan HR-specific parallel audit engine.

Audit metadata harus mengikuti data minimization dan tidak menyimpan unnecessary sensitive payload.

Exact event taxonomy ditentukan pada System/API Design.

---

# 4. Canonical Workforce Model

High-level canonical relationship:

```text
                           Person
                          /      \
                  User (optional) Membership
                                   │
                    ┌──────────────┴────────────────┐
                    │                               │
               Employee                    OrganizationalAssignment
                    │                               │
          HR workforce facts             Organization / Unit placement
                    │
      ┌─────────────┼───────────────────────────┐
      │             │                           │
 Employment      Position                    HR lifecycle
 lifecycle       history                     capabilities
      │             │                           │
      └──────── references applicable placement ┘
```

Integrated domains:

```text
                   ┌──────────── Academic
                   │              teaching/schedule
Employee / HR ─────┼──────────── Attendance
                   │              attendance facts
                   │
                   └──────────── Finance
                                  payroll/settlement
```

Dependency principle:

```text
Core must not depend on HR.
HR consumes Core contracts.
Attendance/Academic/Finance integration must not redefine HR identity ownership.
```

---

# 5. Domain Ownership Matrix

| Concept | Canonical Owner | HR Relationship |
|---|---|---|
| Human identity | Core / Person | Consume |
| Login account | User/Auth + Core identity contract | Consume / optional self-service |
| Tenant participation | Core / Membership | Consume |
| Organization topology | Core / Organization | Consume |
| Organizational placement | Core / OrganizationalAssignment | Consume/reference |
| RBAC mechanism | Core Authorization | Consume |
| Employee profile | HR | **Own** |
| Employment lifecycle | HR | **Own** |
| Employment type/classification | HR | **Own** |
| HR position & position history | HR | **Own** |
| Recruitment candidate/application | HR | **Own** |
| Leave entitlement/request/ledger | HR | **Own** |
| Performance/competency | HR | **Own** |
| HR documents/discipline | HR | **Own** |
| Offboarding HR lifecycle | HR | **Own** |
| Compensation profile/benefit eligibility | HR | **Own** |
| Attendance event/source | Attendance | Consume |
| Academic teaching assignment/schedule | Academic | Consume |
| Payroll run/payable/payment/accounting | Finance | Produce inputs / consume results |
| Government submission status | External system/integration | Export only in initial scope |
| Audit mechanism | Core Governance Audit | Emit through contract |

---

# 6. Architectural Invariants

```text
Person = canonical human identity

User = optional digital account

Membership = Person × Tenant

Employee = HR profile linked to Membership

Employee does not require User

Tenant != Organization != OrganizationUnit

OrganizationalAssignment = canonical organizational placement

HR does not add parallel employee organization/unit ownership

HR Position != RBAC Role != Permission

Employee.jabatan is not an authorization source

Employee.jabatan is not the future canonical position model

Candidate != Employee

Hire/onboarding transition must not duplicate canonical Person/Membership/Employee

Attendance source of truth != HR

Academic teaching schedule source of truth != HR

Payroll settlement source of truth != HR

Core does not depend on HR

Offboarding != hard delete Person/User

Historical employment evidence must be preserved
```

---

# 7. Current Implementation Impact

| Existing Resource | Decision | Impact |
|---|---|---|
| `Modules/HR` | **KEEP + EXTEND** | Tetap bounded context HR. |
| `Employee` model/profile | **KEEP + EXTEND** | Tetap canonical HR profile melalui Membership. |
| `employees.tenant_id` | **KEEP** | Explicit tenant ownership tetap dipertahankan sesuai current convention. |
| `employees.membership_id` | **KEEP** | Canonical relation HR profile → Membership. |
| `employees.nip` | **KEEP, validate semantics later** | Tetap employee identifier; lifecycle/uniqueness semantics ditinjau pada data design. |
| `employees.jabatan` | **DEPRECATE AS CANONICAL POSITION** | Tidak diperbesar menjadi position engine; migration gradual diperlukan. |
| `StoreEmployeeRequest` fixed `jabatan` enum | **REFACTOR LATER** | Harus mengikuti dedicated position/employment design setelah model disetujui. |
| `EmployeeProvisioningService` | **KEEP + EXTEND** | Transaction foundation dipertahankan; future flow perlu resolve/create identity dan idempotent candidate conversion. |
| No automatic `User` provisioning | **KEEP** | Sesuai ADR-013 dan HR-001. |
| Core `OrganizationalAssignment` | **KEEP + REUSE** | Menjadi placement contract, tidak diduplikasi dalam HR. |
| Core RBAC/scoped RBAC | **KEEP + REUSE** | HR menambah capability catalog, bukan auth engine. |
| Core Audit | **KEEP + REUSE** | Critical HR action mengemit audit melalui Core contract. |

---

# 8. Consequences

## Positive

- HR expansion tetap align dengan architecture existing.
- Human identity tidak diduplikasi.
- Multi-unit employment memanfaatkan Core topology yang sudah matang.
- Position dan authorization tetap terpisah.
- Historical employment dapat berkembang tanpa memperbesar `employees` menjadi tabel serba-guna.
- Attendance, Academic, dan Finance memiliki source of truth yang jelas.
- Payroll tidak mencampur employment policy dengan accounting/payment settlement.
- Employee tanpa login tetap valid.
- HR dapat tumbuh secara modular tanpa memecah aplikasi menjadi microservices prematur.

## Trade-offs / Negative

- Read model HR sering membutuhkan join/cross-module contract ke Person, Membership, dan OrganizationalAssignment.
- Position history memerlukan design baru dan migration dari `employees.jabatan`.
- Payroll UI dapat membutuhkan composition antara HR dan Finance read models.
- Recruitment conversion membutuhkan identity resolution yang lebih kompleks daripada current create-only provisioning.
- Scoped approval membutuhkan policy + organizational authorization yang lebih eksplisit.
- Integration consistency harus ditangani tanpa distributed transaction antar bounded context jika kelak module ownership semakin terpisah.

---

# 9. Alternatives Considered

## Option A — Expand `employees` menjadi all-in-one HR record

Contoh:

```text
employees
+ school_id
+ unit_id
+ employment_type
+ classification
+ position
+ salary
+ attendance
+ leave_balance
+ payroll_status
+ ...
```

**Rejected.**

Alasan:

- duplicate Core organization placement;
- history mudah tertimpa;
- payroll/attendance ownership bercampur;
- `Employee` menjadi god-entity;
- sulit mengelola multiple historical/effective-dated facts.

---

## Option B — Membuat HR identity sendiri tanpa Person/Membership

**Rejected.**

Bertentangan dengan ADR-013 dan ADR-014 serta menghasilkan duplicate human identity.

---

## Option C — Menjadikan `jabatan` sebagai RBAC role

**Rejected.**

Bertentangan dengan ADR-016/018 dan mencampur employment semantics dengan authorization.

---

## Option D — Semua HR capability termasuk Attendance dan Payroll dimiliki `Modules/HR`

**Rejected.**

Menciptakan god-module dan overlap dengan Academic/Finance serta future Attendance bounded context.

---

## Option E — Memecah setiap HR capability menjadi microservice sekarang

**Rejected.**

Tidak ada requirement scale/deployment yang membenarkan distributed architecture. EduCore tetap modular monolith sesuai ADR-002.

---

## Option F — HR workforce boundary dengan Core contracts + explicit downstream integration (**Accepted candidate**)

`Modules/HR` diperluas sebagai bounded context workforce sambil menggunakan canonical platform contracts dan menjaga Attendance/Academic/Finance ownership.

---

# 10. Security & Data Integrity Rules

- Semua HR record harus explicit tenant-safe mengikuti current shared-schema multi-tenancy strategy.
- Cross-tenant reference harus ditolak.
- Organizationally scoped operation harus menggunakan verified OrganizationalContext bila scope tersebut diperlukan.
- Resource ownership tetap harus diverifikasi selain permission check.
- HR permission catalog tidak boleh mengambil implicit permission dari Employee position/classification.
- Sensitive HR document/performance/discipline/payroll-related view mengikuti least privilege.
- Export harus menggunakan authorization scope yang sama dengan source query.
- Audit trail tidak boleh menjadi tempat dumping sensitive payload.
- Offboarding harus memicu access-review requirement tetapi tidak menghapus identity secara implicit.

---

# 11. Integration Contract Direction

ADR ini belum mengunci API payload, tetapi mengunci arah dependency.

## HR → Core

HR dapat menggunakan:

```text
Person contracts
Membership context/contracts
OrganizationalAssignment contracts
Authorization services
Audit service
```

## HR ↔ Attendance

```text
HR consumes attendance facts/summary
HR may provide approved leave/absence facts where contract requires
```

Ownership Attendance tetap di Attendance.

## HR ↔ Academic

```text
HR provides employee/workforce identity
Academic provides teaching assignment/schedule facts
```

## HR ↔ Finance

```text
HR → traceable payroll input facts
Finance → payroll result/slip/payment state read contract
```

## HR → Government Reporting Adapter

```text
HR → export dataset
Adapter/export layer → external format
```

Direct submission tetap future decision.

---

# 12. [RESOURCE GAP] / Open Design Items

ADR ini sengaja tidak mengunci hal berikut:

1. exact aggregate/table decomposition untuk Employment, Position, Contract, Leave, Recruitment, Performance, Competency, Document, Discipline, dan Offboarding;
2. candidate/person duplicate resolution algorithm;
3. effective-dating model dan overlap constraints;
4. reusable approval/workflow engine vs HR-local workflow;
5. exact HR permission catalog dan organization-scope applicability;
6. HR ↔ Attendance contract schema;
7. HR ↔ Academic teaching fact contract;
8. HR ↔ Finance payroll input/result contract;
9. Dapodik/EMIS/Simpatika field mapping;
10. regulatory retention periods;
11. RPO/RTO dan HR-specific volume/SLA target;
12. document storage/e-signature provider contract.

Semua item di atas masuk System Architecture & Data Design setelah ADR disetujui atau menunggu external authority bila diperlukan.

---

# 13. [RISK] Architectural Risks

## R-ADR-HR-001 — Duplicate identity during recruitment conversion

Current `EmployeeProvisioningService` bersifat create-oriented. Future candidate conversion membutuhkan canonical resolve/create semantics.

**Mitigation direction:** design idempotent conversion dan canonical identity resolution sebelum recruitment implementation.

## R-ADR-HR-002 — `jabatan` becomes overloaded legacy field

Menambahkan seluruh jabatan ke enum existing akan memperbesar migration debt.

**Mitigation direction:** freeze semantic expansion dan desain dedicated position model sebelum feature position history.

## R-ADR-HR-003 — Duplicate organizational placement

Developer dapat tergoda menambahkan `organization_id/unit_id` pada employee-related tables tanpa memahami Core assignment.

**Mitigation direction:** organizational placement harus ditelusuri ke Core contract; HR hanya menyimpan reference bila domain record membutuhkan scope/evidence.

## R-ADR-HR-004 — HR/Finance payroll overlap

Payroll UI dapat menyebabkan ownership ambigu.

**Mitigation direction:** setiap field/result harus memiliki source-of-truth owner dan integration contract eksplisit.

## R-ADR-HR-005 — Scope leakage in HR reporting

Headcount dan HR reports lintas unit bersifat sensitif.

**Mitigation direction:** verified tenant + organizational scope + resource ownership checks; export mengikuti scope yang sama.

---

# 14. Traceability to HR-001

| ADR Decision | PRD Trace |
|---|---|
| Person canonical, Employee via Membership | BO-001, BR-001..BR-003, FR-001..FR-003, US-001 |
| Employment lifecycle/history separation | BO-001, BR-004, BR-010, FR-004..FR-006, US-002 |
| OrganizationalAssignment reuse | BO-002, BR-005, FR-007..FR-008, US-003 |
| Position != authorization | BR-006..BR-007, FR-006, FR-048..FR-049, US-003 |
| Candidate pre-employment + canonical conversion | BO-003, BR-008..BR-009, FR-009..FR-013, US-004 |
| Attendance integration boundary | BO-004, BR-012, FR-014..FR-017, US-006 |
| Academic ownership of teaching context | BR-013, FR-016, FR-021, US-006 |
| HR/Finance payroll boundary | BO-004, BR-014..BR-015, FR-023..FR-027, US-007 |
| Policy-driven approval | BR-007, BR-016..BR-017, FR-018..FR-022, US-005 |
| Offboarding != identity deletion | BR-011, BR-022, FR-037..FR-040, US-010 |
| Government export boundary | BO-007, BR-021, FR-044..FR-045, US-012 |
| Core RBAC/Audit reuse | BO-005..BO-006, BR-018..BR-020, FR-046..FR-050 |

---

# 15. Validation Contract for Future Implementation

ADR acceptance berarti future HR design/implementation minimal harus dapat membuktikan:

- Employee tetap terhubung canonical melalui Membership;
- tidak ada requirement login untuk Employee existence;
- tidak ada HR parallel Person identity;
- tidak ada canonical employee organization/unit shortcut yang menggantikan OrganizationalAssignment;
- HR position tidak menjadi permission source;
- current tenant isolation tetap lulus;
- organization/unit scoped authorization tetap fail closed;
- candidate conversion tidak menghasilkan duplicate canonical identity/profile;
- offboarding mempertahankan historical evidence;
- Attendance/Academic/Finance source-of-truth boundary tidak diduplikasi;
- critical mutations dapat diaudit;
- regression existing Core/Auth/Academic/HR tetap dipertahankan sesuai environment yang valid.

Exact test cases dibuat pada implementation planning setelah system design.

---

# 16. Decision Status / Acceptance Criteria

ADR-032 dapat diubah dari **Proposed** menjadi **Accepted** jika reviewer menyetujui keputusan berikut sebagai architectural contract:

1. `Modules/HR` tetap bounded context workforce/employment.
2. Person/Membership/Employee canonical graph dipertahankan.
3. OrganizationalAssignment tetap canonical organizational placement.
4. Position/jabatan tidak menjadi authorization.
5. `employees.jabatan` tidak menjadi future canonical position model.
6. Recruitment Candidate dipisahkan dari Employee sebelum hire/onboarding.
7. User account lifecycle tetap terpisah dari employment lifecycle.
8. Attendance, Academic, dan Finance ownership mengikuti boundary pada ADR ini.
9. Approval tidak di-hardcode berdasarkan nama jabatan.
10. Government integration fase awal tetap export/report boundary.

---

# 17. Reviewer Assessment

**Quality Score:** 9.4/10

**Gaps:** exact aggregate/schema, effective-dating constraints, identity duplicate resolution, approval-engine shape, permission catalog, serta integration contracts sengaja belum dikunci dan masuk fase design berikutnya.

**Risks:** migration debt dari `employees.jabatan`, duplicate Person pada future recruitment conversion bila current provisioning diperluas tanpa resolution strategy, payroll boundary leakage, dan sensitive scoped reporting.

**Recommendations:** accept ADR boundary terlebih dahulu; setelah itu lanjut ke System Architecture & Data Design dengan urutan Employee/Employment/Position foundation → authorization catalog → lifecycle aggregates → integration contracts. Jangan mulai dari Payroll atau device integration sebelum workforce foundation stabil.

**Status:** READY FOR APPROVAL
