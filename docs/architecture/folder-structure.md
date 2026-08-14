# EduCore Current Repository Structure

- **Version**: 3.1
- **Status**: Current Architecture Baseline
- **Updated**: 2026-08-14
- **Baseline**: Core Canonical Foundation 2G + Phase 3A + Phase 4A Module Kernel Runtime Hardening + Phase 4B Organizational Topology Foundation

## Purpose

Dokumen ini menjelaskan struktur repository EduCore **yang berlaku saat ini** setelah Core 2G, Phase 3A, Phase 4A, dan Phase 4B Organizational Topology Foundation selesai.

Dokumen ini menggantikan `folder-structure.md` versi lama yang masih menggambarkan Sprint `CORE-001`, `MockStudent`, dan struktur Core sebelum canonical identity/tenancy/RBAC selesai.

Dokumen ini harus dibaca bersama:

1. `docs/architecture/README.md`
2. `docs/architecture/current-architecture.md`
3. `docs/architecture/adr/README.md`
4. ADR-013 sampai ADR-019

> Struktur di bawah mencatat direktori/file yang memiliki arti runtime atau architectural ownership. Direktori kosong yang mungkin masih ada pada working copy lokal tidak dianggap sebagai contract dan sengaja tidak dimasukkan.
> Nama migration pada tree disingkat tanpa timestamp prefix ketika hal itu membuat struktur lebih mudah dibaca; ownership dan nama tabel/file capability tetap mengikuti repository.

---

# 1. Repository-Level Structure

```text
educore/
├── app/
│   ├── Http/
│   └── Providers/
│
├── bootstrap/
│   ├── app.php
│   └── providers.php
│
├── config/
│
├── database/
│   ├── factories/
│   ├── migrations/
│   └── seeders/
│
├── docs/
│   ├── architecture/
│   ├── prd/
│   └── sprint/
│
├── Modules/
│   ├── Core/
│   ├── Auth/
│   ├── User/
│   ├── Academic/
│   ├── HR/
│   └── PPDB/
│
├── routes/
├── tests/
├── composer.json
├── phpunit.xml
└── artisan
```

## Repository Ownership Rule

EduCore adalah **modular monolith**.

Business/platform capability yang sudah menjadi module harus dimiliki oleh module tersebut, bukan dipindahkan ke `app/` hanya karena Laravel menyediakan folder tersebut secara default.

Contoh:

```text
Authentication       → Modules/Auth
Tenant + Membership  → Modules/Core
Organization topology → Modules/Core/Organization
Human Identity       → Modules/Core/Person
User Account          → Modules/Core/Identity
Student / Guardian   → Modules/Academic
Employee             → Modules/HR
```

`app/` tetap dipertahankan untuk Laravel application shell dan komponen global yang benar-benar bukan milik satu module.

---

# 2. Module Dependency Direction

Current dependency graph:

```text
Core      → []
Auth      → Core
User      → Core, Auth
HR        → Core, Auth
Academic  → Core, HR, Auth
PPDB      → Core
```

Arrows berarti **depends on**.

Dependency harus tercermin pada `module.yaml`, tetap acyclic, dan bootstrap harus fail-fast pada missing dependency atau cycle.

Core tidak boleh bergantung pada:

```text
Auth
User
Academic
HR
PPDB
Dormitory
Finance
Attendance
```

untuk mempertahankan compatibility dengan downstream module.

---

# 3. `Modules/Core` — Platform Foundation

`Modules/Core` bukan lagi hanya module discovery kernel.

Current Core terdiri dari beberapa foundation concern:

```text
Modules/Core/
├── Authorization/
├── Domain/
├── Exceptions/
├── Governance/
├── Http/
├── Identity/
├── Jobs/
├── Listeners/
├── Manifest/
├── Notification/
├── Organization/
├── Person/
├── Platform/
├── Providers/
├── Repositories/
├── Routes/
├── Services/
├── Shared/
├── Support/
├── Tenancy/
├── Tests/
└── module.yaml
```

Core memiliki dua jenis responsibility besar:

```text
Platform Kernel
+
Application Foundation
```

Platform Kernel menangani module system dan platform services.

Application Foundation menangani identity, tenancy, membership, organizational topology/context, RBAC, audit, notification infrastructure, dan cross-module primitives.

---

# 4. `Modules/Core/Person` — Canonical Human Identity

```text
Modules/Core/Person/
├── Contracts/
│   ├── PersonLifecycleEventRepositoryInterface.php
│   ├── PersonLifecycleServiceInterface.php
│   └── PersonRepositoryInterface.php
│
├── DTO/
│   └── PersonData.php
│
├── Database/
│   ├── Factories/
│   │   └── PersonFactory.php
│   └── Migrations/
│       ├── create_persons_table.php
│       ├── create_person_contacts_table.php
│       ├── create_person_addresses_table.php
│       ├── create_person_identifiers_table.php
│       ├── create_person_citizenships_table.php
│       └── create_person_lifecycle_events_table.php
│
├── Entities/
├── Enums/
├── Exceptions/
├── Models/
├── Repositories/
├── Services/
└── ValueObjects/
```

Ownership:

```text
Person
= canonical global human identity
```

Human data seperti nama, biodata, contact, address, identifier, citizenship, dan lifecycle tidak boleh kembali dimiliki oleh `User`, `Student`, `Guardian`, atau `Employee`.

Canonical relation:

```text
Person
├── User
└── Membership
```

See ADR-013.

---

# 5. `Modules/Core/Identity` — Digital User Account

```text
Modules/Core/Identity/
├── Contracts/
│   └── ActiveUserResolverInterface.php
│
├── Database/
│   └── Migrations/
│       └── create_users_table.php
│
├── Infrastructure/
│   └── EloquentActiveUserResolver.php
│
└── Models/
    └── User.php
```

Ownership:

```text
User
= global digital/authentication account
```

Canonical relation:

```text
User
→ Person
```

Tidak ada tenant ownership di `User`.

Forbidden architecture:

```text
users.tenant_id
users.name
User → Tenant
```

Tenant participation diselesaikan melalui Membership.

---

# 6. `Modules/Core/Tenancy` — Tenant & Runtime Tenant Context

```text
Modules/Core/Tenancy/
├── Console/
│   └── TenantProvisionCommand.php
│
├── Contracts/
│   ├── TenantContextInterface.php
│   ├── TenantRepositoryInterface.php
│   └── TenantRuntimeResolverInterface.php
│
├── Database/
│   └── Migrations/
│       ├── create_tenants_table.php
│       └── create_memberships_table.php
│
├── Exceptions/
├── Http/
│   ├── Api/v1/
│   │   └── TenantManagementController.php
│   └── Requests/
│
├── Infrastructure/
│   ├── EloquentTenantRuntimeResolver.php
│   └── Scopes/
│       └── TenantScope.php
│
├── Models/
│   └── Tenant.php
│
├── Services/
│   ├── TenantContext.php
│   ├── TenantManager.php
│   └── TenantProvisioningService.php
│
└── Traits/
    └── BelongsToTenant.php
```

Core tenancy owns:

```text
Tenant
TenantContext
Tenant runtime resolution
Tenant provisioning
BelongsToTenant
```

Membership persistence table secara schema dimiliki oleh tenancy foundation, sedangkan canonical Membership model/repositories berada pada Authorization boundary karena Membership juga menjadi RBAC subject.

Canonical boundary:

```text
Person
  ↓
Membership
  ↓
Tenant
```

See ADR-014.

---

# 7. `Modules/Core/Authorization` — Membership & Tenant RBAC

```text
Modules/Core/Authorization/
├── Context/
│   └── AuthorizationContext.php
│
├── Contracts/
│   ├── AuthorizationContextInterface.php
│   ├── AuthorizationContextResolverInterface.php
│   ├── AuthorizationServiceInterface.php
│   └── MembershipContextResolverInterface.php
│
├── DTO/
│   └── MembershipContext.php
│
├── Database/
│   ├── Migrations/
│   │   ├── create_roles_table.php
│   │   ├── create_permissions_table.php
│   │   ├── create_role_permissions_table.php
│   │   └── create_membership_roles_table.php
│   └── Seeders/
│       └── AuthorizationCatalogSeeder.php
│
├── Exceptions/
│   └── MembershipContextResolutionException.php
│
├── Http/
│   ├── Api/v1/
│   │   └── RoleCatalogController.php
│   └── Middleware/
│       ├── CheckTenantRole.php
│       ├── CheckTenantPermission.php
│       └── RequireGlobalSuperadmin.php
│
├── Models/
│   ├── Membership.php
│   ├── MembershipRole.php
│   ├── Permission.php
│   ├── Role.php
│   └── RolePermission.php
│
├── Queries/
│   └── RoleCatalogQuery.php
│
├── Repositories/
│   ├── Contracts/
│   │   ├── MembershipRepositoryInterface.php
│   │   ├── MembershipRoleRepositoryInterface.php
│   │   └── RolePermissionRepositoryInterface.php
│   ├── EloquentMembershipRepository.php
│   ├── EloquentMembershipRoleRepository.php
│   └── EloquentRolePermissionRepository.php
│
└── Services/
    ├── AuthorizationContextResolver.php
    ├── AuthorizationService.php
    └── MembershipContextResolver.php
```

Canonical authorization chain:

```text
Membership
 ↓
MembershipRole
 ↓
Role
 ↓
RolePermission
 ↓
Permission
```

Canonical runtime service:

```text
AuthorizationService
 ↓
AuthorizationContextResolver
 ↓
MembershipContextResolver
 ↓
MembershipRoleRepository
 ↓
RolePermissionRepository
```

Canonical middleware API:

```text
tenant.role:<role>
tenant.permission:<permission>
```

Database adalah RBAC source of truth.

See ADR-016.

---

# 7A. `Modules/Core/Organization` — Organizational Topology & Scoped Authorization

```text
Modules/Core/Organization/
├── Context/
│   └── OrganizationalContext.php
├── Contracts/
│   ├── OrganizationalAssignmentRepositoryInterface.php
│   ├── OrganizationalAssignmentServiceInterface.php
│   ├── OrganizationalAssignmentRoleRepositoryInterface.php
│   ├── OrganizationalAuthorizationServiceInterface.php
│   ├── OrganizationalContextInterface.php
│   ├── OrganizationalContextResolverInterface.php
│   ├── OrganizationalRoleGrantServiceInterface.php
│   └── OrganizationalScopedRoleRepositoryInterface.php
├── Database/Migrations/
│   ├── create_organizations_table.php
│   ├── create_organization_units_table.php
│   ├── create_organizational_assignments_table.php
│   └── create_organizational_assignment_roles_table.php
├── Exceptions/
├── Models/
│   ├── Organization.php
│   ├── OrganizationUnit.php
│   ├── OrganizationalAssignment.php
│   └── OrganizationalAssignmentRole.php
├── Repositories/
│   ├── EloquentOrganizationalAssignmentRepository.php
│   ├── EloquentOrganizationalAssignmentRoleRepository.php
│   └── EloquentOrganizationalScopedRoleRepository.php
└── Services/
    ├── OrganizationalAssignmentService.php
    ├── OrganizationalAuthorizationService.php
    ├── OrganizationalContextResolver.php
    ├── OrganizationalContextState.php
    └── OrganizationalRoleGrantService.php
```

Canonical topology:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

Membership remains `Person × Tenant`; organizational participation is a separate `OrganizationalAssignment`.

Scoped authorization preserves tenant-wide `membership_roles` and adds Organization/Unit grants through `organizational_assignment_roles`.

See ADR-018.

Dormitory is not implemented in Core. Future `Modules/Dormitory` depends on this foundation; Core must not depend on Dormitory. See ADR-019.

---

# 8. `Modules/Core/Governance` — Audit Foundation

Current implemented ownership:

```text
Modules/Core/Governance/
└── Audit/
    ├── Contracts/
    │   └── AuditTrailServiceInterface.php
    ├── Database/Migrations/
    │   └── create_audit_logs_table.php
    └── Persistence/
        └── DatabaseAuditTrailService.php
```

Audit persistence menggunakan canonical structural metadata dan actor user identity.

`Governance/Settings` yang mungkin terdapat sebagai direktori kosong pada working copy tidak dianggap implemented capability sampai memiliki contract/runtime code.

---

# 9. `Modules/Core/Notification` & Platform Notification Contracts

Notification dibagi antara platform contract dan concrete infrastructure.

```text
Modules/Core/Platform/Notification/
├── Contracts/
│   ├── NotificationAttemptStoreInterface.php
│   ├── NotificationChannelInterface.php
│   └── WhatsAppGatewayInterface.php
└── DTO/
    ├── PreparedNotificationAttempt.php
    └── WhatsAppGatewayResult.php
```

Concrete implementation:

```text
Modules/Core/Notification/
├── Channels/
│   └── WhatsAppNotificationChannel.php
├── Database/Migrations/
│   └── create_notification_attempts_table.php
├── Gateways/
│   └── UnavailableWhatsAppGateway.php
└── Persistence/
    └── DatabaseNotificationAttemptStore.php
```

Asynchronous execution:

```text
Modules/Core/Jobs/
├── BaseTenantAwareJob.php
├── SendAsynchronousNotificationJob.php
└── Middleware/
    └── RestoreTenantContext.php
```

Notification infrastructure tidak boleh menjadi owner Person/User PII.

---

# 10. `Modules/Core/Platform` — Module Kernel

Module-system architecture tetap berada di Core Platform.

```text
Modules/Core/Platform/
├── Console/
│   ├── KernelHealthCheckCommand.php
│   ├── ModuleListCommand.php
│   └── ModuleStatusCommand.php
│
├── Dependency/
│   └── DependencyResolver.php
│
├── Discovery/
│   └── ModuleDiscovery.php
│
├── Health/
│   └── Contracts/
│
├── Http/
│   ├── Controllers/Api/v1/
│   │   └── NotificationController.php
│   └── Requests/
│       └── SendNotificationRequest.php
│
├── Module/
│   ├── Domain/
│   │   └── ModuleDefinition.php
│   └── Services/
│       └── ModuleLoader.php
│
└── Registry/
    └── ModuleRegistry.php
```

Supporting module-kernel classes juga berada pada:

```text
Modules/Core/Manifest/
Modules/Core/Services/
Modules/Core/Exceptions/
```

Current supporting services meliputi:

```text
ModuleManifestLoader
ModuleManifestParser
ModuleManifestValidator
ModuleDefinitionFactory
ModuleBootstrapService
ModuleProviderRegistrar
ModuleRepository
```

Tidak ada current:

```text
ModuleStateRepository
ModuleManager
ModuleEnableCommand
ModuleDisableCommand
EventDiscoveryService
ModuleEventRegistry
```

Provider activation berasal dari declared `module.yaml.providers`, mengikuti dependency order, dan gagal secara fail-fast bila provider/dependency configuration invalid.

---

# 11. `Modules/Core/Shared` & `Support`

```text
Modules/Core/Shared/
└── Repositories/
    ├── Contracts/
    │   └── RepositoryInterface.php
    └── BaseRepository.php
```

```text
Modules/Core/Support/
└── Uuid/
    ├── HasUuidV7.php
    ├── UuidBlueprintMacro.php
    └── UuidV7.php
```

UUIDv7 adalah canonical identifier strategy untuk canonical entities baru/refactored yang sudah berada dalam scope foundation.

Shared abstraction harus tetap kecil. Jangan memindahkan business behavior ke `Shared` hanya untuk reuse convenience.

---

# 12. `Modules/Core/Providers`

```text
Modules/Core/Providers/
├── CoreServiceProvider.php
└── TenantServiceProvider.php
```

Provider bertanggung jawab pada IoC binding dan module/platform bootstrap.

`TenantContext` menggunakan **scoped lifecycle**, bukan process-wide mutable singleton.

Authorization resolver/service yang membawa request/tenant context juga menggunakan scoped lifecycle.

Organizational context, scoped authorization, dan organizational role-grant services mengikuti scoped/request-safe lifecycle sesuai responsibility masing-masing.

Provider tidak boleh menjadi tempat business orchestration.

---

# 13. `Modules/Auth` — Authentication Runtime

```text
Modules/Auth/
├── Application/
│   ├── DTO/
│   │   └── ResolvedAuthenticatedIdentity.php
│   └── Services/
│       └── AuthenticatedIdentityResolver.php
│
├── Authentication/
│   └── Contracts/
│       └── AuthenticationRepositoryInterface.php
│
├── Database/
│   └── Migrations/
│       ├── create_auth_token_revocations_table.php
│       ├── create_password_reset_tokens_table.php
│       └── create_sessions_table.php
│
├── Http/
│   ├── Controllers/Api/v1/
│   │   └── AuthController.php
│   ├── Middleware/
│   │   ├── InjectAuthenticatedUser.php
│   │   └── InjectTenantContext.php
│   └── Requests/
│       └── LoginTokenRequest.php
│
├── Providers/
│   └── AuthServiceProvider.php
│
├── Repositories/
│   └── AuthenticationRepository.php
│
├── Routes/
│   └── api.php
│
├── Services/
│   └── DeterministicTokenManager.php
│
├── Token/
│   ├── Contracts/
│   │   ├── TokenManagerInterface.php
│   │   └── TokenRevocationStoreInterface.php
│   └── Persistence/
│       └── DatabaseTokenRevocationStore.php
│
├── Tests/
└── module.yaml
```

Ownership:

```text
credential authentication
bearer token issuance/validation
revocation
authenticated User injection
verified tenant/membership context injection
secured Core route composition
```

`RequireGlobalSuperadmin` adalah Core Authorization-owned middleware. Auth hanya mengomposisikan secured Core entry points karena dependency direction adalah Auth → Core, bukan Core → Auth.

Canonical token claims:

```text
user_id
tenant_id
membership_id
expires_at
```

Auth does **not** own Person, Membership, Role, Permission, Student, Guardian, atau Employee.

See ADR-015.

---

# 14. `Modules/User` — User-Facing Membership Operations

```text
Modules/User/
├── Application/
│   ├── Actions/
│   │   ├── AssignRoleToMembership.php
│   │   ├── ListMyMemberships.php
│   │   └── SwitchMembership.php
│   ├── DTO/
│   └── Queries/
│       └── UserMembershipQueryInterface.php
│
├── Http/
│   ├── Controllers/Api/v1/
│   └── Requests/
│
├── Infrastructure/
│   └── Queries/
│       └── EloquentUserMembershipQuery.php
│
├── Providers/
│   └── UserServiceProvider.php
│
├── Routes/
│   └── api.php
│
├── Tests/
└── module.yaml
```

Ownership penting:

`Modules/User` **bukan owner canonical `User` model**.

Canonical account model tetap:

```text
Modules/Core/Identity/Models/User.php
```

`Modules/User` adalah application-facing module untuk operasi seperti:

```text
list membership
switch membership
assign role to membership
```

Ia mengonsumsi Core/Auth contracts dan tidak menduplikasi identity persistence.

Current manifest dependency:

```text
User → Core, Auth
```

---

# 15. `Modules/Academic` — Academic Domain

```text
Modules/Academic/
├── Contracts/
│   ├── StudentRepositoryInterface.php
│   ├── GuardianRepositoryInterface.php
│   ├── GuardianStudentRepositoryInterface.php
│   └── Repository/
│       ├── AcademicClassRepositoryInterface.php
│       ├── AcademicPeriodRepositoryInterface.php
│       └── AcademicSubjectRepositoryInterface.php
│
├── Database/
│   ├── Migrations/
│   │   ├── create_academic_courses_table.php
│   │   ├── create_guardians_table.php
│   │   ├── create_academic_classes_table.php
│   │   ├── create_academic_subjects_table.php
│   │   ├── create_students_table.php
│   │   ├── create_guardian_student_table.php
│   │   ├── create_academic_assessments_tables.php
│   │   ├── create_academic_report_cards_table.php
│   │   ├── create_academic_years_table.php
│   │   ├── create_academic_report_details_table.php
│   │   └── create_academic_semesters_table.php
│   └── Seeders/
│       └── AcademicAuthorizationCatalogSeeder.php
│
├── Events/
│   └── CoursePublished.php
│
├── Http/
│   ├── Controllers/Api/v1/
│   │   ├── AcademicClassController.php
│   │   ├── AcademicPeriodController.php
│   │   ├── AcademicSubjectController.php
│   │   ├── BulkGradingController.php
│   │   ├── GuardianManagementController.php
│   │   ├── GuardianStudentManagementController.php
│   │   └── StudentManagementController.php
│   └── Requests/
│
├── Models/
│   ├── AcademicReportCard.php
│   ├── AcademicReportDetail.php
│   ├── AssessmentSetting.php
│   ├── Guardian.php
│   ├── Student.php
│   └── StudentGrade.php
│
├── Providers/
│   └── AcademicServiceProvider.php
│
├── Repositories/
│   ├── EloquentAcademicClassRepository.php
│   ├── EloquentAcademicPeriodRepository.php
│   ├── EloquentAcademicSubjectRepository.php
│   ├── EloquentGuardianRepository.php
│   ├── EloquentGuardianStudentRepository.php
│   └── EloquentStudentRepository.php
│
├── Routes/
│   ├── api.php
│   └── web.php
│
├── Services/
│   ├── BulkGradingService.php
│   ├── GuardianProvisioningService.php
│   ├── ReportCardAggregationService.php
│   └── StudentProvisioningService.php
│
├── Tests/
└── module.yaml
```

Canonical profile ownership:

```text
Person
 ↓
Membership
 ├── Student
 └── Guardian
```

Academic tidak memiliki:

```text
Student.name
Guardian.name
Student User account
Guardian User account
Teacher entity
```

Teacher adalah authorization capability.

Grading domain actor adalah `Employee` dari HR:

```text
Membership
 ├── academic.grades.write
 └── Employee
       ↓
student_grades.teacher_id
```

Karena itu Academic mendeklarasikan dependency ke HR dan Auth.

Current manifest dependency:

```text
Academic → Core, HR, Auth
```

`MockStudent` tidak lagi menjadi bagian repository canonical dan **tidak boleh dikembalikan**.

---

# 16. `Modules/HR` — Employee Profile

```text
Modules/HR/
├── Contracts/
│   └── EmployeeRepositoryInterface.php
│
├── Database/
│   └── Migrations/
│       └── create_employees_table.php
│
├── Http/
│   ├── Controllers/Api/v1/
│   │   └── EmployeeManagementController.php
│   └── Requests/
│       └── StoreEmployeeRequest.php
│
├── Models/
│   └── Employee.php
│
├── Providers/
│   └── HRServiceProvider.php
│
├── Repositories/
│   └── EloquentEmployeeRepository.php
│
├── Routes/
│   └── api.php
│
├── Services/
│   └── EmployeeProvisioningService.php
│
├── Tests/
└── module.yaml
```

Canonical ownership:

```text
Person
 ↓
Membership
 ↓
Employee
```

HR owns:

```text
nip
jabatan
employee lifecycle/profile persistence
```

HR does not own Person identity or authentication account.

`jabatan` bukan authorization source.

Canonical repository binding:

```text
EmployeeRepositoryInterface
→ Modules\HR\Repositories\EloquentEmployeeRepository
```

Current manifest dependency:

```text
HR → Core, Auth
```

---

# 17. `Modules/PPDB` — Current Scaffold Only

Current tracked structure masih minimal:

```text
Modules/PPDB/
├── Providers/
│   └── PPDBServiceProvider.php
└── module.yaml
```

Status:

```text
MODULE SCAFFOLD
```

Keberadaan folder/module manifest tidak berarti PPDB domain contract sudah designed atau locked.

Jangan menyalin pola identity legacy ke PPDB ketika domain ini mulai diimplementasikan.

PPDB nantinya harus mengonsumsi canonical Person/Membership foundation sesuai requirement yang disetujui.

Current manifest dependency:

```text
PPDB → Core
```

---

# 18. Database Migration Ownership

Canonical policy:

```text
Core canonical schema
→ migration berada pada Core/Auth owner masing-masing

Business schema
→ migration berada pada business module owner
```

Examples:

```text
persons             → Core/Person
users               → Core/Identity
tenants              → Core/Tenancy
memberships          → Core/Tenancy
roles/permissions    → Core/Authorization
audit_logs           → Core/Governance/Audit
notification_attempts→ Core/Notification
auth token/session   → Auth
students/guardians   → Academic
employees            → HR
```

Root `database/migrations` hanya mempertahankan Laravel/application-level migrations yang memang bukan module-owned.

Development/refactor schema policy:

```text
known final schema
→ edit canonical baseline migration
→ migrate:fresh --seed
```

Jangan membuat ALTER-migration chain hanya untuk mempertahankan architecture yang belum production-frozen.

---

# 19. Route Ownership

Setiap module yang memiliki API bertanggung jawab atas route file-nya sendiri.

```text
Core     → Modules/Core/Routes/api.php
Auth     → Modules/Auth/Routes/api.php
User     → Modules/User/Routes/api.php
Academic → Modules/Academic/Routes/api.php
HR       → Modules/HR/Routes/api.php
```

Core route file tidak boleh mengimpor Auth middleware. Public Core routes tetap Core-owned, sedangkan secured Core entry points dikomposisikan dari Auth route composition agar dependency direction tetap Auth → Core.

Root `routes/` tetap untuk Laravel application-shell routes seperti `web.php`/`console.php` yang tidak dimiliki business module tertentu.

Critical tenant API harus melewati canonical authentication/tenant context middleware sesuai contract masing-masing endpoint.

---

# 20. Service Provider Ownership

Provider hanya menangani:

```text
IoC bindings
route bootstrap
migration bootstrap
module/platform registration
explicit event/integration registration
```

Non-Core provider activation berasal dari validated `module.yaml.providers`, bukan provider guessing atau static business-provider registration pada `bootstrap/providers.php`.

Provider tidak boleh menangani application business orchestration.

Examples:

```text
CoreServiceProvider
TenantServiceProvider
AuthServiceProvider
UserServiceProvider
AcademicServiceProvider
HRServiceProvider
```

Business orchestration berada di application/service/action layer module yang memiliki capability tersebut.

---

# 21. Repository & Service Boundary

Repository responsibility:

```text
persistence
query
aggregate/profile data access
```

Service/action responsibility:

```text
meaningful orchestration
transaction boundary
cross-repository workflow
```

Examples:

```text
StudentProvisioningService
→ Person + Membership + Student transaction

GuardianProvisioningService
→ Person + Membership + optional Contact + Guardian transaction

EmployeeProvisioningService
→ Person + Membership + Employee transaction

BulkGradingService
→ resolve Employee actor + validate targets + grade upsert
```

Jangan membuat service layer hanya karena setiap repository "harus punya service".

KISS tetap berlaku.

---

# 22. Test Structure

Module tests berada bersama module owner:

```text
Modules/Core/Tests
Modules/Auth/Tests
Modules/User/Tests
Modules/Academic/Tests
Modules/HR/Tests
```

Root `tests/` digunakan untuk application-level integration yang benar-benar melintasi ownership module atau Laravel shell.

Critical integration test harus menggunakan production contract:

```text
real route
real Bearer token
real auth middleware
real tenant context
real authorization middleware
```

Tidak diperbolehkan membuat false-green pattern:

```text
HTTP gagal
→ fallback repository langsung
→ test tetap PASS
```

---

# 23. Current Canonical Ownership Map

| Capability | Canonical Owner |
|---|---|
| Human identity | `Modules/Core/Person` |
| Digital User account | `Modules/Core/Identity` |
| Tenant | `Modules/Core/Tenancy` |
| Membership persistence/boundary | Core Tenancy + Authorization boundary |
| RBAC | `Modules/Core/Authorization` |
| Tenant Context | `Modules/Core/Tenancy` |
| Authentication runtime | `Modules/Auth` |
| Membership-facing user operations | `Modules/User` |
| Audit | `Modules/Core/Governance/Audit` |
| Notification infrastructure | `Modules/Core/Notification` + `Core/Platform/Notification` |
| Student | `Modules/Academic` |
| Guardian | `Modules/Academic` |
| Guardian ↔ Student | `Modules/Academic` |
| Academic grading | `Modules/Academic` |
| Employee | `Modules/HR` |
| Teacher capability | RBAC catalog owned by Academic |
| Grading human/domain actor | `Modules/HR/Employee` |
| PPDB | Scaffold only |

---

# 24. Structure That Is NOT Current Contract

Jangan menggunakan struktur lama berikut sebagai referensi:

```text
Modules/Core/Entities/MockStudent.php
create_mock_students_table.php
App\Models\User
users.tenant_id
users.name
memberships.user_id
memberships.role
Teacher model/table
WaliSantri legacy entity
Pegawai legacy entity
```

Semua konsep tersebut sudah superseded atau removed dari canonical foundation.

---

# 25. Organization / Branch / Dormitory Status

Visi aplikasi mencakup:

```text
multi-tenant
multi-lembaga
multi-cabang
integrated dormitory management
```

Phase 4B telah mengunci dan mengimplementasikan Core organizational foundation:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

Membership tetap `Person × Tenant`. Partisipasi operasional di dalam Organization/Unit direpresentasikan oleh `OrganizationalAssignment`, sedangkan runtime selection menggunakan verified `OrganizationalContext`.

Scoped authorization juga sudah current contract melalui ADR-018:

```text
TenantRoles
∪ OrganizationRoles
∪ ExactUnitRoles (ketika unit context tersedia)
```

Dormitory tetap downstream domain. Phase 4B.5/ADR-019 hanya mengunci integration boundary:

```text
Dormitory → Core
Core ↛ Dormitory
```

`Dormitory`, `Building`, `Room`, `Bed`, dan `ResidentPlacement` bukan `OrganizationUnit` variants dan tidak diimplementasikan di Core. Concrete implementation tetap future workstream di `Modules/Dormitory`.

---

# 26. Folder/Namespace Change Policy

Folder structure adalah reflection dari ownership, bukan tujuan refactor itu sendiri.

Jangan melakukan namespace movement hanya untuk membuat tree terlihat lebih rapi.

Sebuah structural move harus memiliki alasan seperti:

```text
wrong ownership
circular dependency
unclear public contract
security boundary leak
demonstrated maintainability problem
```

Bukan sekadar:

```text
"folder ini terlihat kurang Clean Architecture"
```

Architecture lebih penting daripada cosmetic symmetry.

---

# 27. Current Baseline

Setelah Core 2G + Phase 3A + Phase 4A + Phase 4B:

```text
Core Identity Foundation
🔒 LOCKED

Core Tenant Foundation
🔒 LOCKED

Authentication
🔒 LOCKED

Tenant-level RBAC
🔒 LOCKED

Student / Guardian / Employee identity contracts
🔒 LOCKED

Teacher / Grading identity contract
🔒 LOCKED

Module Kernel Runtime / Bootstrap Contract
🔒 LOCKED — Phase 4A

Organization / OrganizationUnit topology
🔒 LOCKED — Phase 4B.1

Membership Organizational Assignment
🔒 LOCKED — Phase 4B.2

Organizational Context
🔒 LOCKED — Phase 4B.3

Scoped Organizational Authorization
🔒 LOCKED — Phase 4B.4

Dormitory Integration Boundary
🔒 LOCKED — Phase 4B.5 / ADR-019

Concrete Dormitory implementation (`Modules/Dormitory`)
⬜ NOT STARTED — downstream future workstream
```

New modules harus mengonsumsi canonical foundation tersebut.

Foundation tidak dibuka kembali untuk compatibility hack dengan module baru.

---

# 28. Documentation Rule

Jika repository structure berubah secara architecturally meaningful:

1. update implementation;
2. validate tests;
3. update relevant ADR bila decision berubah;
4. update `current-architecture.md` bila contract berubah;
5. update dokumen ini agar structure kembali sesuai repository.

Dokumen ini adalah **current repository map**, bukan pengganti ADR.

ADR menjelaskan **mengapa** keputusan dibuat.

Dokumen ini menjelaskan **di mana** responsibility tersebut hidup pada repository saat ini.
