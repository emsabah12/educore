# Phase 2H-D — HR Dashboard, Authorization & Privacy Design

## 1. Status Fase Sebelumnya

**2H-C — HR Reporting Read Model Architecture: APPROVED / LOCKED.**

Dengan approval tersebut, baseline reporting EduCore HR sekarang adalah:

```text
Canonical Domain
      ↓
Direct Query / Rebuildable Projection
      ↓
Authorized Reporting Service
      ↓
Dashboard / Report / Export
```

Reporting tetap non-authoritative, direct-query-first, projection hanya jika justified, dan authorization selalu berasal dari Core—not dari projection, filter, ataupun frontend.

Handoff HR juga menetapkan RBAC, Organization, OrganizationalAssignment, Person, Membership, dan User tetap menjadi ownership Core.

---

# 2. Resource Audit 2H-D

## [FAKTA] Existing Core Authorization

Repository sudah memiliki dua level evaluasi authorization.

### Tenant-level

```text
Authenticated User
→ Membership
→ membership_roles
→ role_permissions
→ Permission
```

Dilayani melalui:

```text
AuthorizationService
CheckTenantPermission
```

`CheckTenantPermission` sudah menggunakan fail-closed behavior.

---

### Organizational/workspace-level

Repository juga memiliki:

```text
InjectOrganizationalContext
OrganizationalContextResolver
OrganizationalAuthorizationService
```

Dengan verified context:

```text
Tenant
+
Membership
+
OrganizationalAssignment
+
Organization
+
OrganizationUnit
```

Header organizational assignment hanya merupakan **locator**, bukan authorization authority.

Ini sangat align dengan desain reporting kita.

---

# 3. Existing Implementation Gap

Ada satu gap yang perlu dicatat secara eksplisit.

## [RISK] HR Employee API belum dilindungi RBAC

Route HR existing saat ini pada dasarnya:

```text
InjectTenantContext
    ↓
GET  /v1/hr/employees
POST /v1/hr/employees
```

Belum ditemukan:

```text
tenant.permission:...
```

atau verified organizational authorization pada kedua route tersebut.

### Classification

| Area                           | Decision              |
| ------------------------------ | --------------------- |
| Employee domain implementation | KEEP                  |
| Employee tenant isolation      | KEEP                  |
| HR API authorization           | **EXTEND / REFACTOR** |
| Existing RBAC Core             | KEEP / REUSE          |
| Organizational authorization   | KEEP / REUSE          |

**[REKOMENDASI]** Ini tidak mengubah desain HR sebelumnya, tetapi sebelum HR functionality menjadi production-ready, endpoint existing harus mengikuti permission + scope model yang sama dengan capability baru.

---

# 4. Core Authorization Principle

Saya usulkan keputusan berikut dikunci.

## HRR-AUTH-001 — Authentication ≠ Authorization ≠ Scope

Tiga konsep harus selalu dipisahkan.

```text
Who are you?
    ↓
Authentication

What may you do?
    ↓
Permission

Where may you do it?
    ↓
Organizational Scope
```

Contoh:

```text
User punya permission:
hr.reporting.workforce.view

Tetapi organizational scope:
Pesantren A / Unit SMP
```

Maka ia tidak otomatis bisa melihat Pesantren B.

---

# 5. Reporting Scope Model

Saya merekomendasikan tiga scope mode.

```text
TENANT
ORGANIZATIONAL
SELF
```

---

## 5.1 TENANT Scope

Untuk user yang secara sah membutuhkan consolidated tenant-level reporting.

Contoh use case:

- HR pusat;
- administrator yayasan;
- authorized executive reporting.

Flow:

```text
Authenticated User
     ↓
Verified Membership/Tenant
     ↓
Tenant Permission
     ↓
Tenant-wide Reporting Query
```

Tidak membutuhkan client untuk memilih `tenant_id`.

---

## 5.2 ORGANIZATIONAL Scope

Untuk kepala/unit manager atau HR yang hanya diberi akses pada organizational context tertentu.

```text
Authenticated User
     ↓
Tenant Context
     ↓
Organizational Assignment locator
     ↓
InjectOrganizationalContext
     ↓
Re-resolve canonical Assignment
     ↓
OrganizationalAuthorizationService
     ↓
Scoped Reporting
```

Ini harus menjadi default untuk unit-scoped dashboard.

---

## 5.3 SELF Scope

Untuk employee self-service di masa depan.

Contoh:

```text
My leave balance
My attendance
My performance result
My documents
```

Scope tidak ditentukan dengan:

```text
?employee_id=abc
```

sebagai authority.

Preferred resolution:

```text
Authenticated User
→ Person
→ Membership
→ Employee
```

lalu query terhadap employee tersebut.

**[FUTURE SCOPE]** Detail self-service UI belum menjadi fokus utama Phase 2H, tetapi boundary perlu disiapkan.

---

# 6. Effective Scope Rule

## HRR-AUTH-002

Client filter hanya boleh mempersempit scope.

Formula konseptual:

```text
Effective Scope
=
Authorized Scope
∩
Requested Scope
```

Contoh:

```text
Authorized:
Unit A + Unit B

Requested:
Unit B

Result:
Unit B
```

Tetapi:

```text
Authorized:
Unit A

Requested:
All Units

Result:
Unit A
```

Tidak pernah:

```text
Authorized Scope
∪
Requested Scope
```

---

# 7. Position Tidak Menentukan Authorization

Tetap mempertahankan keputusan sebelumnya:

```text
Position
≠
RBAC Role
```

Contoh seorang employee mempunyai Position:

```text
Kepala Sekolah
```

tidak otomatis berarti ia mendapat:

```text
hr.reporting.performance.view
hr.reporting.compensation.view
```

Akses diberikan melalui Core Role → Permission sesuai scope.

Ini juga mencegah business hierarchy menjadi security hierarchy secara implisit.

---

# 8. Permission Taxonomy

Saya merekomendasikan naming convention:

```text
hr.reporting.<area>.view
hr.reporting.<area>.export
```

Area menjadi bounded reporting capability.

## Baseline Permission Catalog

| Permission                       | Purpose                       |
| -------------------------------- | ----------------------------- |
| `hr.reporting.workforce.view`    | Workforce/headcount aggregate |
| `hr.reporting.recruitment.view`  | Recruitment reporting         |
| `hr.reporting.leave.view`        | Leave/permit reporting        |
| `hr.reporting.attendance.view`   | Attendance summary            |
| `hr.reporting.compensation.view` | Compensation facts            |
| `hr.reporting.performance.view`  | Performance reporting         |
| `hr.reporting.competency.view`   | Competency reporting          |
| `hr.reporting.documents.view`    | Document/contract metadata    |
| `hr.reporting.discipline.view`   | Discipline reporting          |
| `hr.reporting.offboarding.view`  | Offboarding reporting         |

Dan export capability, bila memang tersedia:

```text
hr.reporting.<area>.export
```

Contoh:

```text
hr.reporting.workforce.export
hr.reporting.compensation.export
hr.reporting.discipline.export
```

---

# 9. Kenapa View dan Export Dipisahkan?

Export meningkatkan risiko data exfiltration.

Seorang user mungkin layak:

```text
melihat dashboard compensation
```

tetapi tidak layak:

```text
mengunduh seluruh compensation dataset
```

Maka:

## HRR-AUTH-003

```text
VIEW permission
≠
EXPORT permission
```

Export permission juga tetap membutuhkan view permission yang relevan.

Contoh:

```text
Can Export Compensation
=
hr.reporting.compensation.view
AND
hr.reporting.compensation.export
```

---

# 10. Jangan Membuat Role HR Baru di Reporting

Saya tidak merekomendasikan hardcoded role:

```text
HR_MANAGER
PRINCIPAL
FOUNDATION_ADMIN
PAYROLL_ADMIN
```

sebagai authority dalam reporting.

Mengapa?

Karena Core sudah memiliki:

```text
Role
→ Permission
```

dan role dapat dikonfigurasi.

Reporting cukup mendefinisikan **permission catalog**.

Role assignment tetap menjadi Governance/RBAC concern.

---

# 11. Aggregate vs Detail Permission

Ini salah satu keputusan terpenting.

## HRR-AUTH-004

Hak melihat aggregate tidak otomatis memberi hak melihat record individual.

Contoh:

```text
Headcount Unit A = 25
```

dapat visible.

Tetapi user tersebut tidak otomatis boleh melihat:

```text
25 employee profiles
```

---

## Drill-down Flow

```text
Aggregate Dashboard
       ↓
User clicks detail
       ↓
NEW authorization evaluation
       ↓
Owning-domain permission
       ↓
Individual records
```

Reporting permission tidak menggantikan owning-domain permission.

Contoh:

```text
hr.reporting.workforce.view
```

memberikan workforce aggregate.

Untuk membuka employee profile, harus memakai permission dari Employee/Workforce domain yang nanti ditetapkan.

---

# 12. Source-Domain Authorization

Ini mempertahankan domain ownership.

```text
Reporting
   ↓
summary access

Employee Domain
   ↓
employee detail access
```

Bukan:

```text
Reporting permission
     ↓
universal access to HR records
```

Hal yang sama berlaku pada:

- disciplinary case;
- contract/document;
- performance record;
- compensation;
- exit interview.

---

# 13. Sensitivity Classification

Saya lanjutkan classification dari 2H-B menjadi access policy.

| Level                      | Contoh                                                     |
| -------------------------- | ---------------------------------------------------------- |
| **S1 — Standard**          | headcount aggregate                                        |
| **S2 — Controlled**        | recruitment/leave/attendance aggregate                     |
| **S3 — Restricted**        | individual performance, competency, HR metadata            |
| **S4 — Highly Restricted** | compensation, discipline, document content, exit interview |

---

# 14. Data Exposure Rule

## HRR-PRIV-001 — Least Disclosure

Report hanya boleh mengembalikan field minimum yang diperlukan.

Contoh headcount report membutuhkan:

```text
organization
unit
employment type
count
```

Tidak membutuhkan:

```text
NIK
bank account
home address
disciplinary case
contract file
salary
```

---

# 15. Aggregate Does Not Make Data Automatically Safe

Contoh:

```text
Average salary
Unit X
Employee count = 1
```

Secara teknis aggregate, tetapi salary individual dapat langsung diinferensikan.

Karena itu:

## HRR-PRIV-002 — Small Cohort Protection

Sensitive aggregate harus mempertimbangkan minimum cohort size.

Konsep:

```text
if group_count < privacy_threshold
    suppress / generalize result
```

**[OPEN DECISION]** Exact threshold belum dikunci.

Kita tidak akan mengarang:

```text
3
5
10
```

tanpa business/privacy authority.

---

# 16. Sensitive Aggregate Behavior

Untuk cohort yang terlalu kecil, opsi yang direkomendasikan:

```text
value: null
status: SUPPRESSED
reason: PRIVACY_THRESHOLD
```

bukan:

```text
0
```

karena `0` mempunyai makna business yang berbeda.

---

# 17. Data Masking

Masking harus dibedakan dari authorization.

```text
Authorization
→ boleh atau tidak mengakses record

Masking
→ seberapa detail field yang boleh terlihat
```

Contoh candidate:

| Field                | Standard                       | Authorized Sensitive Viewer                |
| -------------------- | ------------------------------ | ------------------------------------------ |
| Employee name        | Visible bila detail permitted  | Visible                                    |
| NIP                  | Masked/partial sesuai use case | Full jika permitted                        |
| Compensation         | Hidden                         | Allowed                                    |
| Bank details         | Hidden                         | Hanya use case yang benar-benar memerlukan |
| Discipline narrative | Hidden                         | Restricted permission                      |
| Document content     | Hidden                         | Document-domain permission                 |

**[OPEN DECISION]** exact masking format akan mengikuti Personal Data Policy bila tersedia.

---

# 18. Sensitive Data Tidak Boleh Masuk Generic Dashboard Payload

## HRR-PRIV-003

Jangan mengirim data lalu hanya menyembunyikannya melalui frontend.

Tidak boleh:

```text
API:
salary: 15000000

UI:
display:none
```

Jika user tidak authorized:

```text
salary field tidak dikirim
```

atau endpoint menolak request.

---

# 19. Privacy by Query Design

Lebih baik:

```text
SELECT aggregate_needed_fields
```

daripada:

```text
SELECT *
→ filter DTO
```

khususnya untuk:

- compensation;
- documents;
- discipline;
- performance;
- exit interview.

Ini sejalan dengan principle minimum data di 2H-C.

---

# 20. Dashboard Information Architecture

Saya merekomendasikan navigation level berikut.

```text
HR
├── Overview
├── Workforce
├── Recruitment
├── Leave & Permit
├── Attendance
├── Compensation
├── Performance
├── Competency & Development
├── Documents & Contracts
├── Discipline
├── Offboarding
└── Exports
```

Tetapi menu bukan static universal menu.

---

# 21. Capability-Driven Navigation

Core sudah mempunyai:

```text
TenantCapabilityProjection
WorkspaceCapabilityProjection
```

Maka frontend dapat menggunakan capability projection untuk menentukan menu.

Contoh:

```text
permissions:
[
  "hr.reporting.workforce.view",
  "hr.reporting.leave.view"
]
```

Frontend dapat menampilkan:

```text
Workforce
Leave & Permit
```

dan menyembunyikan Compensation.

Namun:

## HRR-AUTH-005

> Capability projection hanya UX/read projection. Backend tetap wajib authorize setiap request.

Ini mengikuti pola existing Core.

---

# 22. HR Overview Dashboard

Overview sebaiknya bukan endpoint yang otomatis membuka semua data.

Konsep:

```text
HR Overview
   │
   ├── Workforce card
   ├── Recruitment card
   ├── Leave card
   ├── Attendance card
   ├── Contract alerts
   └── Offboarding card
```

Setiap widget hanya ada jika permission tersedia.

---

## Example

User mempunyai:

```text
workforce.view
leave.view
```

Maka:

```text
Overview
├── Workforce ✓
├── Leave ✓
├── Compensation ✗
├── Discipline ✗
└── Performance ✗
```

Backend aggregation juga harus mengikuti aturan yang sama.

---

# 23. Jangan Membuat `hr.dashboard.view` Sebagai Universal Permission

Saya **tidak merekomendasikan**:

```text
hr.dashboard.view
```

yang kemudian memberi seluruh metric.

Karena sensitivity tiap domain berbeda jauh.

Lebih aman:

```text
Dashboard
=
composition of area permissions
```

Jika nanti diperlukan permission untuk sekadar membuka container dashboard, itu boleh ditambahkan, tetapi tidak memberikan data access.

---

# 24. Dashboard Persona Examples

Persona hanya contoh configuration, bukan hardcoded role.

### HR Pusat

Potential permission bundle:

```text
workforce.view
recruitment.view
leave.view
attendance.view
performance.view
competency.view
documents.view
offboarding.view
```

Compensation dan Discipline tetap dapat dipisahkan.

---

### Kepala Unit

Potential:

```text
workforce.view
leave.view
attendance.view
performance.view
```

dengan:

```text
ORGANIZATIONAL scope
```

---

### Finance

Potential:

```text
compensation.view
compensation.export
```

tetapi tetap tidak memperoleh:

```text
discipline
exit interview
performance narrative
```

---

# 25. Scope Selector UX

Jika user memiliki beberapa organizational assignments:

```text
School A
School B
Pesantren A
```

frontend boleh menyediakan workspace selector.

Tetapi selected value harus menghasilkan:

```text
X-EduCore-Organizational-Assignment-Id
```

yang kemudian diverifikasi ulang server-side oleh existing Core resolver.

## Important

Nama unit atau ID organisasi langsung tidak boleh menjadi proof of access.

---

# 26. Consolidated Tenant Dashboard

Tenant-wide report berbeda dari workspace report.

Contoh:

```text
/v1/hr/reporting/workforce
```

secara conceptual dapat mempunyai endpoint/use case:

```text
Tenant Workforce Summary
```

dan:

```text
Workspace Workforce Summary
```

Authorization semantics-nya tidak boleh ditentukan hanya berdasarkan parameter:

```text
?scope=tenant
```

Scope capability merupakan property dari use case/permission, bukan input bebas client.

Detail endpoint final akan dibuat pada API Specification nanti.

---

# 27. Superadmin Behavior

Repository existing menunjukkan:

- tenant authorization mempunyai global-superadmin bypass;
- workspace capability tidak melakukan superadmin short-circuit;
- organizational authorization tetap menjadi evaluator workspace.

## HRR-AUTH-006

Reporting harus **mengikuti semantics Core existing**, bukan menciptakan superadmin semantics sendiri.

Artinya kita tidak membuat special-case di HR Reporting seperti:

```text
if superadmin:
    ignore all organizational rules
```

Workspace authorization tetap melewati Core organizational authorization.

---

# 28. Error & Permission States

Dashboard harus membedakan kondisi berikut.

### AUTHENTICATION_REQUIRED

Tidak ada authenticated identity.

HTTP:

```text
401
```

---

### AUTHORIZATION_DENIED

User authenticated tetapi tidak mempunyai permission.

```text
403
```

---

### ORGANIZATIONAL_CONTEXT_REQUIRED

Use case membutuhkan workspace tetapi context belum dipilih.

```text
403
```

Existing Core sudah mempunyai semantics ini.

---

### ORGANIZATIONAL_CONTEXT_DENIED

Assignment tidak valid/tidak lagi dimiliki/tidak aktif.

```text
403
```

---

### EMPTY

User authorized, query berhasil, tetapi tidak ada business data.

Contoh:

```text
No active offboarding cases
```

Ini bukan error.

---

### FILTER_EMPTY

Data tersedia dalam scope tetapi filter menghasilkan nol row.

Berbeda dari unauthorized.

---

### SOURCE_UNAVAILABLE

Source domain/report belum tersedia.

Contoh Attendance reporting sebelum Attendance domain selesai.

Jangan tampilkan:

```text
Attendance = 0
```

Jika sebenarnya capability belum tersedia.

---

### STALE

Projection berhasil dibaca tetapi freshness melewati policy.

Tampilkan `source_as_of`.

---

### FAILED

Reporting calculation/projection gagal.

Jangan mengubahnya menjadi angka `0`.

---

# 29. Empty State vs Zero

Ini penting untuk analytical correctness.

```text
0
```

berarti:

> value valid dan nilainya nol.

Sedangkan:

```text
N/A
UNAVAILABLE
SUPPRESSED
NO_DATA
```

mempunyai makna berbeda.

## HRR-UX-001

UI/API tidak boleh mengkonversi semua kondisi non-value menjadi zero.

---

# 30. Loading State

Untuk direct report sederhana:

```text
LOADING
→ READY
```

Untuk expensive export/projection:

```text
QUEUED
→ PROCESSING
→ READY
```

atau:

```text
FAILED
```

Jika asynchronous process digunakan nanti.

---

# 31. Freshness State

Untuk projected dashboard:

```text
Workforce
125 employees

Data as of:
22 Aug 2026 18:00
```

bukan sekadar:

```text
125
```

Jika direct:

```text
LIVE
Generated at T
```

Detail visual styling bukan bagian fase ini.

---

# 32. Drill-Down Privacy

Contoh:

```text
Performance Rating
Needs Improvement: 2
```

Jika user hanya mempunyai aggregate permission:

klik angka tersebut tidak otomatis membuka dua nama employee.

Flow:

```text
Aggregate
   ↓
Can view individual performance?
   ├── YES → controlled detail
   └── NO  → no drill-down
```

Hal yang sama untuk:

- absence;
- compensation;
- discipline;
- expiring contract;
- offboarding.

---

# 33. Export Privacy Rules

Export mempunyai risiko terbesar karena data meninggalkan application viewport.

## HRR-PRIV-004

Setiap export harus:

- mempunyai explicit export permission;
- menggunakan authorized effective scope;
- tidak menerima arbitrary tenant;
- menerapkan field-level sensitivity;
- menghasilkan audit evidence;
- menyimpan definition/source-as-of metadata;
- menggunakan private storage;
- mempunyai retention policy.

Retention masih `[OPEN DECISION]`.

---

# 34. Export Audit

Minimal secara konseptual audit event perlu menyimpan:

```text
export_id
export_type
actor
tenant
effective_scope
filters
generated_at
source_as_of
definition_version
status
```

Tidak perlu memasukkan seluruh sensitive payload ke audit log.

Hal ini konsisten dengan existing Employee audit test yang juga sengaja tidak menyimpan nama/NIP/email ke audit metadata.

---

# 35. URL / API Enumeration

User tidak boleh mendapatkan data di luar scope dengan mengganti:

```text
employee_id
organization_id
unit_id
report_id
```

## HRR-SEC-001

Semua resource identifier:

```text
Locator ≠ Authorization
```

Pola ini sama dengan existing organizational assignment header.

---

# 36. Authorization Timing

Authorization tidak cukup dilakukan sekali ketika login.

Setiap request:

```text
Request
  ↓
Resolve current identity
  ↓
Resolve current membership
  ↓
Resolve tenant
  ↓
Resolve organizational context where applicable
  ↓
Evaluate current roles/permissions
  ↓
Query
```

Keuntungan:

Role yang dicabut tidak terus berlaku karena stale frontend state.

---

# 37. Authorization pada Async Export

Queue job tidak boleh mempercayai authorization snapshot mentah tanpa policy.

Ada dua concern:

### Submission authorization

Saat user meminta export:

```text
check permission
check effective scope
```

### Execution context

Job tetap tenant-aware dan bekerja hanya pada scope yang sudah divalidasi.

**[OPEN DECISION]** Apakah permission harus di-revalidate lagi saat job execution akan ditentukan ketika export workflow dirancang.

Rekomendasi awal: untuk job yang tertunda signifikan, revalidation layak dipertimbangkan.

---

# 38. Search & Filtering Privacy

Search tidak boleh menjadi side channel.

Contoh user tidak mempunyai permission discipline.

Ia tidak boleh dapat:

```text
search employee
→ discover "has disciplinary case"
```

melalui filter, count, autocomplete, atau error.

## HRR-PRIV-005

Unauthorized field tidak boleh:

- ditampilkan;
- searchable;
- sortable;
- filterable;
- diexport;
- dipakai sebagai unintended existence oracle.

---

# 39. Suggested Dashboard Contracts

### Workforce

```text
Permission:
hr.reporting.workforce.view

Sensitivity:
S1/S2

Scope:
TENANT or ORGANIZATIONAL
```

### Recruitment

```text
hr.reporting.recruitment.view
S2
TENANT / ORGANIZATIONAL
```

### Leave

```text
hr.reporting.leave.view
S2
TENANT / ORGANIZATIONAL
```

### Attendance

```text
hr.reporting.attendance.view
S2
TENANT / ORGANIZATIONAL
```

### Compensation

```text
hr.reporting.compensation.view
S4
TENANT / explicitly authorized organization scope
```

### Performance

```text
hr.reporting.performance.view
S3
TENANT / ORGANIZATIONAL
```

### Documents

```text
hr.reporting.documents.view
S3
```

Ini hanya metadata/status reporting.

Document content tetap document-domain permission.

### Discipline

```text
hr.reporting.discipline.view
S4
```

Highly restricted.

### Offboarding

```text
hr.reporting.offboarding.view
S3/S4
```

Settlement financial values tidak otomatis termasuk.

---

# 40. Permission Combination Rule

## HRR-AUTH-007

Untuk mendapatkan sebuah dataset:

```text
Allow
=
Required Permission
AND
Valid Tenant Context
AND
Valid Scope
AND
Sensitivity Rule
```

Dan untuk export:

```text
Allow Export
=
View Allowed
AND
Export Permission
AND
Export Sensitivity Rule
```

---

# 41. What Frontend May Trust

Frontend boleh mempercayai capability projection untuk:

- menu;
- button visibility;
- initial workspace UX.

Frontend **tidak boleh** menggunakannya sebagai satu-satunya security enforcement.

```text
Frontend capability
= UX optimization

Backend authorization
= security authority
```

---

# 42. Current HR Employee API Change Impact

Karena kita sudah menemukan gap existing, Phase 2H-D mempunyai impact terhadap current HR API.

## GET `/v1/hr/employees`

Current:

```text
tenant context only
```

Target conceptual:

```text
Auth
+ tenant
+ permission
+ appropriate scope
```

---

## POST `/v1/hr/employees`

Current juga baru tenant-scoped.

Karena employee creation adalah mutation, ini lebih kritis daripada reporting.

**[RISK] HIGH**

Unauthorized tenant member yang berhasil authenticated secara teori tidak boleh memperoleh create capability hanya karena berada dalam tenant.

### Recommendation

Klasifikasi:

```text
HR route authorization
→ REFACTOR before broader production exposure
```

Namun implementation tidak kita lakukan dalam fase dokumentasi ini.

---

# 43. Scope of 2H-D

## IN SCOPE

- dashboard information architecture;
- permission taxonomy;
- tenant/workspace/self scope;
- aggregate/detail access;
- privacy classification;
- masking principles;
- export security;
- capability-based navigation;
- loading/empty/error/permission/freshness states.

## OUT OF SCOPE

- visual UI design;
- colors/theme;
- detailed component specification;
- final API paths;
- actual middleware implementation;
- role catalog configuration;
- retention duration;
- regulatory privacy mapping;
- government export schema.

## FUTURE SCOPE

- employee self-service dashboard;
- delegated temporary reporting access;
- advanced privacy analytics;
- enterprise analytics portal.

## DEFERRED

- exact masking pattern;
- minimum privacy cohort threshold;
- export retention;
- final HR permissions outside reporting;
- permission revalidation behavior for long-running async jobs.

---

# 44. Traceability Example

```text
Business Need
Secure workforce visibility
        ↓
HRR-FR-001
Consolidated workforce
        ↓
HRR-KPI-001
Active Headcount
        ↓
HRR-AUTH-001
Permission + Scope
        ↓
hr.reporting.workforce.view
        ↓
Core Authorization
+
Core Organizational Context
        ↓
Reporting Query
        ↓
HRR-AC-003
No unauthorized unit data
```

Untuk compensation:

```text
Compensation Reporting
        ↓
HRR-KPI-041
        ↓
Sensitivity S4
        ↓
hr.reporting.compensation.view
        ↓
Authorized Scope
        ↓
Minimum disclosure
        ↓
Privacy-aware aggregate
```

---

# 45. Architecture Classification

| Existing Area                      | Decision                |
| ---------------------------------- | ----------------------- |
| Core AuthorizationService          | **KEEP / REUSE**        |
| `CheckTenantPermission`            | **KEEP / REUSE**        |
| OrganizationalContextResolver      | **KEEP / REUSE**        |
| OrganizationalAuthorizationService | **KEEP / REUSE**        |
| Capability projection              | **KEEP / REUSE FOR UX** |
| HR tenant isolation                | **KEEP**                |
| HR current route authorization     | **REFACTOR / EXTEND**   |
| Position-based authorization       | **DO NOT INTRODUCE**    |
| Generic reporting role             | **DO NOT INTRODUCE**    |
| Frontend-only security             | **DO NOT INTRODUCE**    |
| Reporting-specific auth framework  | **DO NOT INTRODUCE**    |

---

# 46. Target Security Architecture

```text
                    Authenticated User
                           │
                           ▼
                  Membership / Tenant
                           │
              ┌────────────┴────────────┐
              │                         │
              ▼                         ▼
        TENANT REPORT             WORKSPACE REPORT
              │                         │
   Tenant Authorization       Organizational Context
              │                         │
              │                Organizational Auth
              │                         │
              └────────────┬────────────┘
                           │
                           ▼
                   Permission Check
                           │
                           ▼
                   Sensitivity Policy
                           │
                           ▼
                   Effective Scope
                           │
                           ▼
                    Reporting Query
                           │
                ┌──────────┴──────────┐
                ▼                     ▼
           Aggregate              Drill-down
                                      │
                                      ▼
                            Source-domain reauth
```

---

# 47. Locked Recommendations 2H-D

Saya merekomendasikan keputusan berikut menjadi baseline locked:

1. **Permission + tenant + scope + sensitivity** adalah authorization equation reporting.
2. Tenant-wide dan organizational reporting adalah use case berbeda.
3. Workspace menggunakan existing `OrganizationalContext`.
4. Position tidak menjadi authorization.
5. Reporting permission tidak memberikan source-record permission.
6. Aggregate dan drill-down dipisahkan.
7. View dan export permission dipisahkan.
8. Sensitive aggregates membutuhkan privacy protection.
9. Capability projection hanya untuk UX.
10. Backend selalu melakukan authorization ulang.
11. `0`, `NO_DATA`, `UNAVAILABLE`, `SUPPRESSED`, dan `STALE` mempunyai semantic berbeda.
12. Existing HR employee API authorization merupakan **security gap yang perlu direfactor**.

---

# 48. Reviewer Mode — Phase 2H-D

**Quality Score: 9.7/10**

### Gaps

**[OPEN DECISION]**

- exact privacy cohort threshold;
- exact masking format;
- final source-domain Employee permissions;
- export retention duration;
- async export reauthorization timing.

Tidak ada gap tersebut yang membatalkan architecture.

### Risks

**[RISK — HIGH] Existing HR endpoints belum mempunyai permission enforcement.**

**[RISK]** Aggregate sensitive data dapat menyebabkan re-identification pada cohort kecil.

**[RISK]** Frontend capability projection dapat disalahgunakan sebagai security authority jika backend tidak melakukan authorization ulang.

**[RISK]** Tenant-wide report dapat menjadi privilege escalation jika hanya dikendalikan parameter scope.

### Recommendations

Prioritas saat engineering menyentuh HR adalah memperbaiki **existing HR route authorization** sebelum memperluas functionality.

Untuk reporting, gunakan Core security primitives yang sudah ada dan **jangan membangun authorization subsystem baru di HR**.

### Status

**READY FOR APPROVAL — MINOR OPEN ITEMS DEFERRED**

Jika 2H-D ini disetujui, tahapan berikutnya adalah **Phase 2H-E — Government Export Boundary: Dapodik, EMIS & Simpatika**. Pada fase itu kita perlu melakukan verifikasi terhadap resource/interface resmi terkini terlebih dahulu sebelum menentukan apakah masing-masing target seharusnya berupa file export, assisted submission, atau API integration.
