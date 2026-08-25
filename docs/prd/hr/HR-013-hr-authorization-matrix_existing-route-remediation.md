# HR-013 — HR Authorization Matrix & Existing Route Remediation

**Version:** 0.1 Draft
**Phase:** 3D — Full HR Authorization Matrix & Existing Route Remediation
**Status:** APPROVED / LOCKED
**Approval Date:** 2026-08-23
**Depends On:** HR-001–HR-012, ADR-016, ADR-018, ADR-027, ADR-032

---

# 1. Objective

HR-013 mendefinisikan canonical authorization contract untuk seluruh HR capability dan remediation terhadap HR API existing.

Target decision:

```text
Authentication
+
Verified Tenant / Membership
+
Permission
+
Required Organizational Context
+
Target Resource Scope
+
Business State
+
Sensitivity Policy
=
ALLOW
```

Tidak satu pun komponen berikut boleh menjadi authorization source:

```text
Employee.jabatan
Position
Job title
Frontend navigation
Bearer-token role claims
Client-provided organization_id
Client-provided permission
```

---

# 2. Resource Audit

## [FAKTA] Existing Core Authorization

Repository telah mempunyai:

```text
Membership
→ MembershipRole
→ Role
→ RolePermission
→ Permission
```

dengan canonical evaluator:

```text
AuthorizationService
```

untuk tenant-wide authorization.

Repository juga telah mempunyai:

```text
OrganizationalAssignment
→ OrganizationalAssignmentRole
→ Role
→ Permission
```

dengan evaluator:

```text
OrganizationalAuthorizationService
```

untuk organizational workspace.

---

## [FAKTA] Effective organizational role semantics

ADR-018 dan implementation existing menetapkan:

```text
Organization Workspace:
Tenant Roles
∪ Organization Roles

OrganizationUnit Workspace:
Tenant Roles
∪ Organization Roles
∪ Exact Unit Roles
```

Tidak ada:

```text
unit → parent escalation
sibling-unit inheritance
DENY precedence
permission override
```

---

## [FAKTA] Current HR Route

Current:

```text
GET  /v1/hr/employees
POST /v1/hr/employees
```

hanya memakai:

```text
InjectTenantContext
```

Belum mempunyai:

```text
tenant.permission:...
```

atau organizational scoped authorization.

`StoreEmployeeRequest::authorize()` juga masih:

```text
return true;
```

---

# 3. Existing Classification

| Area                                      | Decision                      |
| ----------------------------------------- | ----------------------------- |
| Core `AuthorizationService`               | **KEEP**                      |
| Core `OrganizationalAuthorizationService` | **KEEP**                      |
| `membership_roles`                        | **KEEP**                      |
| `organizational_assignment_roles`         | **KEEP**                      |
| Tenant capability projection              | **KEEP**                      |
| Workspace capability projection           | **KEEP**                      |
| HR permission catalog                     | **ADD**                       |
| Organizational permission middleware      | **EXTEND Core**               |
| HR resource-scope checks                  | **ADD in HR/domain boundary** |
| Role-name authorization                   | **DO NOT INTRODUCE**          |
| `Employee.jabatan` authorization          | **PROHIBITED**                |
| Client capability projection as authority | **PROHIBITED**                |

---

# 4. Authorization Scope Model

HR menggunakan tiga authorization dimensions yang berbeda.

## 4.1 Tenant Scope

Digunakan ketika operation memang tenant-wide.

```text
Verified Tenant/Membership
+
AuthorizationService
+
Permission
```

Tenant-scoped role grant berasal dari:

```text
membership_roles
```

---

## 4.2 Organizational Scope

Digunakan ketika operation dibatasi Organization/OrganizationUnit.

```text
Verified Tenant
+
Verified OrganizationalAssignment
+
OrganizationalAuthorizationService
+
Permission
+
Target Resource Scope
```

Scoped role grant berasal dari:

```text
organizational_assignment_roles
```

---

## 4.3 Subject / Resource Scope

Permission saja belum cukup.

Contoh:

```text
User mempunyai hr.leave.view
```

tidak otomatis berarti seluruh leave record tenant dapat dibaca.

Resource authorization juga harus membuktikan target record berada pada scope yang diizinkan.

---

# 5. Critical Authorization Rule

### HR-013-BR-001

```text
Permission
≠ Resource Ownership
```

ADR-018 sudah menetapkan downstream domain wajib memverifikasi resource ownership selain scoped permission.

Karena itu:

```text
OrganizationalAuthorizationService::hasPermission()
```

tidak boleh menjadi satu-satunya check ketika endpoint mengakses employee/case tertentu.

---

# 6. Target Employee Scope Rule

Untuk HR resource yang ownership-nya berasal dari Employee:

```text
Employee
→ Membership
→ OrganizationalAssignment
```

### Organization context

Target Employee considered within scope jika Membership target memiliki relevant ACTIVE assignment pada Organization tersebut.

### OrganizationUnit context

Target Employee considered within scope hanya jika Membership target mempunyai relevant ACTIVE assignment pada exact OrganizationUnit tersebut.

Default:

```text
Sibling Unit
→ DENY

Different Organization
→ DENY

Different Tenant
→ DENY
```

---

## [RISK]

Employee dapat mempunyai lebih dari satu OrganizationalAssignment.

Karena itu filtering tidak boleh menggunakan single cached:

```text
employee.organization_id
```

atau legacy:

```text
employee.jabatan
```

---

# 7. Historical Transaction Scope

## [OPEN DECISION]

Untuk historical HR records seperti:

- leave;
- attendance reconciliation;
- performance assessment;
- discipline;
- offboarding;

masih perlu ditetapkan apakah authorization mengikuti:

```text
A. current Employee organizational placement

atau

B. organizational scope attached/effective ketika transaction dibuat
```

### [REKOMENDASI]

Historical/case records sebaiknya menggunakan **record/effective scope**, bukan semata current Employee placement.

Alasan:

```text
Employee Unit A
→ record created
→ later moved to Unit B
```

tidak seharusnya otomatis mengubah ownership historical case hanya karena current placement berubah.

**Implementation detail dan persistence impact harus diselesaikan pada API/data specification capability terkait.**

Ini tidak menghalangi remediation current Employee endpoints.

---

# 8. Permission Naming Convention

Canonical HR permissions menggunakan:

```text
hr.<resource>.<action>
```

atau bila perlu:

```text
hr.<resource>.<subresource>.<action>
```

Examples:

```text
hr.employees.view
hr.leave.approve
hr.government.exports.generate
```

Permission adalah global DB catalog entity dengan:

```text
module = HR
```

Role-to-permission mapping tetap database-backed.

---

# 9. Canonical HR Permission Catalog

## 9.1 Self Service

| Permission                  | Capability                                                  |
| --------------------------- | ----------------------------------------------------------- |
| `hr.self.profile.view`      | View own HR profile                                         |
| `hr.self.leave.view`        | View own leave                                              |
| `hr.self.leave.request`     | Submit/manage own leave request where business state allows |
| `hr.self.attendance.view`   | View own attendance                                         |
| `hr.self.compensation.view` | View own compensation facts when tenant policy permits      |
| `hr.self.performance.view`  | View own released performance/development information       |
| `hr.self.documents.view`    | View own authorized documents                               |

Self permissions always require:

```text
Authenticated Membership
=
target Employee.membership_id
```

They never authorize another Employee.

---

# 10. Workforce Permissions

| Permission                    | Purpose                                           |
| ----------------------------- | ------------------------------------------------- |
| `hr.employees.view`           | View Employee directory/profile                   |
| `hr.employees.sensitive.view` | View sensitive Employee detail                    |
| `hr.employees.create`         | Create/provision Employee                         |
| `hr.employees.update`         | Update editable Employee profile                  |
| `hr.employments.view`         | View Employment history                           |
| `hr.employments.manage`       | Create/update non-final Employment lifecycle data |
| `hr.employments.end`          | End active Employment                             |

### HR-013-BR-002

```text
hr.employees.update
```

tidak memberikan:

```text
hr.employments.end
```

karena lifecycle termination adalah higher-impact operation.

---

# 11. Organizational Placement Permission

OrganizationalAssignment dimiliki Core.

Karena itu HR **tidak** memperkenalkan permission:

```text
hr.employee.assign-organization
```

sebagai duplicate ownership.

## [RESOURCE GAP]

Canonical Core permission untuk OrganizationalAssignment management belum tersedia.

### Required future direction

HR UI dapat memanggil Core organizational placement capability, tetapi backend authority harus tetap:

```text
Core Organization
```

dengan Core-owned permission.

---

# 12. Recruitment Permissions

| Permission               | Purpose                                              |
| ------------------------ | ---------------------------------------------------- |
| `hr.recruitment.view`    | Candidate/application/selection read                 |
| `hr.recruitment.manage`  | Candidate/application/selection operational mutation |
| `hr.recruitment.approve` | Hiring approval                                      |
| `hr.recruitment.convert` | Authorized Candidate → identity/Employee conversion  |

### HR-013-BR-003

`hr.recruitment.manage` tidak otomatis memberikan hiring approval.

### HR-013-BR-004

Identity conversion dipisahkan karena dapat membuat canonical:

```text
Person
Membership
Employee
Employment PLANNED
```

sesuai workflow yang telah dikunci.

---

# 13. Leave & Permit Permissions

| Permission                     | Purpose                                           |
| ------------------------------ | ------------------------------------------------- |
| `hr.leave.view`                | View authorized employee leave records            |
| `hr.leave.approve`             | Approve/reject according to business workflow     |
| `hr.leave.entitlements.manage` | Manage entitlement/authorized ledger transactions |

Self-service menggunakan permission terpisah:

```text
hr.self.leave.view
hr.self.leave.request
```

### HR-013-BR-005

Approval membutuhkan:

```text
Permission
+
Resource Scope
+
Approval Business Rule
+
Current Request State
```

Permission bukan bukti bahwa user adalah valid approver untuk setiap request.

---

# 14. Attendance Permissions

| Permission                | Purpose                                   |
| ------------------------- | ----------------------------------------- |
| `hr.attendance.view`      | View authorized attendance facts/evidence |
| `hr.attendance.reconcile` | Perform reconciliation                    |
| `hr.attendance.finalize`  | Finalize attendance where domain allows   |

Self:

```text
hr.self.attendance.view
```

`reconcile` dan `finalize` sengaja dipisahkan.

---

# 15. Compensation & Payroll Input Permissions

| Permission                 | Purpose                                      |
| -------------------------- | -------------------------------------------- |
| `hr.compensation.view`     | View compensation/benefit facts              |
| `hr.compensation.manage`   | Manage compensation/benefit facts            |
| `hr.payroll.inputs.view`   | View HR-owned payroll input facts            |
| `hr.payroll.inputs.manage` | Create/update authorized payroll input facts |

Employee self-view:

```text
hr.self.compensation.view
```

### HR-013-BR-006

Tidak ada HR permissions seperti:

```text
hr.payroll.calculate
hr.payroll.pay
hr.payroll.post-accounting
```

karena operation tersebut dimiliki Finance.

---

# 16. Performance & Development Permissions

| Permission                | Purpose                                     |
| ------------------------- | ------------------------------------------- |
| `hr.performance.view`     | View authorized assessment                  |
| `hr.performance.manage`   | Manage draft/in-progress performance record |
| `hr.performance.finalize` | Finalize authorized assessment              |
| `hr.competency.view`      | View competency data                        |
| `hr.competency.manage`    | Manage competency records                   |
| `hr.development.view`     | View PKB/development information            |
| `hr.development.manage`   | Manage development records                  |

Self:

```text
hr.self.performance.view
```

Finalization tidak implied oleh manage.

---

# 17. Documents & Agreements Permissions

| Permission               | Purpose                                                     |
| ------------------------ | ----------------------------------------------------------- |
| `hr.documents.view`      | View authorized employee documents                          |
| `hr.documents.manage`    | Upload/manage non-final document versions                   |
| `hr.documents.finalize`  | Finalize document version                                   |
| `hr.agreements.view`     | View Employment Agreements                                  |
| `hr.agreements.manage`   | Manage draft/current agreement lifecycle                    |
| `hr.agreements.finalize` | Finalize/signing-transition operation controlled by EduCore |

Self:

```text
hr.self.documents.view
```

Actual provider-side signing credential tetap bukan permission catalog concern.

---

# 18. Discipline Permissions

| Permission               | Purpose                            |
| ------------------------ | ---------------------------------- |
| `hr.discipline.view`     | View authorized disciplinary cases |
| `hr.discipline.manage`   | Manage case/evidence/action draft  |
| `hr.discipline.finalize` | Finalize disciplinary action       |

No permission implies automatic mutation terhadap:

- Employment;
- Position;
- Compensation;
- Membership Role.

---

# 19. Offboarding Permissions

| Permission                | Purpose                                    |
| ------------------------- | ------------------------------------------ |
| `hr.offboarding.view`     | View authorized cases                      |
| `hr.offboarding.manage`   | Manage checklist/handover/exit information |
| `hr.offboarding.approve`  | Approve required offboarding decision      |
| `hr.offboarding.complete` | Complete offboarding case                  |

### HR-013-BR-007

```text
hr.offboarding.complete
≠ hr.employments.end
```

dan keduanya tetap independent permissions.

---

# 20. Access Review Boundary

Role grants dan Membership lifecycle dimiliki Core Authorization/Tenancy.

Karena itu Offboarding Access Review boleh:

```text
inspect
recommend
request
```

tetapi actual revocation harus mengikuti Core authorization permission/policy.

HR tidak memperkenalkan duplicate:

```text
hr.roles.revoke
```

---

# 21. Reporting Permissions

| Permission          | Purpose                                     |
| ------------------- | ------------------------------------------- |
| `hr.reports.view`   | View authorized HR aggregate/report results |
| `hr.reports.export` | Export authorized report output             |

Locked rule:

```text
View
≠ Export
```

---

## Individual detail within report

`hr.reports.view` tidak otomatis memberi:

```text
employee sensitive detail
compensation detail
discipline detail
```

Report result harus mematuhi relevant source-domain permissions.

---

# 22. Government Export Permissions

| Permission                       | Purpose                                     |
| -------------------------------- | ------------------------------------------- |
| `hr.government.exports.view`     | View export run/history/status              |
| `hr.government.exports.generate` | Generate authorized Dapodik/EMIS export     |
| `hr.government.exports.download` | Retrieve authorized private export artifact |

Separation diperlukan karena:

```text
generate
≠ retrieve artifact
```

dan private artifact dapat mempunyai sensitivity lebih tinggi daripada viewing run metadata.

---

# 23. Permission Catalog Summary

Canonical baseline:

```text
Self Service
  7

Workforce
  7

Recruitment
  4

Leave
  3

Attendance
  3

Compensation / Payroll Input
  4

Performance / Competency / Development
  7

Documents / Agreement
  6

Discipline
  3

Offboarding
  4

Reporting
  2

Government Export
  3
```

Total:

```text
53 canonical HR permissions
```

Granularity ini dipilih karena memisahkan:

- read vs mutation;
- ordinary mutation vs approval/finalization;
- self vs other-person access;
- view vs export;
- HR ownership vs external domain ownership.

---

# 24. Role Model

HR-013 **tidak mengunci role name sebagai authorization contract**.

Concept:

```text
Role
= bundle of permissions
```

Tenant dapat mempunyai role seperti:

```text
HR Administrator
HR Operator
Principal / Approver
Finance Collaborator
Employee Self-Service
```

tetapi permission tetap authority.

Changing:

```text
role display name
```

tidak boleh mengubah application logic.

---

# 25. Recommended Permission Profiles

Ini template policy, bukan canonical role names.

## Tenant HR Administration Profile

Potentially granted tenant-wide:

```text
broad HR operational permissions
+
approval/finalization permissions
+
authorized sensitive access
```

tergantung tenant governance.

---

## Organizational HR Operator

Role diberikan melalui:

```text
OrganizationalAssignmentRole
```

sehingga permission efektif hanya pada Organization/Unit scope.

Contoh:

```text
hr.employees.view
hr.employees.update
hr.recruitment.manage
hr.leave.view
hr.attendance.view
```

Exact bundle ditentukan policy tenant.

---

## Organizational Approver

Typical:

```text
hr.leave.view
hr.leave.approve
hr.recruitment.view
hr.recruitment.approve
hr.offboarding.view
hr.offboarding.approve
```

tetap dibatasi current verified organizational scope dan business workflow.

---

## Finance Collaboration Profile

Jika Finance membutuhkan HR facts:

```text
hr.compensation.view
hr.payroll.inputs.view
```

atau capability minimum lain yang explicitly required.

Ini tidak memberi HR mutation secara otomatis.

---

## Employee Self-Service Profile

Only:

```text
hr.self.*
```

sesuai policy tenant.

Self permissions tidak boleh dipakai untuk employee-directory access.

---

# 26. Grant Scope Semantics

Satu permission yang sama dapat diberikan pada dua scope berbeda.

Example:

```text
hr.leave.view
```

### Tenant-wide grant

```text
MembershipRole
→ Role
→ hr.leave.view
```

berlaku tenant-wide.

### Organization grant

```text
OrganizationalAssignmentRole
→ Role
→ hr.leave.view
```

berlaku di organization tersebut dan inherited ke units sesuai ADR-018.

### Unit grant

```text
OrganizationalAssignmentRole on Unit
→ Role
→ hr.leave.view
```

berlaku hanya pada exact unit.

Tidak perlu membuat permission:

```text
hr.leave.view.tenant
hr.leave.view.organization
hr.leave.view.unit
```

Scope berasal dari **grant location**, bukan permission name.

---

# 27. Superadmin Semantics

## [FAKTA]

Current tenant permission middleware memberikan global-superadmin bypass.

Tetapi workspace capability implementation secara eksplisit menetapkan:

```text
global superadmin
≠ bypass OrganizationalAuthorizationService
```

### HR-013-BR-008

HR harus mempertahankan semantics ini.

Tenant route:

```text
existing global-superadmin semantics
```

Organizational route:

```text
verified organizational context
+
organizational authorization evaluator
```

tanpa membuat HR-specific superadmin bypass.

---

# 28. Required Core Extension

Existing Core mempunyai organizational authorization evaluator tetapi belum mempunyai route middleware equivalent terhadap:

```text
tenant.permission:<permission>
```

## [REKOMENDASI]

Tambahkan generic Core middleware:

```text
organizational.permission:<permission>
```

Conceptual middleware chain:

```text
InjectTenantContext
→ InjectOrganizationalContext
→ organizational.permission:hr.leave.approve
→ Controller
```

Implementation harus menggunakan:

```text
OrganizationalAuthorizationServiceInterface
```

dan bukan duplicate repository queries.

---

# 29. Organizational Middleware Error Contract

Missing or invalid workspace tetap ditangani oleh:

```text
InjectOrganizationalContext
```

Permission denial:

```text
403
AUTHORIZATION_DENIED
```

mengikuti canonical `ApiErrorResponse`.

Middleware baru tidak membuat HR-specific error envelope.

---

# 30. Resource-Scope Authorization Boundary

`organizational.permission` hanya menjawab:

> Apakah actor mempunyai permission pada current workspace?

Ia belum menjawab:

> Apakah target Employee/Leave/Case berada dalam workspace tersebut?

Karena itu HR membutuhkan domain-level resource-scope authorization.

Conceptual:

```text
Request
 ↓
Verified Workspace
 ↓
Permission Check
 ↓
Load Target Resource
 ↓
HR Resource Scope Check
 ↓
Business Rule
 ↓
Mutation / Read
```

---

# 31. Collection Query Rule

Untuk scoped list:

```text
permission scope
```

harus diterapkan di query.

Forbidden implementation:

```text
SELECT all tenant employees
→ filter unauthorized rows in frontend
```

atau:

```text
load all tenant employees
→ filter in PHP after pagination
```

Scoped query harus membatasi dataset sebelum pagination/result exposure.

---

# 32. Current Employee GET Remediation

Existing:

```text
GET /v1/hr/employees
```

repository saat ini:

```text
getByTenantPaginated()
```

hanya mempunyai tenant filter.

## Immediate safe remediation

Current endpoint tetap tenant-wide dan ditambah:

```text
tenant.permission:hr.employees.view
```

Target chain:

```text
InjectTenantContext
→ tenant.permission:hr.employees.view
→ EmployeeManagementController@index
```

### [RISK]

Jangan mengganti permission middleware di endpoint ini dengan organizational permission tanpa mengubah repository query.

Jika dilakukan:

```text
scoped role
+
tenant-wide repository query
```

maka user organization-level dapat memperoleh seluruh employee tenant.

---

# 33. Future Workspace Employee Listing

Jika HR membutuhkan employee directory per Workspace:

```text
verified Workspace
+
organizational.permission:hr.employees.view
+
scope-filtered repository query
```

harus tersedia.

Exact HTTP endpoint/path:

**[DEFERRED ke API specification]**

HR-013 tidak memaksakan duplicate route structure sebelum API design.

---

# 34. Current Employee POST Remediation

Existing:

```text
POST /v1/hr/employees
```

membuat:

```text
Person
Membership
Employee
```

dalam Tenant.

Ia belum membuat canonical OrganizationalAssignment.

Karena itu current operation adalah **tenant-level provisioning**.

Target middleware:

```text
InjectTenantContext
→ tenant.permission:hr.employees.create
→ EmployeeManagementController@store
```

---

# 35. Workspace Employee Creation

## [DEFERRED]

Scoped organizational user belum boleh menggunakan current Employee POST hanya dengan organizational permission karena operation menghasilkan tenant-wide Employee tanpa guaranteed placement contract.

Sebelum workspace-scoped Employee creation dibuka, harus ditentukan:

```text
identity resolution
+
Employee provisioning
+
Employment creation
+
Organizational placement
+
failure/retry semantics
```

sesuai relevant HR workflow.

---

# 36. StoreEmployeeRequest Authorization

Current:

```php
public function authorize(): bool
{
    return true;
}
```

## [REKOMENDASI]

Tidak perlu memindahkan canonical authorization ke FormRequest jika route middleware sudah menjadi standard architecture.

Classification:

```text
FormRequest authorize()
→ KEEP as non-authority

Route middleware
→ canonical request permission enforcement

Domain/service
→ resource/business invariants
```

Jangan melakukan duplicate permission query di Request + Controller + Service tanpa kebutuhan.

---

# 37. HR Authorization Catalog Seeder

## Required

Tambahkan:

```text
Modules/HR/Database/Seeders/
HRAuthorizationCatalogSeeder
```

Responsibilities:

```text
upsert canonical HR permissions
module = HR
idempotent
do not delete custom roles
do not reconcile arbitrary custom grants
```

Pattern mengikuti existing:

```text
AcademicAuthorizationCatalogSeeder
```

---

# 38. Default Role Mapping

## [OPEN DECISION]

Permission catalog dan default role assignment adalah dua concerns berbeda.

Seeder permission HR tidak boleh diam-diam memberikan sensitive permissions kepada arbitrary custom roles.

Jika product policy mempertahankan tenant `admin` sebagai full tenant administrator, mapping HR permission ke `admin` harus dibuat **explicit dan tested**, bukan akibat role name check di runtime.

Runtime tetap:

```text
RolePermission database state
```

bukan:

```text
if role == admin
```

---

# 39. Capability Projection Impact

Setelah HR permission catalog disimpan:

```text
PermissionCatalogQuery
```

akan otomatis menemukan permission HR.

Tenant capability projection akan menghasilkan tenant-effective HR permissions.

Workspace projection akan menghasilkan:

```text
Tenant grants
∪ organization/unit scoped grants
```

melalui evaluator existing.

Tidak diperlukan HR-specific capability endpoint.

---

# 40. Frontend Authorization Contract

Frontend menerima permission strings sebagai UX projection.

Example:

```text
hr.employees.view
hr.leave.approve
```

Frontend boleh menggunakan ini untuk:

- navigation;
- route affordance;
- button visibility;
- tab visibility.

Tetapi:

```text
frontend capability
≠ backend authority
```

Setiap request tetap mengevaluasi persistence state.

---

# 41. Sensitive Data Rule

Permission terhadap parent resource tidak otomatis memberikan semua fields.

Example:

```text
hr.employees.view
```

dapat memberikan ordinary directory detail.

Sensitive fields membutuhkan:

```text
hr.employees.sensitive.view
```

dan sensitivity policy Phase 3E.

Similarly:

```text
hr.reports.view
```

tidak bypass compensation/document/discipline sensitivity.

---

# 42. Cross-Domain Permission Ownership

| Action                            | Permission Owner                               |
| --------------------------------- | ---------------------------------------------- |
| Employee HR profile               | HR                                             |
| Employment                        | HR                                             |
| Leave                             | HR                                             |
| Attendance                        | Attendance/HR boundary per locked architecture |
| Compensation facts                | HR                                             |
| Payroll calculation/payment       | Finance                                        |
| OrganizationalAssignment mutation | Core Organization                              |
| Role grant/revocation             | Core Authorization                             |
| Academic teaching data            | Academic                                       |
| Government HR export              | HR                                             |

HR page boleh menampilkan integrated UX tetapi tidak membuat duplicate permission namespace untuk foreign-owned mutation.

---

# 43. Deny Semantics

Authorization failure:

```text
401
→ authentication missing/invalid

403 AUTHORIZATION_DENIED
→ permission denied

403 ORGANIZATIONAL_CONTEXT_REQUIRED
→ required workspace absent

403 ORGANIZATIONAL_CONTEXT_DENIED
→ workspace invalid/unavailable

404
→ resource not exposed / unavailable according to API policy

409
→ business lifecycle conflict
```

Permission denial tidak boleh diubah menjadi:

```text
422 validation error
```

---

# 44. Enumeration / Existence Leakage

## HR-013-NFR-001

Scoped endpoints harus menghindari leaking existence dari out-of-scope HR resource.

Contoh actor Unit A meminta Employee Unit B.

Response contract harus konsisten dengan security policy.

**[OPEN DECISION]**

Apakah resource-scope failure menggunakan:

```text
403
```

atau security-hardened:

```text
404
```

akan ditentukan pada detailed API specification.

Internal audit tetap dapat mencatat true denial reason.

---

# 45. Audit Requirement

High-impact authorization-sensitive operations minimum memiliki transactional/domain evidence sesuai existing HR architecture.

Examples:

```text
Employment end
Hiring approval
Leave final approval
Attendance finalization
Performance finalization
Document finalization
Disciplinary action
Offboarding completion
Sensitive export generation/download
```

Core generic Audit tetap supplemental bila fail-open semantics masih berlaku.

---

# 46. Testing Matrix

Setiap protected HR operation minimum harus mempunyai tests untuk:

### Authentication

```text
no token → 401
```

### Tenant isolation

```text
other tenant → deny
```

### Permission

```text
no permission → 403
correct permission → proceeds
```

### Organizational scope

```text
same org → allowed when permission exists
same unit → allowed when permission exists
sibling unit → deny
other organization → deny
stale assignment → deny
inactive assignment → deny
```

### Resource scope

```text
permission exists
+
target outside scope
→ deny
```

### Business state

```text
permission exists
+
invalid lifecycle transition
→ domain conflict, not authorization success
```

### Self-service

```text
own Employee → allowed
other Employee → deny
```

---

# 47. Existing Route Remediation Priority

## P0 — Before broader HR production exposure

1. Create HR permission catalog.
2. Add `hr.employees.view`.
3. Add `hr.employees.create`.
4. Protect current GET Employee route.
5. Protect current POST Employee route.
6. Add authorization denial regression tests.
7. Ensure canonical `ApiErrorResponse`.
8. Add OpenAPI security/error contract.
9. Ensure existing tests grant required permissions instead of relying only on authenticated Tenant.

---

## P1 — Before organizational HR rollout

1. Add generic organizational permission middleware.
2. Add scope-filtered employee queries.
3. Add resource-scope authorization service/policy.
4. Test organization/unit inheritance.
5. Test sibling/cross-organization denial.
6. Integrate workspace capability projection.

---

## P2 — Before later HR capability rollout

For each HR-003–HR-009 API:

```text
permission
→ request context
→ resource scope
→ business state
→ sensitive fields
→ audit
→ tests
```

must be specified before production exposure.

---

# 48. Change Impact — Existing Employee Tests

Current feature tests authenticate tenant context but do not establish HR permission.

After remediation:

```text
tests
→ seed HR permissions
→ assign Role
→ grant Role to Membership
→ call endpoint
```

Additional denial tests become mandatory.

This is expected test migration, not regression in business behavior.

---

# 49. Change Impact Classification

| Component                                 | Decision                                      |
| ----------------------------------------- | --------------------------------------------- |
| `Modules/HR/Routes/api.php`               | **REFACTOR**                                  |
| `EmployeeManagementController`            | **KEEP + error hardening**                    |
| `StoreEmployeeRequest`                    | **KEEP**, legacy fields separately refactored |
| `EmployeeRepository` tenant query         | **KEEP for tenant route**                     |
| Employee scoped query                     | **ADD when workspace route exists**           |
| Core tenant permission middleware         | **KEEP**                                      |
| Core organizational evaluator             | **KEEP**                                      |
| Core organizational permission middleware | **ADD**                                       |
| Permission catalog                        | **EXTEND**                                    |
| Capability endpoints                      | **KEEP**                                      |
| OpenAPI HR routes                         | **EXTEND / HARDEN**                           |
| Employee authorization tests              | **REFACTOR + EXTEND**                         |

---

# 50. Security Invariants

```text
Tenant isolation first.

Permission never replaces scope.

Scope never replaces permission.

Frontend visibility never replaces backend authorization.

Position/Jabatan never grants access.

Self permission never grants access to another employee.

Scoped permission never authorizes tenant-wide query.

Report permission never bypasses source-data sensitivity.

HR permission never mutates foreign-owned domain state.
```

---

# 51. IN SCOPE

- canonical HR permission identifiers;
- self-service permission separation;
- tenant vs organizational authorization;
- resource-scope enforcement;
- current Employee route remediation;
- organizational permission middleware requirement;
- permission catalog seeding;
- capability projection impact;
- authorization testing requirements;
- cross-domain permission ownership.

---

# 52. OUT OF SCOPE

- implementation code;
- actual role administration UI;
- final tenant role bundles;
- detailed Employment/Leave/etc API contracts;
- sensitivity masking implementation;
- detailed historical-case persistence;
- Finance permissions;
- Core OrganizationalAssignment permission design;
- Membership deactivation policy.

---

# 53. DEFERRED / OPEN ITEMS

1. Historical HR record organizational scope semantics.
2. Exact Core permission for OrganizationalAssignment mutation.
3. Exact Core permissions for access-review role revocation.
4. Default HR permission grants to existing `admin`.
5. 403 vs 404 policy for out-of-scope resource probing.
6. Exact role templates/configuration UI.
7. Workspace-scoped Employee creation workflow.

Tidak ada item di atas yang membatalkan immediate P0 remediation current HR Employee routes.

---

# 54. Acceptance Criteria

### HR-013-AC-001 — Employee View

**Given** authenticated Membership mempunyai `hr.employees.view` tenant-wide
**When** `GET /v1/hr/employees` dipanggil
**Then** request dapat melanjutkan ke domain handler.

---

### HR-013-AC-002 — Missing Permission

**Given** Membership aktif tetapi tidak mempunyai `hr.employees.view`
**When** Employee list diminta
**Then** backend mengembalikan canonical `403 AUTHORIZATION_DENIED`.

---

### HR-013-AC-003 — Jabatan Not Authority

**Given** Employee actor mempunyai `jabatan = KEPALA_SEKOLAH`
**And** Membership tidak mempunyai required permission
**When** protected HR operation diminta
**Then** access ditolak.

---

### HR-013-AC-004 — Scoped Role

**Given** Membership mempunyai `hr.leave.approve` hanya pada Organization A
**When** user bekerja dalam verified Organization A workspace
**Then** permission dapat efektif.

**When** user bekerja pada Organization B
**Then** permission tersebut tidak efektif.

---

### HR-013-AC-005 — Unit Isolation

**Given** permission hanya diberikan pada Unit A
**When** target resource berada pada sibling Unit B
**Then** operation ditolak.

---

### HR-013-AC-006 — Tenant Role Inheritance

**Given** `hr.employees.view` diberikan tenant-wide
**When** user berada pada valid Organization/Unit workspace
**Then** permission tetap efektif melalui organizational evaluator.

---

### HR-013-AC-007 — Resource Scope

**Given** actor mempunyai scoped permission
**And** target Employee berada di luar authorized scope
**When** detail/mutation diminta
**Then** permission saja tidak cukup
**And** request ditolak.

---

### HR-013-AC-008 — Self Service

**Given** user mempunyai `hr.self.leave.view`
**When** user melihat leave miliknya sendiri
**Then** operation dapat diizinkan.

**When** user meminta leave Employee lain
**Then** operation ditolak.

---

### HR-013-AC-009 — Sensitive Employee Data

**Given** user mempunyai `hr.employees.view`
**But** tidak mempunyai `hr.employees.sensitive.view`
**When** Employee detail diminta
**Then** ordinary authorized fields dapat ditampilkan
**And** sensitive fields tidak otomatis diberikan.

---

### HR-013-AC-010 — Export Separation

**Given** user mempunyai `hr.reports.view`
**But** tidak mempunyai `hr.reports.export`
**When** export diminta
**Then** backend menolak export tanpa menolak ordinary report view.

---

### HR-013-AC-011 — Foreign Domain Boundary

**Given** user mempunyai broad HR permissions
**When** user mencoba Finance payroll payment atau Core role revocation
**Then** HR permission tidak memberikan authorization operation tersebut.

---

### HR-013-AC-012 — Global Superadmin Workspace

**Given** user adalah global superadmin
**When** operation membutuhkan verified organizational authorization
**Then** HR tidak membuat bypass baru
**And** canonical organizational evaluator tetap berlaku.

---

# 55. Traceability

```text
HR Business Objective
        ↓
ADR-016 Tenant RBAC
        ↓
ADR-018 Scoped Authorization
        ↓
HR-002 ... HR-009 Domain Capability
        ↓
HR-010 Navigation
        ↓
HR-011 Transaction UX
        ↓
HR-012 Permission/Error UX
        ↓
HR-013 Permission + Scope Matrix
        ↓
API Middleware / Domain Scope
        ↓
Contract Tests
        ↓
Frontend Capability UX
```

---

# 56. Phase Review

**Quality Score:** **9.6/10**

## Gaps

- historical record organizational ownership belum locked;
- Core organizational management permission belum tersedia;
- default role-to-HR permission mapping belum ditetapkan;
- several future HR APIs belum diimplementasikan sehingga resource-level policy hanya dapat didefinisikan secara contract.

## Risks

**[RISK — CRITICAL FOR IMPLEMENTATION]**

Menambahkan organizational permission pada current Employee GET tanpa scope-filtered repository akan menghasilkan authorization leak karena repository saat ini tetap membaca seluruh Tenant.

**[RISK — HIGH]**

Menggunakan `Employee.jabatan` sebagai shortcut role akan bertentangan langsung dengan ADR-016 dan locked HR architecture.

**[RISK — HIGH]**

Self-service permission yang digabung dengan ordinary Employee permissions dapat menyebabkan horizontal privilege escalation.

## Recommendations

1. Lock HR-013 permission catalog dan scope model.
2. Treat current HR Employee GET/POST remediation sebagai P0.
3. Reuse Core evaluators; jangan membuat HR RBAC engine.
4. Tambahkan generic `organizational.permission` middleware pada Core.
5. Jangan membuka scoped Employee list sampai query benar-benar scope-aware.
6. Jangan membuka workspace Employee creation sampai provisioning + placement workflow dikunci.
7. Lanjut ke **Phase 3E — HR Security, Privacy & Retention Controls** setelah approval.

**Status:** **READY WITH MINOR OPEN ITEMS**
