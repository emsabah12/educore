# EduCore HR-001 — Human Resources Management PRD

**Document Stage:** Phase 1 — Product & Business Requirement  
**Status:** Approved — Locked  
**Product:** EduCore  
**Module:** HR  
**Repository Baseline:** `26b475b695aa4511064b1410db03d1f0c8bdd6ce`  
**Existing Module:** `Modules/HR` v0.1.0  
**Target:** Extend existing HR module without redesigning Core or existing canonical contracts

---

# 1. Executive Summary

EduCore sudah memiliki `Modules/HR` dengan fondasi Employee yang mengikuti canonical identity EduCore: `Person → Membership → Employee`. Namun capability HR saat ini baru mencakup provisioning dan listing employee dasar. Kebutuhan sekolah/yayasan memerlukan HR lifecycle yang jauh lebih luas: data kepegawaian, rekrutmen, onboarding, penempatan, cuti, compensation, benefit, performance, competency, dokumen, discipline, offboarding, reporting, serta integrasi Attendance, Academic, Finance, Auth/User, Organization, dan government reporting.

PRD ini mendefinisikan kebutuhan produk HR tanpa mengubah keputusan arsitektur existing. Prinsip utamanya adalah memperluas `Modules/HR`, bukan membuat sistem HR baru dan bukan memindahkan ownership Core ke HR.

---

# 2. Current State & Architectural Baseline

## 2.1 Existing HR

Current HR persistence memiliki Employee dengan atribut utama:

- `tenant_id`
- `membership_id`
- `nip`
- `jabatan`
- lifecycle timestamps dan soft delete

Current provisioning sudah transactionally membuat:

```text
Person
  ↓
Membership
  ↓
Employee
```

Employee provisioning tidak membuat User account otomatis.

## 2.2 Existing platform contracts yang menjadi constraint PRD

- `Person` adalah canonical human identity.
- `User` adalah optional digital/authentication account.
- `Membership` adalah partisipasi `Person × Tenant`.
- `Employee` adalah downstream HR profile.
- `Tenant` adalah customer/security/data-isolation boundary.
- Multi-lembaga/multi-unit menggunakan `Organization` dan `OrganizationUnit`.
- Partisipasi organisasi menggunakan `OrganizationalAssignment`.
- Authorization menggunakan database-backed Role/Permission, termasuk scoped authorization.
- Position/jabatan bisnis tidak menjadi authorization primitive.
- Academic sudah bergantung pada HR untuk actor Employee.

---

# 3. Problem Statement

Sekolah/yayasan membutuhkan satu sumber operasional HR yang konsisten untuk seluruh unit, tetapi proses HR biasanya tersebar pada spreadsheet, dokumen manual, sistem absensi terpisah, payroll terpisah, dan catatan personal yang sulit ditelusuri.

Masalah utama yang ingin diselesaikan:

1. Data pegawai dan status employment tersebar dan rawan inkonsistensi.
2. Penempatan pegawai lintas sekolah/unit sulit dilacak secara historis.
3. Rekrutmen, onboarding, kontrak, cuti, disiplin, dan offboarding belum memiliki workflow terpadu.
4. Kehadiran guru dan staf sulit direkonsiliasi dengan jadwal mengajar.
5. Input compensation/payroll belum mempunyai source of truth yang terhubung dengan employment facts.
6. Dokumen, sertifikasi, pelatihan, dan masa berlaku belum terkelola sistematis.
7. Yayasan membutuhkan reporting lintas unit tanpa kehilangan tenant isolation dan scoped access.
8. Audit perubahan data HR sensitif belum menjadi pengalaman produk yang menyeluruh.

---

# 4. Business Objectives

| ID     | Business Objective                                                                                                                          |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------- |
| BO-001 | Menyediakan single operational source of truth untuk lifecycle kepegawaian dalam satu tenant yayasan.                                       |
| BO-002 | Mendukung pengelolaan pegawai lintas Organization/OrganizationUnit tanpa menduplikasi identity atau placement model.                        |
| BO-003 | Mengurangi administrasi manual untuk recruitment, onboarding, leave, document, performance, competency, discipline, dan offboarding.        |
| BO-004 | Menyediakan workforce data yang dapat dikonsumsi Academic, Attendance, dan Finance secara konsisten.                                        |
| BO-005 | Meningkatkan traceability dan auditability terhadap perubahan data HR dan approval penting.                                                 |
| BO-006 | Memungkinkan employee self-service sesuai capability yang diberikan.                                                                        |
| BO-007 | Mendukung kebutuhan reporting sekolah/yayasan dan export government reporting tanpa mengikat domain ke vendor/API eksternal pada fase awal. |

---

# 5. Success Metrics / KPI

Target numerik belum tersedia dari business resource dan tidak akan diarang.

| ID      | Metric                                                                                           | Target              |
| ------- | ------------------------------------------------------------------------------------------------ | ------------------- |
| KPI-001 | Persentase employee aktif dengan employment profile wajib lengkap                                | **[OPEN DECISION]** |
| KPI-002 | Persentase perubahan employment/placement yang mempunyai audit trail                             | **[OPEN DECISION]** |
| KPI-003 | Waktu rata-rata penyelesaian leave request                                                       | **[OPEN DECISION]** |
| KPI-004 | Persentase kontrak/sertifikat yang mendapat reminder sebelum jatuh tempo                         | **[OPEN DECISION]** |
| KPI-005 | Persentase payroll input yang dapat direkonsiliasi ke employment/attendance source               | **[OPEN DECISION]** |
| KPI-006 | Waktu yang dibutuhkan yayasan untuk menghasilkan headcount/payroll/attendance report lintas unit | **[OPEN DECISION]** |
| KPI-007 | Persentase workflow HR yang diselesaikan tanpa spreadsheet/manual re-entry                       | **[OPEN DECISION]** |

---

# 6. Stakeholder & User Persona

Persona digunakan untuk memahami kebutuhan produk, bukan sebagai hardcoded authorization role.

| Persona                           | Kebutuhan utama                                                                                                |
| --------------------------------- | -------------------------------------------------------------------------------------------------------------- |
| Yayasan / Tenant HR Administrator | Workforce lintas unit, policy, approval, reporting, governance                                                 |
| HR / Personalia                   | Employee lifecycle, recruitment, leave, document, performance, discipline                                      |
| Kepala Sekolah / Unit Leader      | Workforce unit, approval, supervision, performance                                                             |
| Bendahara / Finance Operator      | Payroll inputs, compensation facts, payroll/slip visibility sesuai capability                                  |
| Guru                              | Self-service profile, leave/permit, schedule-related attendance view, document/training/performance visibility |
| Tenaga Kependidikan               | Self-service profile, attendance, leave, document, benefit visibility                                          |
| Supervisor / Assessor             | Assessment, observation, approval sesuai scope                                                                 |
| Auditor / Read-only Reviewer      | Audit-safe reporting dan historical evidence sesuai permission                                                 |

---

# 7. Scope

## 7.1 IN SCOPE — HR-owned product capability

- Employee master/profile extension
- Employment relationship dan lifecycle
- Employment type dan employment classification
- Position dan position history
- Employment placement view menggunakan organizational contracts existing
- Education history
- Certification/license history dan expiry tracking
- Recruitment dan candidate pipeline
- Recruitment approval
- Onboarding checklist
- Leave dan permit management
- Leave policy, entitlement, balance, request, approval
- Compensation profile dan payroll input facts
- Benefit eligibility dan HR benefit tracking
- Performance review, PKG-supporting workflow, KPI, self/manager assessment
- Competency, training, workshop, PKB, certification tracking
- HR document registry: SK, contract, mutation, termination, supporting documents
- Discipline, warning, follow-up/coaching history
- Offboarding workflow
- HR reporting dan dashboard
- Employee self-service capability

## 7.2 IN SCOPE — Integrated capability, ownership di module lain

- Attendance data consumption/integration
- Teaching schedule/teaching assignment consumption dari Academic
- Payroll run/payment/slip consumption dari Finance
- Identity/account provisioning melalui Core/Auth/User contracts
- Organization/unit placement melalui Core Organization
- Authorization melalui Core RBAC/scoped authorization
- Audit melalui Core audit foundation

## 7.3 OUT OF SCOPE — HR ownership

- Canonical Person identity
- Authentication engine
- Tenant/Membership ownership
- Organization topology ownership
- Authorization engine
- Academic timetable ownership
- Student attendance ownership
- Accounting ledger
- Bank reconciliation
- Payment settlement
- Government-system implementation internals

## 7.4 FUTURE SCOPE

- Direct fingerprint device adapters
- GPS/geofencing attendance
- QR attendance adapters
- Direct bank disbursement
- Payment gateway
- Direct Dapodik API synchronization
- Direct EMIS API synchronization
- Direct Simpatika synchronization
- External e-signature provider integration
- Advanced workforce planning/succession planning

## 7.5 DEFERRED

Exact vendor, protocol, data mapping, API contract, government integration mechanics, e-signature provider, and payroll tax implementation details are deferred to architecture/integration design after requirements are approved.

---

# 8. Locked Product Decisions

| ID        | Decision                                                                                                                                |
| --------- | --------------------------------------------------------------------------------------------------------------------------------------- |
| OD-HR-001 | `employment_type` dan `employment_classification` adalah dua dimensi berbeda.                                                           |
| OD-HR-002 | Approval leave/permit harus policy-driven/configurable per applicable organizational scope; tidak hardcoded ke hierarchy tertentu.      |
| OD-HR-003 | HR owns compensation/employment/payroll input facts; Finance owns payroll run, payable, payment, accounting, dan financial settlement.  |
| OD-HR-004 | Government reporting fase awal adalah export/report; direct synchronization adalah future scope.                                        |
| OD-HR-005 | Attendance fase awal mendukung canonical record consumption dan manual/import flow; device-specific adapter menjadi future integration. |

---

# 9. Business Rules

| ID     | Business Rule                                                                                                                                                                                |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| BR-001 | Human identity employee selalu mengikuti canonical `Person`; HR tidak membuat parallel human identity.                                                                                       |
| BR-002 | Employee adalah profile dari Membership dalam tenant dan tidak otomatis mempunyai User account.                                                                                              |
| BR-003 | Satu Membership tidak boleh mempunyai lebih dari satu active canonical Employee profile yang merepresentasikan employment yang sama.                                                         |
| BR-004 | `employment_type` (mis. tetap/kontrak/honorer) dipisahkan dari `employment_classification` (mis. GTY/GTT/PTY/PTT).                                                                           |
| BR-005 | Organization/Unit placement harus menggunakan organizational participation contract existing; HR tidak membuat parallel `school_id/unit_id` ownership pada Employee.                         |
| BR-006 | Position/jabatan bisnis tidak memberikan system permission secara otomatis.                                                                                                                  |
| BR-007 | Approval dilakukan berdasarkan configured policy + effective authorization/scope, bukan nama jabatan yang di-hardcode.                                                                       |
| BR-008 | Recruitment approval tidak otomatis membuat Employee sebelum candidate dinyatakan hired/onboarding sesuai workflow.                                                                          |
| BR-009 | Conversion candidate → employee harus atomic/idempotent pada implementation design dan tidak boleh menghasilkan duplicate Person/Membership/Employee.                                        |
| BR-010 | Employment history, position history, placement history, contract history, performance, discipline, dan offboarding evidence harus dapat dipertahankan sebagai historical record.            |
| BR-011 | Offboarding menutup employment lifecycle; tidak identik dengan menghapus Person atau User. Perubahan access harus dilakukan melalui identity/membership/authorization lifecycle yang sesuai. |
| BR-012 | Attendance adalah integrated workforce fact; HR tidak mengambil alih ownership Attendance domain.                                                                                            |
| BR-013 | Teaching assignment dan teaching schedule tetap menjadi Academic concern.                                                                                                                    |
| BR-014 | Payroll financial finalization, payable, payment, tax/financial calculation, reconciliation, dan accounting berada pada Finance boundary.                                                    |
| BR-015 | HR boleh menghasilkan compensation/payroll inputs hanya dari source yang dapat ditelusuri (employment, attendance, benefit, approved adjustment).                                            |
| BR-016 | Leave balance dan entitlement mengikuti configured policy yang berlaku pada tenant/organization/employee classification.                                                                     |
| BR-017 | Employee tidak boleh meng-approve request miliknya sendiri apabila workflow membutuhkan independent approver.                                                                                |
| BR-018 | Dokumen sensitif hanya dapat dilihat sesuai capability dan scope.                                                                                                                            |
| BR-019 | Critical HR mutations dan approvals harus mempunyai audit trail tanpa menyalin unnecessary sensitive payload ke audit metadata.                                                              |
| BR-020 | Data/report lintas unit hanya boleh diakses oleh actor yang mempunyai effective tenant-wide/scoped capability yang sesuai.                                                                   |
| BR-021 | Government reporting fase awal menghasilkan export/report; keberhasilan export tidak boleh diklaim sebagai successful government submission.                                                 |
| BR-022 | Hard delete bukan default lifecycle action untuk historical employment records yang sudah mempunyai downstream references.                                                                   |

---

# 10. Functional Requirements

## 10.1 Employee & Employment Master

| ID     | Requirement                                                                                                                                  | Priority |
| ------ | -------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-001 | Sistem harus dapat membuat dan membaca Employee berdasarkan canonical Person/Membership flow existing.                                       | P0       |
| FR-002 | Sistem harus dapat mengelola employment type, employment classification, employment status, start date, dan relevant lifecycle dates.        | P0       |
| FR-003 | Sistem harus mendukung NIP/employee identifier sesuai tenant rules dan tetap tenant-scoped.                                                  | P0       |
| FR-004 | Sistem harus menampilkan canonical personal identity/contact data dari Person-owned source tanpa menduplikasinya sebagai HR source of truth. | P0       |
| FR-005 | Sistem harus mengelola education history dan certification/license records pegawai.                                                          | P1       |
| FR-006 | Sistem harus mencatat position dan position history tanpa menjadikannya authorization role.                                                  | P0       |
| FR-007 | Sistem harus menampilkan dan mengelola employment placement melalui organizational assignment contracts existing.                            | P0       |
| FR-008 | Sistem harus mendukung satu pegawai mempunyai applicable assignments lintas unit sesuai Core organizational rules.                           | P0       |

## 10.2 Recruitment & Onboarding

| ID     | Requirement                                                                                                                                                  | Priority |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- |
| FR-009 | Sistem harus mendukung vacancy/requisition untuk kebutuhan guru dan tenaga kependidikan.                                                                     | P1       |
| FR-010 | Sistem harus mendukung candidate pipeline: application, administrative screening, test, interview, micro-teaching bila applicable, approval, rejected/hired. | P1       |
| FR-011 | Recruitment workflow harus dapat menyimpan evaluation dan decision evidence sesuai capability.                                                               | P1       |
| FR-012 | Hired candidate harus dapat memasuki onboarding checklist sebelum/ketika employment diaktifkan.                                                              | P1       |
| FR-013 | Onboarding harus mendukung checklist dokumen, SK/contract, orientation, dan required administrative tasks.                                                   | P1       |

## 10.3 Attendance Integration

| ID     | Requirement                                                                                                                                                     | Priority |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-014 | HR harus dapat mengonsumsi rekap attendance pegawai dari Attendance capability.                                                                                 | P0       |
| FR-015 | Sistem harus mendukung manual/import attendance flow sebagai initial operational fallback.                                                                      | P0       |
| FR-016 | Guru harus dapat direkonsiliasi dengan teaching attendance/jadwal melalui integration ke Academic dan Attendance tanpa menduplikasi Academic schedule dalam HR. | P1       |
| FR-017 | Rekap keterlambatan, absence, dan attendance exception harus dapat digunakan sebagai HR reporting/payroll input sesuai policy.                                  | P1       |

## 10.4 Leave & Permit

| ID     | Requirement                                                                                                              | Priority |
| ------ | ------------------------------------------------------------------------------------------------------------------------ | -------- |
| FR-018 | Sistem harus mendukung configurable leave/permit types.                                                                  | P0       |
| FR-019 | Sistem harus mendukung entitlement, balance, request, approval/rejection, cancellation, dan historical ledger.           | P0       |
| FR-020 | Approval flow harus configurable berdasarkan applicable organizational policy dan scope.                                 | P0       |
| FR-021 | Sistem harus mendukung teaching permit/substitution request yang dapat mengacu pada Academic context apabila diperlukan. | P1       |
| FR-022 | Employee harus dapat melihat request history dan saldo hak yang relevan melalui self-service.                            | P0       |

## 10.5 Compensation, Benefit & Payroll Integration

| ID     | Requirement                                                                                                                                                                                                  | Priority |
| ------ | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | -------- |
| FR-023 | HR harus mengelola compensation profile dan eligibility facts: base component, position allowance, functional allowance, teaching/hourly basis, benefit eligibility, dan approved adjustments sesuai policy. | P0       |
| FR-024 | Sistem harus dapat menghasilkan payroll input yang traceable untuk dikonsumsi Finance.                                                                                                                       | P0       |
| FR-025 | Payroll run, payable, payment, tax/financial calculation, reconciliation, dan accounting tidak boleh menjadi HR source of truth.                                                                             | P0       |
| FR-026 | HR/self-service harus dapat menampilkan payroll/slip result dari Finance sesuai capability tanpa menggandakan financial source of truth.                                                                     | P1       |
| FR-027 | Sistem harus mendukung tracking benefit seperti BPJS, THR eligibility, TPG tracking, dan employee-child education benefit apabila tenant mengaktifkannya.                                                    | P1       |

## 10.6 Performance & Competency

| ID     | Requirement                                                                                                                | Priority |
| ------ | -------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-028 | Sistem harus mendukung configurable performance cycle untuk guru dan non-guru.                                             | P1       |
| FR-029 | Sistem harus mendukung self-assessment, manager/supervisor assessment, observation/supervision evidence, dan final review. | P1       |
| FR-030 | Sistem harus menyimpan performance history sebagai historical evidence dan tidak menimpa hasil periode sebelumnya.         | P1       |
| FR-031 | Sistem harus mengelola training, workshop, competency development, PKB, certification, dan re-certification records.       | P1       |
| FR-032 | Sistem harus dapat memberikan reminder terhadap expiry certificate/license/contract yang mempunyai expiry date.            | P1       |

## 10.7 HR Documents & Discipline

| ID     | Requirement                                                                                                                     | Priority |
| ------ | ------------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-033 | Sistem harus mengelola metadata dan lifecycle dokumen HR seperti SK pengangkatan, mutation, contract, renewal, dan termination. | P0       |
| FR-034 | Sistem harus mendukung permission-controlled access terhadap dokumen sensitif.                                                  | P0       |
| FR-035 | Sistem harus mendukung warning/disciplinary records, follow-up/coaching, status, dan history.                                   | P1       |
| FR-036 | Reminder jatuh tempo contract/document harus tersedia untuk data yang memiliki expiry/renewal date.                             | P1       |

## 10.8 Offboarding

| ID     | Requirement                                                                                                                                          | Priority |
| ------ | ---------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-037 | Sistem harus mendukung resignation, termination, end-of-contract, retirement/other configured offboarding reason.                                    | P0       |
| FR-038 | Offboarding harus mempunyai checklist, effective date, handover, document/asset handoff reference, dan final HR status.                              | P0       |
| FR-039 | Offboarding harus menghasilkan downstream action requirement untuk access review dan final payroll/entitlement tanpa langsung menghapus Person/User. | P0       |
| FR-040 | Sistem harus dapat menyimpan exit interview secara permission-controlled bila tenant menggunakannya.                                                 | P2       |

## 10.9 Reporting & Government Export

| ID     | Requirement                                                                                                                                               | Priority |
| ------ | --------------------------------------------------------------------------------------------------------------------------------------------------------- | -------- |
| FR-041 | Sistem harus menyediakan headcount/report berdasarkan tenant, organization, unit, employment type/classification/status, dan position sesuai scope akses. | P0       |
| FR-042 | Sistem harus menyediakan leave, attendance integration, discipline, contract expiry, competency, dan performance reporting sesuai capability.             | P1       |
| FR-043 | Sistem harus menyediakan payroll-related HR input/report dan dapat mereferensikan Finance payroll result sesuai access.                                   | P1       |
| FR-044 | Sistem harus mendukung export data yang dibutuhkan untuk pelaporan Dapodik/EMIS/Simpatika pada mapping yang disepakati kemudian.                          | P1       |
| FR-045 | Export harus membedakan generated/exported dari submitted/accepted oleh external government system.                                                       | P1       |

## 10.10 Self-Service & Authorization

| ID     | Requirement                                                                                                       | Priority |
| ------ | ----------------------------------------------------------------------------------------------------------------- | -------- |
| FR-046 | Employee dengan User account harus dapat mengakses HR self-service sesuai effective capability.                   | P0       |
| FR-047 | Employee tanpa User account tetap valid sebagai Employee dan dapat dikelola HR.                                   | P0       |
| FR-048 | HR operation harus menghormati tenant-wide maupun organization/unit-scoped authorization existing.                | P0       |
| FR-049 | Product tidak boleh memberi permission hanya karena `jabatan`, `position`, atau employee classification tertentu. | P0       |
| FR-050 | Critical create/update/approval/termination action harus menghasilkan auditable event.                            | P0       |

---

# 11. Non-Functional Requirements

| ID      | Requirement                                                                                                                                                                     |
| ------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| NFR-001 | Semua HR data harus tenant-isolated mengikuti Core tenant boundary.                                                                                                             |
| NFR-002 | Scoped HR access harus mengikuti effective organizational authorization dan fail closed ketika context/authorization tidak valid.                                               |
| NFR-003 | Sensitive employee data harus menerapkan least-privilege access dan data minimization pada response, logs, audit metadata, dan exports.                                         |
| NFR-004 | Critical HR mutations harus dapat diaudit dengan actor, tenant, relevant scope, action, target identifier, timestamp, dan outcome tanpa unnecessary sensitive payload.          |
| NFR-005 | Employment lifecycle/history tidak boleh hilang karena normal editing atau offboarding.                                                                                         |
| NFR-006 | Mutations yang melibatkan beberapa canonical records harus dirancang transactional/idempotent pada fase teknis berikutnya.                                                      |
| NFR-007 | API/UI harus mengikuti existing EduCore error, authentication, context, capability, dan frontend foundation contracts.                                                          |
| NFR-008 | Self-service harus mobile-friendly/responsive mengikuti existing Frontend Foundation NFR.                                                                                       |
| NFR-009 | HR export harus menerapkan authorization dan data scope yang sama seperti source view; export tidak boleh menjadi bypass permission.                                            |
| NFR-010 | Backup/recovery mengikuti platform backup strategy; HR-specific RPO/RTO target adalah **[OPEN DECISION]** bila platform baseline belum mencukupi.                               |
| NFR-011 | Performance target untuk list/search/report mengikuti platform baseline; HR-specific volume/SLA target adalah **[OPEN DECISION]** setelah headcount dan usage profile tersedia. |
| NFR-012 | Integration failure dengan Attendance/Academic/Finance/external reporting tidak boleh merusak canonical HR employment data.                                                     |
| NFR-013 | Data privacy/retention/destruction policy harus melalui legal/compliance review yang berlaku; exact retention period tidak ditetapkan tanpa authority source.                   |
| NFR-014 | File/document access harus private-by-default dan tidak menggunakan public URL sebagai authorization mechanism.                                                                 |
| NFR-015 | Reporting lintas organization/unit harus tetap menghormati tenancy dan scope authorization.                                                                                     |

---

# 12. User Journeys

## UJ-001 — HR creates a new employee

```text
HR authorized context
→ capture/resolve canonical person
→ establish membership where applicable
→ create employee profile
→ define employment facts
→ assign organizational placement through Core contract
→ attach position/contract/onboarding
→ employee becomes active
```

## UJ-002 — Candidate becomes employee

```text
Vacancy
→ Candidate
→ Selection stages
→ Approval
→ Hired
→ Onboarding
→ canonical Employee provisioning
→ organizational placement
→ optional User/account invitation
```

## UJ-003 — Employee requests leave

```text
Employee self-service
→ select leave type/date
→ eligibility/balance validation
→ configured approval chain
→ approved/rejected
→ leave ledger updated
→ Attendance receives/consumes relevant absence fact through integration
```

## UJ-004 — Monthly payroll preparation

```text
Employment facts
+ compensation profile
+ approved adjustments
+ eligible benefit
+ attendance/teaching facts
→ HR payroll input
→ Finance payroll run
→ Finance final result/payment
→ employee accesses slip/result according to capability
```

## UJ-005 — Employee offboarding

```text
Offboarding initiated
→ approval/effective date
→ handover/checklist
→ employment closed
→ access-review request
→ final payroll/entitlement input to Finance
→ historical record retained
```

---

# 13. User Stories & Acceptance Criteria

## US-001 — Manage canonical employee profile

**As** HR staff  
**I want** to create/manage an employee without duplicating canonical human identity  
**So that** employee data remains consistent across EduCore.

**Acceptance Criteria**

- **Given** HR has required tenant/scope capability, **when** employee provisioning is submitted, **then** Employee must be linked through canonical Membership/Person flow.
- **Given** a Person already exists and can be safely resolved, **when** employment is created, **then** implementation must not intentionally create a duplicate human identity.
- **Given** employee is created, **when** no account provisioning is requested, **then** User account must not be created implicitly.

## US-002 — Manage employment classification

**As** HR staff  
**I want** employment type and institutional classification to be managed separately  
**So that** status seperti kontrak/honorer tidak bercampur dengan GTY/GTT/PTY/PTT.

**Acceptance Criteria**

- **Given** an employee, **when** HR updates employment data, **then** employment type and classification must be independent attributes/business concepts.
- **Given** history changes, **when** a new status becomes effective, **then** previous relevant historical evidence must remain traceable.

## US-003 — Manage multi-unit placement and position

**As** HR staff  
**I want** to place an employee in one or more applicable units and positions  
**So that** workforce structure matches the yayasan organization.

**Acceptance Criteria**

- **Given** organizational topology exists, **when** employee placement is assigned, **then** placement must use existing organizational assignment semantics.
- **Given** a position is assigned, **when** authorization is evaluated, **then** position alone must not grant permission.
- **Given** a move/rotation occurs, **when** effective placement changes, **then** historical placement/position must remain traceable.

## US-004 — Manage recruitment and onboarding

**As** HR recruiter  
**I want** candidates tracked from vacancy to onboarding  
**So that** hiring decisions are transparent and repeatable.

**Acceptance Criteria**

- **Given** a candidate is in recruitment, **when** stages progress, **then** stage, evaluation, outcome, and authorized actor must be traceable.
- **Given** candidate is only shortlisted/approved for next stage, **when** workflow proceeds, **then** Employee must not yet be created automatically.
- **Given** candidate is finally hired, **when** onboarding provisions employment, **then** duplicate canonical Person/Membership/Employee must be prevented by technical design.

## US-005 — Request and approve leave

**As** an employee  
**I want** to request leave/permission and see its status  
**So that** leave administration is transparent.

**Acceptance Criteria**

- **Given** employee has applicable entitlement, **when** request is submitted, **then** balance/policy must be validated.
- **Given** configured approval policy, **when** request advances, **then** only authorized approver in applicable scope may approve/reject it.
- **Given** an approval requires independent approval, **when** requester attempts self-approval, **then** system must deny the action.
- **Given** request is approved/cancelled/rejected, **when** history is viewed, **then** status transition and decision evidence must remain traceable.

## US-006 — Reconcile attendance with HR

**As** HR staff  
**I want** to consume attendance and teaching-attendance facts  
**So that** attendance reporting and payroll inputs use traceable data.

**Acceptance Criteria**

- **Given** Attendance provides employee attendance facts, **when** HR reads attendance, **then** HR must not create a competing canonical attendance source.
- **Given** teacher attendance relates to a teaching schedule, **when** reconciliation occurs, **then** Academic remains source for schedule/teaching assignment.
- **Given** device integration is unavailable, **when** attendance is operated in initial phase, **then** authorized manual/import flow must remain possible.

## US-007 — Prepare payroll inputs

**As** HR/payroll operator  
**I want** to prepare compensation and payroll inputs  
**So that** Finance can execute payroll using reliable workforce facts.

**Acceptance Criteria**

- **Given** compensation and attendance facts are available, **when** payroll input is prepared, **then** each material input must be traceable to an approved/source fact.
- **Given** payroll is finalized, **when** payment/payable/accounting is created, **then** Finance must be source of truth for financial result.
- **Given** employee is authorized for self-service, **when** slip is available, **then** HR experience may display Finance result without redefining it as HR-owned financial data.

## US-008 — Manage performance and competency

**As** supervisor/HR  
**I want** to manage employee performance and development history  
**So that** evaluation and development decisions are evidence-based.

**Acceptance Criteria**

- **Given** a review cycle, **when** assessment is completed, **then** self/manager/observer evidence must remain associated with the cycle.
- **Given** a new review period starts, **when** new assessment is recorded, **then** previous review history must not be overwritten.
- **Given** certification has expiry date, **when** configured reminder threshold is reached, **then** authorized users must be able to see reminder/expiry status.

## US-009 — Manage HR documents and discipline

**As** HR staff  
**I want** to manage employment documents and disciplinary history securely  
**So that** legal/administrative evidence is controlled and traceable.

**Acceptance Criteria**

- **Given** a sensitive document/discipline record, **when** user requests access, **then** effective capability and scope must be evaluated.
- **Given** document/discipline status changes, **when** viewed later, **then** authorized users must be able to identify relevant historical changes.
- **Given** audit is generated, **when** metadata is written, **then** unnecessary sensitive payload must not be copied into audit metadata.

## US-010 — Offboard an employee

**As** HR staff  
**I want** to close employment cleanly  
**So that** employment, access review, handover, and final rights are coordinated.

**Acceptance Criteria**

- **Given** approved offboarding, **when** effective date is reached, **then** employment lifecycle must be closed according to policy.
- **Given** employment is closed, **when** identity is reviewed, **then** Person/User must not be hard-deleted merely because employment ended.
- **Given** final payroll/entitlement is required, **when** offboarding progresses, **then** HR must provide traceable input to Finance rather than perform financial settlement itself.

## US-011 — Generate HR reports

**As** yayasan/unit leader  
**I want** HR reports according to my authorized scope  
**So that** staffing decisions can be made without cross-unit data leakage.

**Acceptance Criteria**

- **Given** user has unit-scoped capability, **when** report is requested, **then** data outside effective scope must not be returned.
- **Given** tenant-wide authorized user, **when** cross-unit report is requested, **then** system may aggregate relevant organizations/units in the same tenant.
- **Given** report is exported, **when** file is generated, **then** export must contain no broader data than the authorized source query.

## US-012 — Prepare government reporting export

**As** HR operator  
**I want** to generate reporting exports  
**So that** required Dapodik/EMIS/Simpatika reporting can be prepared without manual re-entry where feasible.

**Acceptance Criteria**

- **Given** configured export mapping, **when** operator generates export, **then** system must validate required data before export.
- **Given** export file is generated, **when** status is shown, **then** system must describe it as generated/exported and not as submitted/accepted unless an external integration confirms that state.
- **Given** direct synchronization is not configured, **when** export is generated, **then** the workflow must remain usable without external API dependency.

---

# 14. High-Level Permission Capability Requirements

Exact permission names are Phase 2/architecture concern. Product semantics require capability groups at minimum for:

- employee read/manage
- employment read/manage
- position/placement read/manage
- recruitment read/manage/approve
- onboarding read/manage
- leave own/read/manage/approve
- attendance HR view/import/reconciliation
- compensation read/manage
- payroll input prepare/view
- payroll result/slip view
- benefit read/manage
- performance own/read/manage/approve
- competency read/manage
- document read/manage
- discipline read/manage
- offboarding read/manage/approve
- reporting/export

**Rule:** capability implementation must reuse existing Core authorization infrastructure, not hardcoded HR role checks.

---

# 15. Integration Requirements

| ID      | Integration            | Product Requirement                                                                             |
| ------- | ---------------------- | ----------------------------------------------------------------------------------------------- |
| INT-001 | Core Person            | HR consumes canonical human identity.                                                           |
| INT-002 | Core Membership/Tenant | Employee lifecycle operates in verified tenant participation context.                           |
| INT-003 | Core Organization      | Placement uses Organization/OrganizationUnit/OrganizationalAssignment.                          |
| INT-004 | Core Authorization     | HR actions use tenant/scoped capability evaluation.                                             |
| INT-005 | Core Audit             | Critical HR mutations and approvals emit safe audit events.                                     |
| INT-006 | Auth/User              | Employee account provisioning remains explicit/optional.                                        |
| INT-007 | Academic               | HR consumes teaching assignment/schedule facts where required; Academic owns academic schedule. |
| INT-008 | Attendance             | HR consumes workforce attendance/reconciliation facts; Attendance owns attendance record.       |
| INT-009 | Finance                | HR publishes/exports payroll inputs; Finance owns payroll run/payment/accounting result.        |
| INT-010 | Government Reporting   | Initial integration is export/report; direct API sync future.                                   |

---

# 16. Existing Code Change Classification

| Existing Component                | Classification     | Product Direction                                                                                 |
| --------------------------------- | ------------------ | ------------------------------------------------------------------------------------------------- |
| `Modules/HR`                      | EXTEND             | Tetap menjadi HR bounded context utama.                                                           |
| `Employee` canonical profile      | KEEP               | Tetap `Membership → Employee`.                                                                    |
| Person ownership                  | KEEP               | HR tidak menduplikasi human identity.                                                             |
| Employee provisioning transaction | KEEP / EXTEND      | Dipakai sebagai foundation lifecycle baru.                                                        |
| Optional User account             | KEEP               | Self-service tidak memaksa semua employee memiliki User.                                          |
| Core OrganizationalAssignment     | KEEP / REUSE       | Source untuk organizational participation/placement.                                              |
| Core RBAC/scoped authorization    | KEEP / REUSE       | HR menambah capability catalog, bukan auth engine.                                                |
| `employees.jabatan`               | REFACTOR GRADUALLY | Digantikan/diturunkan menjadi richer employment/position concepts tanpa breaking change mendadak. |
| Fixed `jabatan` request enum      | REFACTOR           | Tidak scalable untuk domain HR yang lebih luas.                                                   |
| HR route hanya tenant context     | EXTEND/HARDEN      | Tambahkan product capability checks pada technical phase.                                         |

---

# 17. Traceability Matrix — Phase 1

| Business Objective | Business Rules                 | Functional Requirements                        | User Stories                   | Phase 2 Technical Trace     |
| ------------------ | ------------------------------ | ---------------------------------------------- | ------------------------------ | --------------------------- |
| BO-001             | BR-001..BR-004, BR-010         | FR-001..FR-008                                 | US-001..US-003                 | Data/API/Module TBD Phase 2 |
| BO-002             | BR-005..BR-007, BR-020         | FR-006..FR-008, FR-048..FR-049                 | US-003, US-011                 | TBD Phase 2                 |
| BO-003             | BR-008..BR-011, BR-016..BR-022 | FR-009..FR-013, FR-018..FR-022, FR-028..FR-040 | US-004, US-005, US-008..US-010 | TBD Phase 2                 |
| BO-004             | BR-012..BR-015                 | FR-014..FR-017, FR-023..FR-027                 | US-006, US-007                 | TBD Phase 2                 |
| BO-005             | BR-010, BR-018..BR-022         | FR-030, FR-033..FR-050                         | US-008..US-012                 | TBD Phase 2                 |
| BO-006             | BR-002, BR-006..BR-007         | FR-022, FR-026, FR-046..FR-049                 | US-005, US-007, US-008         | TBD Phase 2                 |
| BO-007             | BR-020..BR-021                 | FR-041..FR-045                                 | US-011, US-012                 | TBD Phase 2                 |

---

# 18. Dependencies

- Accepted Core canonical identity/tenancy/membership contracts
- Core organizational topology and scoped authorization
- Existing Auth/User account contracts
- Academic employee/teacher integration contract
- Future Attendance capability/contract
- Finance payroll contract
- Frontend Foundation accepted contracts
- Audit foundation
- Document/file storage strategy during technical design
- Notification/reminder strategy during technical design

---

# 19. Risks

| ID          | Risk                                                   | Mitigation Direction                                                        |
| ----------- | ------------------------------------------------------ | --------------------------------------------------------------------------- |
| RISK-HR-001 | HR berubah menjadi god-module                          | Pertahankan boundary dengan Core, Attendance, Academic, Finance.            |
| RISK-HR-002 | `jabatan` menjadi overloaded enum                      | Refactor gradual ke employment/position concepts.                           |
| RISK-HR-003 | Position disalahgunakan sebagai authorization          | Seluruh authorization tetap Core capability-based.                          |
| RISK-HR-004 | Duplicate human identity saat recruitment conversion   | Fase 2 harus mendefinisikan canonical matching/conversion contract.         |
| RISK-HR-005 | Payroll ownership bercampur dengan Finance             | Lock HR-input vs Finance-finalization boundary.                             |
| RISK-HR-006 | Attendance vendor coupling                             | Canonical/manual/import first; adapter vendor future.                       |
| RISK-HR-007 | Sensitive employee data leakage                        | Least privilege, scoped access, private document access, safe audit/export. |
| RISK-HR-008 | Government export mapping berubah                      | Mapping/configuration isolated from HR canonical model.                     |
| RISK-HR-009 | Historical employment data hilang karena update/delete | Effective-dated/history-oriented lifecycle; no default hard delete.         |
| RISK-HR-010 | Approval hardcoded ke struktur satu yayasan            | Policy-driven approval.                                                     |

---

# 20. Resource Gaps / Open Items

Tidak ada critical product blocker untuk menutup Phase 1, tetapi item berikut tetap harus diselesaikan sebelum/selama fase berikutnya:

1. **[OPEN DECISION]** KPI numeric targets.
2. **[RESOURCE GAP]** Exact HR retention policy berdasarkan legal/compliance authority yang berlaku.
3. **[RESOURCE GAP]** Exact mapping/export requirements Dapodik, EMIS, dan Simpatika.
4. **[RESOURCE GAP]** Exact payroll/tax/BPJS calculation contract pada Finance PRD/implementation baseline yang akan digunakan.
5. **[OPEN DECISION]** Attendance phase sequencing per device/vendor setelah Attendance contract tersedia.
6. **[OPEN DECISION]** Notification/reminder delivery channels.
7. **[OPEN DECISION]** File storage/e-signature implementation — technical concern untuk fase selanjutnya.

---

# 21. Phase 1 Reviewer Assessment

**Quality Score:** 9.3/10

**Gaps**

- KPI target belum memiliki business baseline.
- Government reporting mapping belum diverifikasi.
- Exact regulatory retention dan payroll statutory mechanics belum menjadi verified resource.
- Recruitment candidate canonical identity conversion perlu keputusan architecture pada fase berikutnya.

**Risks**

- God-module boundary, duplicate identity, authorization coupling ke jabatan, data privacy, dan cross-module payroll/attendance ownership adalah risiko utama.

**Recommendations**

1. Lock PRD ini sebagai product/business contract terlebih dahulu.
2. Setelah approval, buat ADR `ADR-032 — HR Domain Boundary & Workforce Architecture` berdasarkan PRD ini.
3. Setelah ADR boundary accepted, lanjut Phase 2 secara bertahap mulai dari Employee/Employment/Position/Placement sebelum recruitment/payroll integration.
4. Jangan memulai implementation schema/API baru sebelum candidate identity conversion, position model, dan cross-module contracts dibahas pada Phase 2/ADR.

**Status:** READY WITH MINOR OPEN ITEMS

Phase 1 tidak memiliki critical gap yang mengharuskan requirement diulang dari nol. PRD dapat diajukan untuk approval; open items di atas tidak mengubah locked canonical architecture dan dapat ditindaklanjuti pada phase yang tepat.
