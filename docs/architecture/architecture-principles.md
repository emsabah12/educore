# EduCore Architecture Principles

- **Version**: 3.2
- **Status**: Current Architecture Principle Baseline
- **Updated**: 2026-08-17
- **Baseline**: Core Canonical Foundation 2G + Phase 3A + Phase 4A Module Kernel Runtime Hardening + Phase 4B Organizational Topology Foundation + Frontend Transport 1-6A + Foundation 6B-6D

Dokumen ini mendefinisikan prinsip arsitektur yang berlaku untuk pengembangan EduCore setelah canonical identity, tenancy, authentication, RBAC, downstream human/profile foundation, frontend-facing transport, canonical API error, capability projection, dan executable OpenAPI contract di-lock.

Dokumen ini bukan daftar pola yang wajib diterapkan secara mekanis. Prinsip digunakan untuk menjaga boundary, keamanan, maintainability, dan arah evolusi sistem. Abstraction baru hanya dibuat ketika ada kebutuhan yang nyata.

Untuk contract implementasi yang berlaku saat ini, baca juga:

- [`current-architecture.md`](current-architecture.md)
- [`folder-structure.md`](folder-structure.md)
- [`adr/README.md`](adr/README.md)
- [`../api/openapi.yaml`](../api/openapi.yaml) — executable public foundation HTTP transport contract; explicitly deferred domain operations tetap bukan hardened foundation contract

---

# 1. Architecture Before Implementation

Keputusan implementasi harus berasal dari kebutuhan domain dan keputusan arsitektur yang sudah disepakati, bukan dari kebetulan struktur framework atau library.

Urutan yang diharapkan:

```text
Requirement / Problem
        ↓
Architecture Decision
        ↓
Contract / Boundary
        ↓
Implementation
        ↓
Regression Validation
```

Framework, package, dan pola hanya dipakai jika membantu memenuhi contract tersebut.

---

# 2. Domain Before Framework

Domain meaning harus tetap jelas walaupun implementasinya menggunakan Laravel/Eloquent.

Contoh canonical meaning:

```text
Person      = manusia global
User        = akun digital/authentication
Membership  = partisipasi Person dalam Tenant
Organization = lembaga/institution di dalam Tenant
OrganizationUnit = branch/campus/operational unit di dalam Organization
OrganizationalAssignment = partisipasi operasional Membership pada Organization/Unit
Employee    = profile HR
Teacher     = role/capability, bukan entity manusia
```

Jangan membentuk domain hanya karena framework menyediakan model, guard, middleware, trait, atau convention tertentu.

Business rule yang meaningful sebaiknya ditempatkan pada service/action/domain component yang sesuai, bukan disembunyikan di controller atau framework hook.

---

# 3. Canonical Identity Separation

Human identity, digital identity, tenant participation, authorization, dan domain profile adalah concern yang berbeda.

Canonical graph:

```text
Person
  │
  ├── User
  │
  └── Membership
        │
        ├── Tenant
        ├── Student
        ├── Guardian
        └── Employee
```

Rules:

```text
Person     ≠ User
User       ≠ Membership
Membership ≠ Role
Employee   ≠ Teacher capability
```

Jangan mengembalikan human data ke `users`, atau tenant ownership ke `User`.

---

# 4. Single Responsibility

Setiap komponen memiliki satu responsibility utama yang jelas.

Contoh platform kernel:

- `ModuleDiscovery` menemukan physical module manifests.
- `ModuleManifestParser` membaca manifest.
- `ModuleManifestValidator` memvalidasi manifest.
- `DependencyResolver` memvalidasi dan mengurutkan dependency graph.
- `ModuleRegistry` menyimpan registered module definitions.
- `ModuleProviderRegistrar` mendaftarkan declared non-Core providers dalam dependency order.

Contoh application foundation:

- Repository menangani persistence/query.
- Provisioning service menangani orchestration dan transaction boundary.
- Authorization service menangani authorization decision.
- Middleware menangani request boundary, bukan domain orchestration.

Jangan menjadikan controller, repository, middleware, atau model sebagai god-object.

---

# 5. Single Source of Truth

Setiap jenis informasi hanya memiliki satu canonical source of truth.

| Concern | Canonical Source of Truth |
| --- | --- |
| Module metadata | `module.yaml` |
| Non-Core provider declarations | `module.yaml.providers` |
| Registered modules | `ModuleRegistry` |
| Human identity | `Person` |
| Digital/login account | `User` |
| Tenant participation | `Membership` |
| Tenant boundary | `Tenant` |
| Organizational topology | `Organization` + `OrganizationUnit` |
| Organizational participation | `OrganizationalAssignment` |
| Tenant roles | database `roles` + `membership_roles` |
| Scoped organizational roles | database `organizational_assignment_roles` |
| Permissions | database `permissions` + `role_permissions` |
| Student identity projection | `Student → Membership → Person` |
| Guardian identity projection | `Guardian → Membership → Person` |
| Employee identity projection | `Employee → Membership → Person` |
| Grading human/domain actor | `Employee` resolved from authenticated Membership |

Duplikasi source of truth harus dianggap architecture smell.

---

# 6. Tenant Is a Security and Data-Isolation Boundary

`Tenant` bukan sekadar filter UI atau grouping label.

Canonical meaning:

```text
Tenant
= customer boundary
= security boundary
= data-isolation boundary
```

Request tidak boleh memperoleh tenant authority hanya dari header, subdomain, route parameter, atau payload client.

Tenant context harus diverifikasi melalui canonical identity ownership:

```text
Authenticated User
        ↓
User.person_id
        ↓
Membership
        ↓
Tenant
```

Multi-lembaga dan multi-cabang dibangun **di dalam** tenant boundary melalui locked topology:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

Organization/Unit tidak menggantikan Tenant sebagai security/data-isolation boundary.

---

# 7. Fail-Closed Security

Authentication, tenancy, authorization, dan cross-tenant resolution harus fail closed.

Jika canonical context tidak dapat dibuktikan, operasi harus ditolak.

Contoh:

```text
missing/invalid token       → reject
invalid User                → reject
invalid Membership          → reject
wrong tenant                → reject
inactive Membership         → reject
missing Permission          → reject
missing required Employee   → reject
cross-tenant domain target  → reject
```

Jangan menggunakan permissive fallback untuk membuat request "tetap jalan".

---

# 8. Explicit Tenant-Aware Persistence

Semua persistence/query terhadap tenant-owned data wajib mempertahankan tenant boundary.

Dua mekanisme yang sah:

```text
Eloquent tenant-aware model
→ BelongsToTenant
```

atau:

```text
Query Builder / explicit query
→ explicit tenant-scoped predicate
```

Yang dilarang adalah query tenant-owned data tanpa tenant constraint yang dapat dibuktikan.

Untuk data dengan duplicated tenant projection, gunakan defense-in-depth bila relevan.

Contoh:

```text
Student.tenant_id
=
Membership.tenant_id
=
Current Tenant
```

---

# 9. Authentication Context Is Minimal and Verifiable

Bearer token membawa identity context minimum yang diperlukan:

```text
user_id
membership_id
tenant_id
expires_at
```

Token tidak menjadi source of truth untuk role atau permission.

Tidak ada canonical authorization claim:

```text
role
permission
```

karena authorization state harus dibaca dari database-backed RBAC agar tidak menjadi stale authority.

---

# 10. Current Request Context Must Be Lifecycle-Safe

Request-dependent service tidak boleh menyimpan stale request state melintasi request lifecycle.

Canonical rule:

```text
resolve request context
→ read current Request / current scoped context
```

Bukan:

```text
construct once
→ retain old Request
→ reuse stale attributes
```

Scoped dependency dan tenant/request context harus reset dengan benar antar request, job, atau test scope.

---

# 11. Authorization Is Database-Backed and Explicit

Canonical tenant RBAC:

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

Decision flow:

```text
AuthorizationService
 ↓
AuthorizationContext
 ↓
MembershipRoleRepository
 ↓
RolePermissionRepository
```

Authorization tidak boleh bersumber dari:

- `memberships.role`;
- token role/permission claim;
- HR classification seperti `Employee.jabatan`;
- client-supplied role;
- static global role/permission registry yang menggantikan database source of truth.

Laravel Gate/Policy tetap concern terpisah dari tenant RBAC.

Tenant-wide `AuthorizationService` mempertahankan semantics `membership_roles`. Organization/unit-aware checks menggunakan dedicated `OrganizationalAuthorizationService` dengan verified `OrganizationalContext`.

Organization-level scoped role inherit ke unit dalam Organization yang sama. Unit role hanya berlaku pada exact Unit dan tidak inherit ke parent/sibling.

Frontend capability projection adalah read-only UX/navigation hint. Capability yang diproyeksikan ke client tidak menjadi authorization source dan protected backend operation tetap harus mengevaluasi current persistence state melalui canonical authorization boundary.

---

# 12. Domain Profile Is Not Authorization

Domain status/profile tidak otomatis berarti capability.

Contoh:

```text
Employee.jabatan = GURU
≠
teacher authorization
```

Dan:

```text
Student profile
≠ student authorization role
```

Role/capability harus diberikan secara explicit melalui canonical RBAC ketika requirement membutuhkannya.

---

# 13. Server-Derived Security-Sensitive Identity

Identity yang menentukan actor/ownership security-sensitive tidak boleh dipercaya dari payload client jika server dapat menurunkannya dari authenticated context.

Contoh grading:

```text
authenticated_membership_id
+ authenticated_tenant_id
        ↓
Employee
        ↓
student_grades.teacher_id
```

Client tidak boleh memilih `teacher_id`.

Prinsip yang sama berlaku untuk future domain actor lain jika identity actor dapat diturunkan dari canonical authenticated context.

---

# 14. Explicit Dependency Direction

Module dependency harus eksplisit dan mengalir ke foundation, bukan sebaliknya.

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

Rules:

- Core tidak bergantung pada Auth atau business module.
- Auth bergantung pada Core.
- User bergantung pada Core dan Auth.
- HR bergantung pada Core dan Auth.
- Academic bergantung pada Core, HR, dan Auth.
- PPDB bergantung pada Core.
- Setiap direct production dependency harus tercermin pada `module.yaml`.

Circular dependency tidak diperbolehkan dan invalid dependency graph harus fail-fast.

---

# 15. Module Ownership Must Be Explicit

Setiap capability memiliki owning module.

Contoh:

```text
Person / User / Membership / RBAC
→ Core

Organization / OrganizationUnit / OrganizationalAssignment / OrganizationalContext
→ Core

Authentication token runtime
→ Auth

Membership-facing user operations
→ User

Student / Guardian / Grading
→ Academic

Employee
→ HR
```

Module tidak boleh mengambil ownership domain lain hanya untuk mempermudah query atau endpoint.

Cross-module access harus menggunakan contract/repository/service boundary yang memang diperlukan.

---

# 16. Composition Over Inheritance

Gunakan komposisi sebelum inheritance.

Inheritance hanya digunakan jika terdapat hubungan `is-a` yang nyata dan shared behavior yang stabil.

Jangan membuat base class hanya untuk menghindari beberapa baris kode atau memaksakan keseragaman folder.

Shared behavior seperti tenant scoping dapat menggunakan trait/contract jika responsibility-nya memang reusable dan jelas.

---

# 17. Repository for Persistence, Service for Orchestration

Repository fokus pada persistence/query concern.

Service/action digunakan untuk meaningful orchestration, terutama bila operasi melibatkan beberapa aggregate atau transaction boundary.

Contoh:

```text
StudentProvisioningService
→ Person + Membership + Student

GuardianProvisioningService
→ Person + Membership + optional Contact + Guardian

EmployeeProvisioningService
→ Person + Membership + Employee

BulkGradingService
→ actor resolution + tenant target validation + grade upsert
```

Jangan membuat service kosong yang hanya meneruskan satu repository call demi pattern symmetry.

---

# 18. Transaction Boundaries Follow Business Atomicity

Operasi yang secara bisnis harus berhasil/gagal sebagai satu unit wajib transactional.

Contoh:

```text
Person + Membership + Student
Person + Membership + Guardian
Person + Membership + Employee
Tenant + Membership + admin role
Bulk grading write set
```

Jika satu langkah gagal, partial canonical identity/persistence tidak boleh tertinggal.

---

# 19. Fail Fast Validation, Tenant-Safe Existence Validation

Format dan contract input harus ditolak sedini mungkin.

Contoh:

```text
UUIDv7 format
required fields
prohibited legacy fields
distinct collection identifiers
score range
```

Namun `exists` global tidak boleh dipakai sebagai security boundary untuk tenant-owned entity.

Tenant ownership harus diverifikasi melalui tenant-scoped application/repository query.

---

# 20. UUIDv7 for Canonical Domain Identity

Canonical/new domain identifiers menggunakan UUIDv7 ketika berada dalam foundation atau domain yang sudah direfactor ke canonical identity policy.

Current examples:

- Person
- User
- Tenant
- Membership
- Role
- Permission
- Student
- Guardian
- Employee
- StudentGrade
- Audit/notification identities yang sudah dialign

`Teacher` tidak termasuk daftar entity karena Teacher adalah role/capability.

Legacy UUIDv4 pada domain yang belum masuk workstream canonicalization tidak otomatis direwrite tanpa audit compatibility.

Metadata module tetap menggunakan stable manifest identity, bukan UUID domain entity.

---

# 21. Immutable Module Metadata, No Mutable Kernel Activation State

Module metadata berasal dari `module.yaml` dan diperlakukan immutable setelah parsing/validation.

Current Module Kernel tidak memiliki persisted `enabled/disabled` bootstrap state, `ModuleStateRepository`, `ModuleManager`, `module:enable`, atau `module:disable`.

Bootstrap participation ditentukan oleh deployment composition:

```text
physically present
→ manifest valid
→ dependencies valid
→ bootstrappable
```

Tenant feature/entitlement availability dan authorization adalah concern terpisah dari Module Kernel bootstrap.

---

# 22. Stable Module Identity

Identity module berasal dari `name` pada `module.yaml`.

Current runtime menggunakan exact manifest key:

```text
core
Auth
User
HR
Academic
PPDB
```

Canonical technical-key target adalah lowercase slug:

```regex
^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$
```

Physical lowercase cutover belum dilakukan. Sampai migration tersebut dilakukan:

- exact current manifest name tetap menjadi lookup key;
- tidak ada silent normalization;
- tidak ada permanent compatibility alias.

UUID domain entity tidak digunakan sebagai module metadata identity.

---

# 23. Convention Over Configuration

Gunakan convention yang konsisten untuk mengurangi configuration noise.

Contoh:

- module manifest location;
- namespace conventions;
- module-owned routes/providers/tests;
- canonical migration ownership;
- repository interface → implementation binding.

Provider activation adalah explicit manifest contract, bukan naming-convention guessing. `module.yaml.providers` adalah sole non-Core provider activation source.

Tambahkan konfigurasi hanya jika ada kebutuhan nyata.

Convention tidak boleh mengalahkan security boundary atau domain meaning.

---

# 24. Keep Core Fundamental, Stable, and Non-Speculative

Core hanya memiliki capability yang fundamental, shared, dan memiliki demonstrated need lintas module/platform.

Current Core foundation meliputi:

```text
Platform Module Kernel
Human Identity
Tenancy
Organizational Topology / Context
Authorization/RBAC
Governance/Audit
Shared Platform Infrastructure
```

Jangan memasukkan business domain seperti Academic, HR, Finance, Attendance, atau Dormitory ke Core.

Jangan membuat abstraction spekulatif seperti Command Bus, Query Bus, generic Organization engine, atau global registry hanya untuk terlihat enterprise-ready.

---

# 25. KISS and YAGNI Are Architecture Constraints

Sederhana bukan berarti kurang arsitektural.

Gunakan abstraction ketika ada demonstrated complexity/reuse.

Hindari:

```text
generic repository hanya demi pattern
service tanpa orchestration
base controller tanpa duplication pressure
CQRS tanpa query/command problem nyata
generic recursive organization tree tanpa concrete hierarchy requirement
```

Architecture harus menyelesaikan masalah yang ada, bukan masalah hipotetis.

---

# 26. Readability Over Cleverness

Kode harus mudah dibaca dan dapat ditelusuri oleh developer lain.

Prefer:

```text
explicit tenant predicate
explicit transaction
explicit repository contract
explicit permission name
explicit module dependency
```

Daripada magic behavior yang sulit diaudit.

Optimisasi dilakukan setelah ada kebutuhan yang terukur.

---

# 27. Testability and Real Boundary Validation

Critical architecture boundary harus diuji pada boundary yang sebenarnya.

Untuk auth/tenant/authorization HTTP flow, prefer:

```text
real production route
real Bearer token
real middleware
real membership context
real tenant context
real AuthorizationService
```

Dilarang membuat false-green pattern:

```text
HTTP failed
→ direct repository fallback
→ test tetap pass
```

Unit tests tetap digunakan untuk isolated business rules ketika boundary HTTP tidak diperlukan.

---

# 28. Error Handling Must Preserve Security Boundaries

Expected client/domain errors harus dipetakan ke response yang aman dan konsisten. Public JSON error contract menggunakan HTTP status + stable machine-readable `code` + safe user-facing `message`; validation error boleh menambahkan field-level `errors`.

Contoh semantic:

```text
401 → authentication missing/invalid
403 → authenticated but not authorized / actor unavailable
404 → tenant-scoped target tidak dapat diakses/ditemukan
422 → input contract invalid
500 → unexpected internal failure
```

Raw exception message, stack trace, credential, token, PII, atau full business payload tidak boleh bocor ke client/log secara tidak perlu.

Unexpected failure harus dilog dengan metadata struktural minimum yang aman.

---

# 29. Backward Compatibility Must Not Reopen Canonical Foundation

Stable public contract perlu dipertimbangkan saat melakukan perubahan.

Namun backward compatibility **tidak boleh** menjadi alasan untuk menghidupkan kembali legacy architecture yang sudah superseded.

Dilarang membuat compatibility hack seperti:

```text
memberships.user_id
memberships.role
users.name
User → Tenant ownership
Teacher entity
client-selected grading actor
```

Jika downstream code tidak sesuai dengan canonical foundation, downstream code yang harus diperbaiki.

Breaking change yang sah harus didokumentasikan melalui ADR/migration strategy yang sesuai dengan lifecycle project.

---

# 30. Database Evolution Follows Deployment Reality

Selama project masih berada pada development/refactor stage dengan resettable database:

```text
known canonical final schema
→ edit baseline migration
→ migrate:fresh --seed
```

Jangan membuat chain `ALTER` migration hanya untuk mempertahankan transitional schema yang belum menjadi production history.

Ketika deployment lifecycle berubah dan migration history harus dipertahankan, strategy ini harus dievaluasi ulang secara formal.

---

# 31. Documentation Is Part of the Architecture

Current contract, superseded decision, historical planning, dan future direction harus dibedakan secara eksplisit.

Lifecycle documentation:

```text
CURRENT
→ berlaku sebagai implementation contract

SUPERSEDED
→ historical decision yang sudah diganti

HISTORICAL
→ planning/context lama, bukan current contract

FUTURE / NOT LOCKED
→ direction yang belum boleh diimplementasikan sebagai contract
```

ADR tidak dihapus hanya karena keputusan berubah; statusnya diperbarui dan replacement dicatat.

Kode dan dokumentasi harus berevolusi bersama setelah architecture lock. Untuk public foundation HTTP transport, `docs/api/openapi.yaml` adalah executable compatibility contract yang harus berubah bersama implementation dan route contract tests. Domain operations yang belum hardened harus tetap ditandai explicit deferred, bukan diasumsikan covered.

---

# 32. Organizational Topology Extends, Not Rewrites, the Foundation

Phase 4B locks multi-lembaga/multi-cabang topology inside the existing Tenant boundary:

```text
Tenant
  ↓
Organization
  ↓
OrganizationUnit
```

The canonical human/tenant foundation remains unchanged:

```text
Person
  ↓
Membership
  ↓
Tenant
```

`Membership` remains `Person × Tenant`. Operational participation inside Organization/Unit is represented separately by `OrganizationalAssignment`, and runtime selection is represented by verified `OrganizationalContext`.

Scoped authorization extends tenant-wide RBAC rather than replacing it:

```text
TenantRoles
∪ OrganizationRoles
∪ ExactUnitRoles (only when unit context exists)
```

Dormitory does not become another Core topology level. Concrete implementation now lives in `Modules/Dormitory`, consuming Core through the dependency direction `Dormitory → Core` as defined by ADR-019. Ongoing Dormitory business lifecycle work remains downstream and must not reopen Core topology, identity, tenancy, or authorization contracts.

The locked topology must not reintroduce tenant ownership on `User`, duplicate Person/Membership identity, or create a second Role/Permission catalog.

---

# Summary

EduCore dikembangkan dengan prinsip utama:

- architecture before implementation;
- domain meaning before framework convenience;
- canonical separation antara Person, User, Membership, authorization, dan domain profile;
- Tenant sebagai security/data-isolation boundary;
- locked `Tenant → Organization → OrganizationUnit` topology dengan separate `OrganizationalAssignment`/`OrganizationalContext`;
- tenant-wide + organizational scoped authorization yang tetap memakai global Role/Permission catalog;
- frontend capability projection sebagai UX hint, bukan authorization authority;
- Dormitory sebagai downstream module boundary (`Dormitory → Core`), bukan Core topology level;
- fail-closed authentication, tenancy, dan authorization;
- canonical `/api/v1` public transport dengan stable machine-readable error codes dan executable OpenAPI contract;
- explicit tenant-aware persistence;
- database-backed RBAC;
- module ownership dan dependency direction yang jelas;
- manifest-driven, dependency-ordered, fail-fast Module Kernel bootstrap;
- deployment composition yang terpisah dari tenant feature availability dan authorization;
- repository untuk persistence, service/action untuk meaningful orchestration;
- transaction berdasarkan business atomicity;
- UUIDv7 untuk canonical domain identities;
- Core yang fundamental, stable, dan non-speculative;
- KISS dan YAGNI sebagai architecture constraints;
- real boundary regression testing;
- canonical foundation tidak dibuka ulang untuk legacy compatibility;
- documentation lifecycle yang membedakan current, superseded, historical, dan future.

Prinsip-prinsip ini menjadi guardrail ketika EduCore terus berevolusi di atas current multi-tenant dan organizational foundation, sementara downstream domain seperti Dormitory berkembang tanpa memperluas Core topology secara implisit.
