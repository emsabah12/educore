Baik. **FE-5 kita lock** dan sekarang masuk hanya ke **FE-6 — Capability / Authorization UX PRD**.

Tahap ini sangat penting karena kita menentukan bagaimana frontend _menggunakan_ authorization information tanpa pernah menjadi authorization engine. Backend baseline sudah eksplisit: tenant/workspace capability endpoints adalah read projection untuk navigation/UX; protected operation tetap melakukan authorization ulang dari persistence state.

# FE-6 — Capability / Authorization UX PRD

## 1. Tujuan & Prinsip Fundamental

Kita perlu memisahkan tiga konsep:

```text
ROLE
↓
bagaimana permission diberikan

PERMISSION / CAPABILITY PROJECTION
↓
apa yang secara efektif dapat dilakukan
dalam context saat ini

FRONTEND UX POLICY
↓
apa yang ditampilkan / diaktifkan
```

Frontend seharusnya **tidak peduli user mendapat permission tersebut dari role apa**.

Jangan:

```typescript
if (user.roles.includes("admin")) {
  showStudentMenu();
}
```

Gunakan konsep:

```typescript
if (can("academic.students.view")) {
  showStudentMenu();
}
```

Namun bahkan `can()` di browser tetap hanya:

```text
UX decision
```

bukan:

```text
security decision
```

Canonical backend sendiri sudah menetapkan bahwa role/permission tidak berasal dari token dan capability projection tidak boleh dikirim kembali sebagai authorization authority.

---

# 2. Canonical Capability Sources

Current backend mempunyai dua projection endpoint.

### Tenant context

```http
GET /api/v1/core/authorization/capabilities
```

Conceptual result:

```json
{
  "scope": {
    "type": "tenant",
    "tenant_id": "...",
    "membership_id": "..."
  },
  "is_global_superadmin": false,
  "permissions": ["academic.grades.write"]
}
```

### Organization / OrganizationUnit context

```http
GET /api/v1/core/authorization/workspace-capabilities
X-EduCore-Organizational-Assignment-Id: ...
```

Conceptual result:

```json
{
  "scope": {
    "type": "organization_unit",
    "tenant_id": "...",
    "membership_id": "...",
    "organizational_assignment_id": "...",
    "organization_id": "...",
    "organization_unit_id": "..."
  },
  "is_global_superadmin": false,
  "permissions": ["academic.grades.write", "dormitory.rooms.manage"]
}
```

Repository foundation memang dirancang dengan vocabulary canonical `permissions.name`, bukan membuat vocabulary frontend baru bernama capability aliases.

---

# 3. Gunakan Permission, Bukan Role

Saya merekomendasikan ini menjadi P0 requirement:

> **Semua visibility/action requirement frontend harus diekspresikan sebagai permission requirement, bukan role-name requirement.**

Misalnya jangan:

```text
Menu Dormitory
visible if role = "Kepala Asrama"
```

Tetapi:

```text
Dormitory Rooms
requires:
dormitory.rooms.view
```

dan:

```text
Manage Room
requires:
dormitory.rooms.manage
```

Mengapa?

Karena satu permission bisa diberikan melalui:

```text
Role A
Role B
Role C
scoped Role
future custom Tenant role
```

tanpa frontend perlu berubah.

Ini juga sesuai backend design: module dapat memiliki concrete permission catalog masing-masing dan role name bukan canonical authorization source.

---

# 4. Jangan Membuat Frontend Capability Vocabulary Kedua

Hindari:

```text
Backend permission:
academic.grades.write

Frontend capability:
CAN_EDIT_GRADE

Navigation capability:
GRADE_EDITOR

Button capability:
EDIT_ASSESSMENT
```

Itu akan menghasilkan tiga vocabularies yang harus selalu sinkron.

Saya merekomendasikan:

```text
Canonical permission:
academic.grades.write
```

digunakan langsung sebagai contract.

Frontend boleh mempunyai typed constant:

```typescript
Permission.AcademicGradesWrite;
```

tetapi value-nya tetap:

```text
academic.grades.write
```

Jadi constant tersebut hanya developer convenience, bukan authorization catalog baru.

---

# 5. Static Navigation Catalog

Navigation structure sebaiknya **tidak dikirim oleh Core backend**.

Core backend mengetahui:

```text
permissions
authorization
scope
```

Core tidak perlu mengetahui:

```text
sidebar
icons
React routes
navigation order
labels
```

Kita ingin separation:

```text
Backend
↓
What CAN this context do?

Frontend
↓
How should available application features
be presented?
```

Maka frontend memiliki static navigation catalog.

Conceptual:

```typescript
{
  id: 'academic.students',
  label: 'Students',
  route: '/academic/students',
  requiredPermissions: [
    'academic.students.view'
  ]
}
```

Business modules nantinya memiliki catalog mereka sendiri.

---

# 6. Module Owns Navigation Requirements

Sama seperti backend module memiliki concrete capabilities, frontend module sebaiknya memiliki navigation definition-nya sendiri.

Conceptual:

```text
Academic frontend module
├── routes
├── navigation
└── permission requirements

Dormitory frontend module
├── routes
├── navigation
└── permission requirements
```

Bukan:

```text
src/core/navigation.ts

3000 lines containing:
Academic
HR
Dormitory
PPDB
Finance
Library
...
```

Dengan begitu pertumbuhan:

```text
5 modules
→
20 modules
→
50 modules
```

tidak membuat Core frontend menjadi monolith.

---

# 7. Effective Navigation Composition

Flow yang saya rekomendasikan:

```text
Module Navigation Catalog
         +
Current Authenticated Context
         +
Current Workspace
         +
Capability Projection
         ↓
Effective Navigation
```

Contoh:

```text
Catalog
────────────────────
Students
requires student.view

Grades
requires grades.view

Rooms
requires dormitory.rooms.view


Current capabilities
────────────────────
student.view
grades.view
```

Result:

```text
Students    ✅
Grades      ✅
Rooms       hidden
```

---

# 8. Single Permission Requirement

Simple case:

```text
View Students
```

requires:

```text
academic.students.view
```

Semantics:

```text
has permission
→ feature candidate visible

does not have permission
→ hidden
```

---

# 9. Multiple Permissions — ALL vs ANY

Kita harus menghindari ambiguity.

Route/action metadata harus dapat menyatakan:

```text
ALL
```

atau:

```text
ANY
```

Contoh ALL:

```text
Advanced student import

requires ALL:
student.import
student.create
```

Contoh ANY:

```text
People directory

requires ANY:
student.view
employee.view
guardian.view
```

Jangan membuat implicit convention seperti:

```typescript
permissions: ["a", "b"];
```

yang tidak jelas berarti:

```text
a AND b
```

atau:

```text
a OR b
```

Exact TypeScript shape nanti diputuskan pada ADR/implementation.

---

# 10. Default Requirement Harus Fail Closed

Jika developer lupa mendefinisikan capability requirement untuk protected feature, jangan ada asumsi:

```text
no metadata
→ everyone allowed
```

Kita harus membedakan:

```text
PUBLIC / AUTHENTICATED-NO-PERMISSION-REQUIRED
```

secara eksplisit dari:

```text
PROTECTED-CAPABILITY
```

Untuk business mutation route, default engineering policy sebaiknya fail closed.

---

# 11. Capability State Bukan Boolean Global

Jangan hanya:

```typescript
isCapabilitiesLoaded = true;
```

Kita perlu conceptual states:

```text
UNRESOLVED
LOADING
READY
STALE
ERROR
```

Karena:

```text
[] permissions
```

bisa berarti dua hal berbeda:

```text
READY + user has zero permissions
```

atau:

```text
we have not loaded anything yet
```

Frontend tidak boleh mencampur keduanya.

---

# 12. Saat Capability Belum Siap

Misalnya setelah workspace switch:

```text
Workspace B
Capabilities B = loading
```

Kita jangan sementara menggunakan:

```text
Capabilities A
```

dan jangan pula menunjukkan semua menu.

Safer UX:

```text
navigation authorization-sensitive area
→ loading/skeleton

protected actions
→ unavailable
```

setelah projection B siap barulah menu disusun ulang.

Ini **fail closed pada presentation layer**.

---

# 13. Tenant Capability vs Workspace Capability

FE-5 sudah menetapkan context.

Sekarang kita kunci:

### Tenant-level workspace

Gunakan:

```text
Tenant Capability Projection
```

### Organization / Unit workspace

Gunakan:

```text
Workspace Capability Projection
```

Jangan:

```text
tenant capabilities
+
workspace capabilities
→ merge manually in browser
```

Karena backend OrganizationalAuthorizationService sudah melakukan effective authorization termasuk tenant-wide + scoped role semantics. Backend tests dan architecture foundation memang menegaskan workspace evaluator menggabungkan tenant role dengan scoped roles.

---

# 14. Frontend Tidak Menghitung Scope Inheritance

Ini dilarang:

```typescript
if (
  role.organizationId === workspace.organizationId ||
  role.unit.parentId === ...
) {
    ...
}
```

Frontend tidak perlu mengetahui algorithm:

```text
Tenant role
→ global within Tenant

Organization role
→ Organization + descendants

Unit role
→ exact Unit
```

Frontend menerima hasil akhirnya:

```text
permissions[]
```

Backend adalah satu-satunya evaluator scoped authorization.

---

# 15. Global Superadmin

Ada special case di backend:

```text
users.is_superadmin
```

Ini bukan normal Tenant role.

Foundation sebelumnya memang sengaja memproyeksikannya sebagai:

```json
"is_global_superadmin": true
```

bukan synthetic:

```text
role = superadmin
```

atau:

```text
permission = *
```

Saya menyarankan frontend mempertahankan distinction tersebut.

---

# 16. Jangan Membuat `"*"` Permission

Hindari:

```json
{
  "permissions": ["*"]
}
```

atau:

```text
all
superadmin.*
everything
```

Mengapa?

Karena kemudian setiap permission evaluator frontend membutuhkan special condition:

```typescript
return permissions.includes(permission) || permissions.includes("*");
```

Itu menciptakan synthetic authorization semantics baru.

Lebih baik:

```text
is_global_superadmin
```

tetap explicit presentation property.

---

# 17. Superadmin UI Juga Bukan Security Authority

Walaupun:

```json
"is_global_superadmin": true
```

frontend hanya menggunakannya untuk UX.

Backend setiap administrative operation tetap memvalidasi canonical authenticated User.

Client tidak pernah mengirim:

```json
{
  "is_global_superadmin": true
}
```

sebagai proof.

---

# 18. Navigation Hidden vs Disabled

FE-2 sudah menetapkan policy umum. Sekarang kita finalkan authorization-specific rule.

### Tidak mempunyai capability

Default:

```text
HIDDEN
```

Contoh:

```text
user lacks:
dormitory.rooms.manage
```

Maka:

```text
Manage Rooms
→ hidden
```

### Mempunyai capability tetapi business condition tidak terpenuhi

Gunakan:

```text
DISABLED + reason
```

Contoh:

```text
User has:
academic.grades.write

but grading period CLOSED
```

Maka:

```text
Save Grade
→ disabled

"Grading period is closed."
```

Jadi:

```text
authorization restriction
→ hidden

business-state restriction
→ disabled/explained
```

---

# 19. Exception untuk Discoverability

Tidak semua authorization-hidden feature harus selalu benar-benar invisible.

Ada kemungkinan product ingin:

```text
Upgrade
Request Access
Ask Administrator
```

untuk fitur tertentu.

Tetapi itu harus menjadi explicit product requirement.

Default Foundation tetap:

```text
no capability
→ feature hidden
```

agar UI administratif tidak penuh dengan controls yang tidak dapat digunakan.

---

# 20. Button-Level Authorization

Route capability saja tidak cukup.

Misalnya:

```text
Students page
requires:
student.view
```

Tetapi dalam page:

```text
Add Student
requires:
student.create

Edit
requires:
student.update

Delete
requires:
student.delete
```

Jadi authorization UX berlaku pada beberapa levels:

```text
Module
Route
Section
Action
```

Semua tetap menggunakan permission vocabulary yang sama.

---

# 21. Jangan Sebarkan `permissions.includes()` ke Seluruh Codebase

Walaupun belum coding, saya sarankan architectural requirement:

Business component tidak langsung:

```typescript
permissions.includes("academic.students.create");
```

di ratusan file.

Kita akan mempunyai satu presentation primitive conceptual:

```text
can(permission)

canAny(...)
canAll(...)
```

yang hanya membaca current verified capability projection.

Tujuannya bukan membuat authorization engine baru, tetapi:

```text
centralize frontend presentation semantics
```

sehingga loading/stale/superadmin handling konsisten.

---

# 22. `can()` Tidak Boleh Mempunyai Business Logic

Jangan:

```typescript
can("student.edit");
```

kemudian fungsi tersebut juga:

```text
checks enrollment state
checks academic period
checks record ownership
```

Capability primitive hanya menjawab projection.

Business eligibility harus tetap terpisah.

```text
can(...)
→ authorization UX

canEditStudentRecord(...)
→ domain/business state
```

Separation ini penting.

---

# 23. Route Authorization Metadata

Saya merekomendasikan conceptual route contract:

```text
Academic Students

Authentication:
required

Context:
organization workspace required

Permission:
academic.students.view
```

Contoh route lain:

```text
Dashboard

Authentication:
required

Context:
tenant

Permission:
none
```

Sehingga route guard dapat melakukan orchestration secara generic.

---

# 24. Route Guard Flow

Conceptual:

```text
Route requested
      ↓
authenticated?
      │ no
      → login
      ↓
required context available?
      │ no
      → workspace selection/recovery
      ↓
capabilities ready?
      │ no
      → loading
      ↓
required permission present?
      │ no
      → frontend Forbidden / safe route
      ↓
render page
```

Tetapi setelah page dirender:

```text
API request
```

tetap harus melewati backend authorization.

---

# 25. Direct URL Access

Misalnya user tidak memiliki:

```text
dormitory.rooms.manage
```

Sidebar tidak menampilkan menu.

Tetapi user membuka:

```text
/dormitory/rooms/manage
```

Frontend route guard boleh langsung menampilkan:

```text
You don't have access to this page.
```

tanpa melakukan business-data request.

Itu baik untuk UX/performance.

Namun direct request ke backend tetap dapat menghasilkan `403`.

Dan backend result adalah canonical authority.

---

# 26. Frontend Allowed, Backend Denied

Sangat mungkin terjadi:

```text
Capability cache says:
student.update ✅

Admin removes permission

User clicks Edit

Backend:
403
```

Frontend **tidak boleh berkata**:

```text
"backend bug because capability said true"
```

Projection bisa stale.

Correct behavior:

```text
mutation
↓
403 authorization denied
↓
action fails safely
↓
invalidate/reload capability projection
↓
recompose UI
```

Ini penting untuk permission changes yang berlaku tanpa rewriting token. Backend foundation memang dirancang agar authorization tetap berasal dari current database state.

---

# 27. Frontend Denied, Backend Might Allow

Kebalikannya juga dapat terjadi.

Misalnya admin baru memberikan permission tetapi frontend masih mempunyai cached capability lama.

Frontend masih menyembunyikan action sampai capability refresh.

Ini acceptable untuk short-lived stale UX, tetapi kita perlu freshness policy agar tidak lama.

Jangan mengatasi ini dengan:

```text
"If unsure, show everything"
```

Frontend default tetap fail closed.

---

# 28. Capability Freshness Events

Saya menyarankan capability refresh pada event berikut:

```text
Login/bootstrap
Tenant/Membership switch
Workspace switch
Stale organizational-context recovery
Backend authorization denial
Explicit capability invalidation
```

Tidak perlu:

```text
every route navigation
```

dan tidak perlu:

```text
poll every second
```

Kita akan menentukan caching/revalidation detail pada ADR server state.

---

# 29. Tidak Perlu Real-Time Permission Push di Foundation v1

Untuk sekarang kita tidak membutuhkan:

```text
WebSocket:
permission changed
```

ke semua clients.

Jika administrator mengubah permission, browser lain bisa mengetahui melalui:

```text
next capability refresh
```

atau:

```text
backend 403 → refresh
```

Ini jauh lebih sederhana.

Real-time revocation dapat menjadi future security/product requirement kalau memang diperlukan.

---

# 30. Cache Identity

Capability state harus dibedakan berdasarkan context.

Conceptual:

```text
Tenant Capability
key:
membership + tenant
```

dan:

```text
Workspace Capability
key:
membership + tenant + assignment
```

Dengan demikian:

```text
Tenant A / Academic
```

tidak mungkin memakai capability projection:

```text
Tenant B / Finance
```

Ini melanjutkan context-aware server-state principle dari FE-4/FE-5.

---

# 31. Capability Cache Tidak Boleh Persist Selamanya

Kita tidak membutuhkan:

```text
localStorage.permissions
```

sebagai canonical state.

Capability projection dapat dipulihkan dari server dengan murah dibanding risiko stale authorization UX.

Jika ada cache antar reload, ia hanya boleh dianggap temporary optimization dan tetap harus divalidasi/refetch.

Foundation v1 lebih baik sederhana:

```text
session bootstrap
→ fresh capability projection
```

---

# 32. Permission Removal Saat User Sedang di Page

Scenario:

```text
User on:
Students

student.view removed
```

Request berikutnya:

```text
403
```

Frontend harus:

```text
refresh capabilities
      ↓
student.view absent
      ↓
current route no longer valid
      ↓
redirect safe route
```

Misalnya:

```text
/dashboard
```

Jangan tetap menampilkan stale page dengan broken controls.

---

# 33. Permission Removal untuk Mutation Saja

Scenario:

```text
student.view ✅
student.update removed
```

Current Students page masih valid.

Maka setelah capability refresh:

```text
page remains
Edit button disappears
```

Tidak perlu redirect.

Jadi revalidation harus terjadi pada **route requirement**, bukan “ada perubahan permission apa pun → dashboard”.

---

# 34. Capability Endpoint Failure

Misalnya:

```text
/auth/me success
/workspace-capabilities → 503
```

Frontend tidak boleh:

```text
assume all permissions
```

dan juga jangan secara keliru menyatakan user tidak memiliki permission.

State-nya adalah:

```text
CAPABILITY_ERROR
```

UX:

```text
We couldn't determine your available features.

[ Retry ]
```

Protected business UI tidak interactive sampai capability state berhasil dipulihkan.

---

# 35. Zero Permissions Berbeda dengan Capability Error

### Valid response

```json
{
  "permissions": []
}
```

berarti:

```text
READY
user has zero projected permissions
```

UX dapat menampilkan:

```text
No features are currently available for this workspace.
```

### Request failure

berarti:

```text
ERROR
unknown capabilities
```

UX harus menawarkan retry/recovery.

Ini jangan dicampur.

---

# 36. Capability Projection Scope Validation

Response capability mempunyai scope information.

Frontend sebaiknya melakukan defensive consistency check.

Misalnya current:

```text
Tenant B
Assignment Y
```

tetapi late response mengatakan:

```text
Tenant A
Assignment X
```

Response itu harus dianggap stale dan tidak di-commit.

Ini bukan authorization verification; ini stale-response protection.

Sangat berguna pada concurrent context changes.

---

# 37. Navigation Module Visibility

Bagaimana jika Academic memiliki:

```text
Students
Classes
Grades
```

tetapi user hanya mempunyai:

```text
academic.grades.view
```

Sidebar dapat menjadi:

```text
ACADEMIC
  Grades
```

Module group ditampilkan jika minimal satu child navigation item visible.

Jangan tampilkan:

```text
ACADEMIC
  [nothing]
```

---

# 38. Parent Menu Permission

Saya tidak menyarankan synthetic permission:

```text
academic.module.access
```

hanya supaya sidebar bisa ditampilkan.

Jika module mempunyai child actions:

```text
student.view
grade.view
class.view
```

module navigation visibility dapat dihitung dari visible children.

Permission baru hanya dibuat jika memang ada **business/security operation** yang nyata, bukan demi kebutuhan sidebar.

---

# 39. Core Tidak Mengetahui Business Navigation

Ini saya anggap important architectural invariant.

Backend Core:

```text
returns effective permissions
```

Frontend Academic:

```text
knows which Academic menu
requires which permission
```

Frontend Dormitory:

```text
knows its own navigation
```

Jangan membuat endpoint:

```http
GET /api/v1/core/menu
```

yang mengembalikan:

```json
[
  {
    "label": "Students",
    "icon": "..."
  }
]
```

Itu akan membuat Core mengetahui presentation structure seluruh module.

---

# 40. Role Management UI Berbeda dari Authorization Runtime

Nanti pada IAM Administration PRD user mungkin melihat:

```text
Roles
Permissions
Assignments
```

Itu administrative resource UI.

Tetapi runtime frontend tetap menggunakan:

```text
effective permission projection
```

Jadi jangan mengambil:

```text
GET /roles
```

lalu frontend menghitung sendiri:

```text
Role → Permission → Scope
```

Backend projection sudah dibuat agar kita **tidak perlu melakukan itu**.

---

# 41. Teacher / Admin / Staff Tetap Persona, Bukan Frontend Condition

Contoh:

```text
Teacher
```

berguna untuk product persona.

Tetapi code tidak boleh:

```typescript
if (role === 'teacher')
```

untuk membuka Academic route.

Backend canonical documentation sendiri menyatakan Teacher adalah role/capability semantics, bukan identity/profile entity.

UX tetap berdasarkan effective capabilities.

---

# 42. Business Object Ownership Tidak Dihitung Frontend

Nanti mungkin ada rule:

```text
Teacher can update only classes they teach
```

Frontend boleh menampilkan UI yang sesuai berdasarkan response API.

Tetapi jangan membuat security:

```typescript
if (class.teacherId === currentUser.id)
```

lalu menganggap mutation aman.

Record-level authorization harus backend-enforced.

FE-6 fokus pada coarse feature affordance, bukan menciptakan client-side ABAC.

---

# 43. Delete Confirmation Tidak Terkait Authorization

Jika user memiliki:

```text
student.delete
```

frontend boleh menampilkan Delete.

Tetapi confirmation:

```text
Are you sure?
```

adalah safety UX, bukan authorization.

Keduanya jangan dicampur.

```text
Permission
→ may attempt operation

Confirmation
→ user intends operation

Backend
→ operation actually authorized
```

---

# 44. Loading Navigation Tidak Boleh Berubah-ubah Berlebihan

Kita ingin menghindari:

```text
show all menus
→ hide menus
→ show menus
```

saat startup.

Flow yang lebih stabil:

```text
App bootstrap
↓
context known
↓
capabilities loading
↓
shell skeleton
↓
capabilities ready
↓
render effective navigation
```

Ini juga menghindari flash of unauthorized UI.

---

# 45. No Flash of Unauthorized Content

Ini P0 UX/security hygiene.

Jangan:

```text
Render page
↓
1 second later capability fetched
↓
hide page
```

Protected route tidak boleh render business content sebelum required capability state ready.

Ini bukan karena frontend security boundary, tetapi karena:

```text
confusing UX
potentially sensitive cached content flash
```

---

# 46. Permission Names Tidak Boleh Ditampilkan sebagai Primary UX

Developer contract:

```text
dormitory.rooms.manage
```

User-facing UX:

```text
Manage Rooms
```

Forbidden state jangan:

```text
Missing permission:
dormitory.rooms.manage
```

kepada normal user.

Boleh untuk debug/admin diagnostics yang explicitly designed, tetapi bukan default production message.

---

# 47. Accessibility Tetap Berlaku pada Hidden/Disabled Controls

Jika disabled karena business state:

```text
aria-disabled
accessible reason
keyboard semantics
```

harus benar.

Jika unauthorized:

```text
control omitted
```

daripada hidden hanya secara visual dengan CSS:

```css
visibility: hidden;
```

tetapi masih focusable/accessible.

Authorization UX harus konsisten juga di accessibility tree.

---

# 48. Capability Helper Tidak Boleh Menerima Role

Saya menyarankan interface conceptual nantinya:

```text
can(permission)
canAny(permissions)
canAll(permissions)
```

Bukan:

```text
hasRole()
isAdmin()
isTeacher()
```

untuk runtime feature authorization.

`hasRole()` mungkin kelak berguna di IAM administration untuk menampilkan role metadata, tetapi bukan foundation authorization UX primitive.

---

# 49. Capability Architecture

Setelah FE-6, dependency-nya menjadi:

```text
Canonical Backend Authorization
        ↓
Capability Projection API
        ↓
Capability Server State
        ↓
Frontend Presentation Policy
        ├── Route Guard
        ├── Navigation Composition
        ├── Section Visibility
        └── Action Visibility
```

Dan **tidak pernah**:

```text
Frontend Capability State
        ↓
Backend trusts client
```

---

# 50. P0 / P1 Classification

P0 foundation mencakup: canonical permission-name vocabulary, tenant/workspace projection separation, global-superadmin projection yang tetap terpisah, capability state lifecycle, context-aware cache identity, module-owned navigation catalog, permission-based route metadata, `can/canAny/canAll` presentation primitives, no role-name authorization, hidden unauthorized actions, direct-route guard, stale capability recovery, no flash of unauthorized content, dan backend `403` sebagai authority.

P1/future meliputi capability diagnostics untuk administrator, richer “request access” UX, real-time authorization updates, permission change notifications, serta advanced route/menu discovery.

---

# 51. Non-Negotiable FE-6 Guardrails

Saya merekomendasikan kita lock berikut sebagai satu set invariant:

```text
CAP-FE-01
Backend remains the only authorization authority.

CAP-FE-02
Capability projection is presentation state only.

CAP-FE-03
Frontend authorization UX uses canonical permission names,
not role names.

CAP-FE-04
Frontend must not invent a second capability vocabulary.

CAP-FE-05
Core backend does not own frontend navigation structure.

CAP-FE-06
Each frontend business module owns its navigation and
permission requirements.

CAP-FE-07
Tenant and workspace capability projections are never
manually merged in the browser.

CAP-FE-08
Frontend never evaluates scoped-role inheritance.

CAP-FE-09
Global superadmin remains an explicit projection and
must not become a synthetic "*" permission.

CAP-FE-10
Missing authorization normally hides actions/navigation.

CAP-FE-11
Business-state restriction normally disables an allowed
action and explains why.

CAP-FE-12
Protected routes wait until capability state is READY.

CAP-FE-13
Unknown/error capability state fails closed for UX.

CAP-FE-14
Zero permissions and failed capability loading are
distinct states.

CAP-FE-15
Direct-route frontend guards optimize UX but never replace
backend authorization.

CAP-FE-16
Backend authorization denial overrides cached frontend
capability state.

CAP-FE-17
Authorization denial triggers appropriate capability
revalidation.

CAP-FE-18
Capability cache identity includes the active tenant/
membership/workspace context.

CAP-FE-19
Stale capability responses from superseded contexts
must never replace active-context state.

CAP-FE-20
Frontend does not calculate object-level authorization
from cached domain data.

CAP-FE-21
Capability helpers remain presentation primitives and
contain no business rules.

CAP-FE-22
Protected content must not flash before authorization UX
state is resolved.

CAP-FE-23
Permission identifiers are developer/API contracts,
not normal user-facing labels.

CAP-FE-24
Role/permission administrative screens remain separate
from runtime effective authorization.
```

---

# FE-6 Gate Recommendation

Saya menyarankan status:

```text
FE-6 — Capability / Authorization UX PRD

Canonical Source
✅ Backend database authorization
✅ Tenant capability projection
✅ Workspace capability projection
✅ Permission names as canonical frontend vocabulary
✅ Global superadmin projected separately

Navigation
✅ Static frontend navigation catalog
✅ Module-owned navigation definitions
✅ Capability-aware composition
✅ Empty module groups removed
✅ Core does not own menu structure

Routes
✅ Authentication/context/capability metadata
✅ Capability-aware route guards
✅ Direct URL protected
✅ Backend remains final authority

Actions
✅ Permission-based visibility
✅ Unauthorized → hidden by default
✅ Business unavailable → disabled + explanation
✅ can / canAny / canAll presentation abstraction

State
✅ UNRESOLVED / LOADING / READY / STALE / ERROR
✅ Context-aware cache identity
✅ No stale projection across Tenant/Workspace switch
✅ No flash of unauthorized content

Freshness
✅ Bootstrap refresh
✅ Tenant switch refresh
✅ Workspace switch refresh
✅ 403-driven revalidation
✅ No aggressive polling
⏭ Real-time authorization push future

Forbidden Architecture
❌ role-name runtime authorization
❌ client-side scope inheritance
❌ synthetic "*" permission
❌ Core-generated navigation
❌ frontend-computed backend authorization
❌ trusting capability cache over backend 403
```

**FE-6 menurut saya siap di-lock.**

Hasil penting FE-6 adalah kita sekarang memiliki separation yang sangat jelas:

```text
ROLE
→ administration / permission assignment

PERMISSION
→ canonical authorization vocabulary

CAPABILITY PROJECTION
→ effective permission read model

FRONTEND POLICY
→ navigation / routes / actions

BACKEND
→ final security enforcement
```

Ini akan membuat custom role per Tenant tetap mungkin tanpa membuat frontend berubah setiap kali organisasi menciptakan role baru.

Setelah persetujuan Anda, langkah berikutnya hanya **FE-7 — Error, Loading & Recovery UX PRD**. Di sana kita akan mengunci centralized error taxonomy untuk `401/403/404/409/422/429/5xx/network`, validation errors, context recovery, retry policy, loading/skeleton strategy, unsaved-form protection, offline/network degradation, dan memastikan retry tidak pernah menggandakan mutation.
