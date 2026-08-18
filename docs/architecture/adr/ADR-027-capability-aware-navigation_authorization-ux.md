# ADR-027 — Capability-Aware Navigation & Authorization UX

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Permission Projection, Navigation, Route Guard, Actions & Backend-Denial Reconciliation

> **Decision Summary**
>
> EduCore Frontend menggunakan **canonical permission names dari capability projection** sebagai satu-satunya vocabulary runtime untuk authorization UX.
>
> ```text
> Backend Roles
>       ↓
> Permission Evaluation
>       ↓
> Capability Projection
>       ↓
> permissions[]
>       ↓
> Frontend Policy
>       ├── Navigation
>       ├── Routes
>       └── Actions
> ```
>
> Frontend tidak menghitung authorization sendiri dan tidak menggunakan role names, token claims, organizational hierarchy, atau `is_global_superadmin` sebagai bypass.
>
> Navigation catalog dan route policy tetap **frontend-owned dan module-owned**. Backend tidak mengirim React menu tree.
>
> Capability absence menyebabkan authorization-controlled UI **hidden by default**. Business-state restriction menggunakan **disabled + explanation**.
>
> Direct route access tetap dijaga oleh frontend untuk UX, tetapi backend selalu menjadi final security authority.
>
> Backend `AUTHORIZATION_DENIED` tidak pernah dioverride, tidak menyebabkan logout, dan tidak otomatis di-retry.

---

## 1. Context

Backend sekarang menyediakan dua canonical projection:

```text
GET /api/v1/core/authorization/capabilities

GET /api/v1/core/authorization/workspace-capabilities
```

Keduanya mengembalikan secara konseptual:

```text
scope
is_global_superadmin
permissions[]
```

`permissions[]` berisi canonical machine-readable permission names, misalnya:

```text
academic.grades.write
dormitory.rooms.view
dormitory.rooms.manage
```

Backend permission catalog tidak menggunakan synthetic wildcard.

Artinya:

```text
academic.*
```

bukan canonical permission.

---

## 2. Backend Authority Model

Canonical relationship tetap:

```text
Role
  ↓
Permission assignment
  ↓
Backend authorization evaluation
  ↓
Effective capability projection
  ↓
Frontend UX
```

Frontend tidak diperbolehkan membalik hubungan tersebut menjadi:

```text
role name
↓
frontend permission assumptions
```

Contoh yang dilarang:

```ts
if (role === "admin") {
    showRoleManagement();
}
```

atau:

```ts
if (role === "teacher") {
    allowGradeEditing();
}
```

---

## 3. Selected Runtime Authorization Vocabulary

Frontend menggunakan:

```text
permissions[]
```

dari capability projection.

Evaluation primitives secara konseptual:

```text
has(permission)

hasAll(permissions)

hasAny(permissions)
```

Permission matching harus:

```text
exact
```

bukan prefix/wildcard matching.

Contoh:

```text
dormitory.rooms.view
```

tidak otomatis berarti:

```text
dormitory.rooms.*
```

atau:

```text
dormitory.*
```

---

## 4. No Frontend Permission Inheritance

Frontend tidak menghitung:

```text
Role → Permission
```

atau:

```text
Organization → child units
```

atau:

```text
parent role → child role
```

atau scoped-role inheritance.

Semua evaluation tersebut tetap backend responsibility.

Frontend menerima hasil akhirnya saja.

---

## 5. `is_global_superadmin` Policy

Backend projection memang menyertakan:

```text
is_global_superadmin
```

tetapi frontend authorization engine:

```text
MUST NOT
```

melakukan:

```ts
if (isGlobalSuperadmin) {
    return true;
}
```

untuk semua permission.

Alasannya konkret.

Pada Tenant projection, global superadmin memperoleh seluruh canonical permission catalog.

Namun pada Workspace projection, backend **tetap menjalankan scoped organizational authorization evaluation** dan tidak melakukan superadmin permission short-circuit.

Karena itu frontend bypass berdasarkan boolean tersebut dapat menghasilkan:

```text
frontend = allowed
backend workspace = denied
```

yang merupakan architecture drift.

### Decision

```text
is_global_superadmin
≠ frontend permission bypass
```

Nilai tersebut boleh dipertahankan sebagai canonical metadata apabila suatu UX memang membutuhkannya, tetapi bukan general authorization primitive.

Jika suatu future Platform Administration UI membutuhkan global-superadmin-specific authorization, backend harus menyediakan contract/capability yang eksplisit atau architecture requirement baru.

---

## 6. Capability Projection Must Match Current Context

Capability response tidak boleh langsung dianggap READY hanya karena HTTP 200.

Tenant projection harus cocok dengan active:

```text
membership_id
tenant_id
```

Workspace projection harus cocok dengan active:

```text
membership_id
tenant_id
organizational_assignment_id
organization_id
organization_unit_id
```

Jika projection scope tidak cocok dengan current authoritative context:

```text
CONTRACT / SECURITY FAILURE
```

dan projection tidak boleh dipakai.

---

## 7. Capability State

Canonical authorization readiness:

```text
UNRESOLVED
LOADING
READY
STALE
ERROR
```

Namun `STALE` authorization state tidak boleh disamakan secara buta dengan generic TanStack Query `isStale`.

Contohnya, ordinary cached server data dapat menjadi stale untuk background refetch tetapi masih valid ditampilkan.

Sebaliknya capability yang secara eksplisit invalid karena:

```text
Tenant switch
Workspace switch
backend authorization denial
context recovery
```

tidak boleh dipakai sebagai authorization authority sampai direfresh.

---

## 8. Zero Permissions Is Valid

Response:

```json
{
    "permissions": []
}
```

berarti:

```text
READY
+
ZERO EFFECTIVE PERMISSIONS
```

bukan:

```text
ERROR
```

Ini penting karena fail-closed tidak berarti semua zero-permission user dianggap mengalami system error.

---

## 9. Capability Loading Fails Closed

Ketika permission projection belum authoritative:

```text
protected navigation
protected route content
protected actions
```

tidak boleh diasumsikan allowed.

Contoh dilarang:

```text
show everything
↓
fetch permissions
↓
hide later
```

karena dapat menyebabkan unauthorized UI flash.

Canonical behavior:

```text
capabilities unresolved
↓
protected UI unavailable
↓
projection READY
↓
evaluate
```

---

# 10. Tenant vs Workspace Capability Scope

Tidak semua operation menggunakan authorization scope yang sama.

Frontend policy harus membedakan minimal:

```text
TENANT capability scope

WORKSPACE capability scope
```

### Tenant-scoped operation

Menggunakan:

```text
GET /authorization/capabilities
```

meskipun user saat ini sedang berada pada Organization Workspace.

### Workspace-scoped operation

Menggunakan:

```text
GET /authorization/workspace-capabilities
```

untuk selected organizational assignment.

Ini mencegah kesalahan:

```text
current Workspace exists
therefore every operation
must use Workspace permissions
```

---

## 11. Context Requirement and Authorization Scope Are Different

Route dapat memiliki dua metadata berbeda:

```text
Context Requirement
```

dan:

```text
Authorization Scope
```

Contoh konsep:

```text
Role Administration

context:
TENANT

authorization scope:
TENANT

requires:
core.roles.manage
```

sementara:

```text
Dormitory Unit Residents

context:
ORGANIZATIONAL

authorization scope:
WORKSPACE

requires:
dormitory.residents.view
```

Context menjawab:

> Apakah environment yang dibutuhkan sudah tersedia?

Permission menjawab:

> Apakah effective capability mengizinkan UX ini?

Keduanya tidak boleh digabung menjadi satu boolean ambigu.

---

# 12. Frontend-Owned Navigation Catalog

Backend tidak perlu menyediakan:

```text
GET /core/menu
```

yang berisi:

```text
React route
icon
sidebar group
label
component name
```

Presentation structure adalah frontend concern.

Navigation merupakan static application metadata yang kemudian disaring berdasarkan current capability projection.

---

# 13. Module-Owned Navigation

ADR-021 tetap berlaku.

Setiap business module memiliki navigation contribution sendiri.

Contoh konseptual:

```text
modules/academic/
    navigation/

modules/hr/
    navigation/

modules/dormitory/
    navigation/
```

Module mengekspos contribution melalui explicit public contract.

Application shell mengkomposisikannya.

---

# 14. No Giant Navigation Registry

Dilarang membangun satu file seperti:

```text
app/navigation.ts
```

yang akhirnya berisi detail seluruh:

```text
Academic
HR
Dormitory
Finance
Library
Attendance
...
```

`app` mengkomposisikan contribution tetapi tidak mengambil ownership business navigation.

---

# 15. Navigation Metadata

Conceptually navigation item dapat memiliki:

```text
id
label
route
contextRequirement
authorizationScope
requiredPermissions
children
presentation metadata
```

Exact TypeScript interface ditentukan pada TDD.

Permission metadata menggunakan canonical permission names.

---

# 16. Navigation Evaluation

Canonical algorithm:

```text
navigation catalog
      ↓
current context
      ↓
required capability projection READY?
      ↓
required permission satisfied?
      ↓
visible
```

Jika permission tidak tersedia:

```text
HIDDEN
```

bukan disabled.

---

# 17. Navigation Parent Visibility

Untuk hierarchy:

```text
Academic
├── Students
├── Grades
└── Subjects
```

jika user tidak mempunyai akses ke satupun child:

```text
Academic group
→ hidden
```

Jangan menampilkan empty navigation section.

Jika hanya Students accessible:

```text
Academic
└── Students
```

tetap valid.

---

# 18. Maximum Navigation Depth

PRD tetap mengunci:

```text
maximum visible sidebar hierarchy
= 2 levels
```

Authorization policy tidak boleh menghasilkan nested navigation tanpa batas.

Complex internal navigation harus diselesaikan pada module/page UX.

---

# 19. No Backend Menu Filtering

Backend capability projection mengirim:

```text
permission vocabulary
```

bukan:

```text
which React item should be visible
```

Ini menjaga separation:

```text
Backend
→ security semantics

Frontend
→ presentation semantics
```

---

# 20. Route Policy Ownership

Business module juga memiliki route requirement metadata.

Conceptual route:

```text
/academic/grades

context:
tenant

authorizationScope:
tenant

requires:
academic.grades.write
```

Route guard menggunakan metadata tersebut.

---

# 21. Route Guard Evaluation Order

Protected route secara konseptual mengevaluasi:

```text
1. Authentication READY?

2. Membership/Tenant READY?

3. Required organizational context available?

4. Correct capability projection READY?

5. Required permission satisfied?

6. Render route
```

Urutan tersebut penting.

Tidak masuk akal mengevaluasi Workspace permission jika Workspace sendiri belum valid.

---

# 22. Capability Loading on Direct Route

Jika user membuka URL langsung dan capability projection masih loading:

```text
do not render protected page
```

dan juga:

```text
do not immediately redirect as unauthorized
```

karena status authorization belum diketahui.

Gunakan route authorization loading state.

---

# 23. Direct Route Without Permission

Setelah capability menjadi READY dan permission tidak tersedia:

```text
route content
→ ACCESS DENIED
```

Navigation item memang hidden, tetapi direct URL masih harus menghasilkan controlled unauthorized UX.

Backend tetap akan menolak jika request tetap dicoba.

---

# 24. Context-Required Route

Jika route membutuhkan organizational Workspace tetapi current Workspace:

```text
TENANT
```

frontend tidak boleh:

```text
randomly select first Workspace
```

atau:

```text
infer Workspace from role
```

Route menampilkan context-required UX yang memungkinkan user memilih valid Workspace jika tersedia.

---

# 25. No Automatic Permission-Driven Context Switch

Permission requirement tidak boleh menyebabkan:

```text
current Tenant Workspace
↓
frontend scans all Workspaces
↓
find one with permission
↓
switch automatically
```

Workspace choice tetap explicit user/runtime context.

---

# 26. Action Authorization

Actions seperti:

```text
Create
Edit
Delete
Approve
Assign
Dispatch
```

menggunakan permission requirement yang sama.

Example:

```text
Delete Room

requires:
dormitory.rooms.manage
```

Jika permission absent:

```text
button absent
```

---

# 27. Hidden vs Disabled

Canonical policy dari PRD tetap:

### Authorization restriction

```text
required permission absent
↓
HIDDEN
```

### Business restriction

```text
permission exists
+
business condition blocks action
↓
DISABLED
+
reason
```

Contoh:

```text
Has student.delete
+
academic period closed
↓
Delete disabled:
"Academic period is closed."
```

---

# 28. Multiple Restrictions

Jika:

```text
permission absent
+
business condition false
```

authorization rule wins:

```text
HIDDEN
```

Jangan expose disabled action yang user sebenarnya tidak memiliki capability untuk gunakan.

---

# 29. Permission Checks Are UX Optimization

Frontend checks membantu:

```text
reduce confusing UI

prevent obviously denied requests

reduce unnecessary network calls

improve navigation
```

Tetapi bukan security boundary.

User dapat:

```text
modify JavaScript

construct HTTP request

tamper with client state
```

dan backend tetap harus menolak unauthorized operation.

---

# 30. No Per-Button Authorization API Calls

Dilarang:

```text
button render
↓
GET /can?permission=x
```

untuk setiap action.

Capability projection memang dibuat sebagai read model agar frontend dapat mengevaluasi banyak UI affordance secara lokal.

Ini menghindari:

```text
request amplification
latency
authorization flicker
```

---

# 31. Permission Evaluation Is Pure

Setelah capability projection READY:

```text
has(permission)
```

harus menjadi synchronous deterministic evaluation terhadap current permission set.

Tidak boleh melakukan hidden network call.

---

# 32. Permission Set Representation

Implementation sebaiknya menghasilkan efficient immutable lookup representation secara internal, misalnya:

```text
ReadonlySet<PermissionName>
```

daripada melakukan repeated linear search di banyak component.

Exact TypeScript representation tetap TDD concern.

Canonical source tetap generated capability response.

---

# 33. Unknown Backend Permissions

Jika backend kemudian mengirim:

```text
new.module.future.permission
```

yang belum dikenal frontend:

```text
do not crash
do not treat as wildcard
do not expose new UI magically
```

Frontend cukup mengabaikannya kecuali navigation/feature metadata memang menggunakan permission tersebut.

Ini memberikan forward compatibility.

---

# 34. Missing Permission

Sebaliknya jika frontend membutuhkan:

```text
academic.students.view
```

tetapi projection tidak mengandungnya:

```text
DENIED FOR UX
```

Frontend tidak boleh berasumsi bahwa backend lupa mengirim permission lalu tetap menampilkan feature.

---

# 35. Static Permission References

Frontend module boleh mendeklarasikan canonical permission identifiers yang dibutuhkannya.

Contoh konseptual:

```text
academicPermissions.gradesWrite
=
"academic.grades.write"
```

Namun frontend tidak membuat permission baru hanya untuk presentation convenience.

Canonical vocabulary harus berasal dari backend contract/module authorization design.

---

# 36. No Synthetic Wildcards

Dilarang:

```text
academic.*
dormitory.*
*.manage
```

kecuali backend suatu hari secara eksplisit mendefinisikan string tersebut sebagai canonical permission.

Saat ini backend permission projection menggunakan exact registered permission names.

Frontend mengikuti semantic tersebut.

---

# 37. Backend Role-Only Routes

Ada existing backend endpoint yang masih menggunakan middleware seperti:

```text
tenant.role:admin
```

misalnya current role-catalog access.

Frontend Foundation tetap tidak boleh merespons dengan:

```text
if role === "admin"
```

untuk menampilkan route tersebut.

### Consequence

Setiap future UI feature yang perlu capability-aware visibility harus mempunyai **canonical frontend-consumable permission/capability vocabulary**.

Jika sebuah backend route hanya dapat dievaluasi melalui role-name semantics dan belum mempunyai permission projection equivalent:

```text
backend contract alignment
```

diperlukan sebelum feature tersebut menjadi canonical capability-aware frontend feature.

Ini bukan blocker ADR-027 karena Identity & Access Administration UI belum diimplementasikan.

---

# 38. Capability Projection Is Not a Menu Contract

Backend permission seperti:

```text
academic.grades.write
```

tidak menentukan apakah frontend harus mempunyai:

```text
sidebar item
toolbar button
context menu
keyboard shortcut
```

Satu permission dapat digunakan pada beberapa UI affordance.

Presentation tetap frontend-owned.

---

# 39. Backend `AUTHORIZATION_DENIED`

Scenario:

```text
frontend capability
→ operation appears allowed

mutation request
↓
backend
↓
403 AUTHORIZATION_DENIED
```

Canonical behavior:

```text
operation fails safely
↓
NO automatic mutation retry
↓
invalidate/reload relevant capability projection
↓
re-evaluate current UI
```

Backend denial selalu menang.

---

# 40. One Reconciliation Cycle, Not Infinite Retry

Frontend tidak melakukan:

```text
403
↓
refresh capability
↓
retry mutation
↓
403
↓
refresh
↓
retry
...
```

Mutation yang ditolak:

```text
remains failed
```

Capability refresh hanya merekonsiliasi UX.

User harus memulai operation baru jika masih relevan.

---

# 41. Permission Still Present After 403

Ada situasi valid ketika:

```text
capability still contains permission
```

tetapi backend operation tetap denied.

Contohnya backend mungkin mempunyai additional:

```text
domain actor validation
resource ownership
business constraint
scoped object validation
```

yang tidak direpresentasikan oleh coarse capability projection.

Dalam kondisi ini:

```text
do not override backend
do not retry automatically
do not remove permission artificially
```

Tampilkan controlled operation denial.

Capability projection adalah UX affordance model, bukan bukti bahwa setiap resource instance pasti dapat dimutasi.

---

# 42. Route-Level Denial Reconciliation

Jika direct route API sendiri menghasilkan canonical authorization denial:

```text
refresh relevant capability projection
```

Kemudian:

### Permission hilang

```text
render Access Denied
```

### Permission masih ada

Tetap hormati backend denial untuk resource/operation tersebut.

Jangan memaksa page berjalan hanya karena cached/global capability terlihat sufficient.

---

# 43. Context Denial Is Different

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

tidak diproses sebagai ordinary missing permission.

Itu masuk ke ADR-024:

```text
stale Workspace recovery
```

Likewise:

```text
AUTHENTICATION_REQUIRED
```

masuk ke session/auth recovery.

Central API normalization dari ADR-025 mempertahankan distinction tersebut.

---

# 44. Capability Refresh Triggers

Capability projection harus direfresh ketika:

```text
Tenant switch commits

Workspace switch commits

Workspace stale recovery occurs

backend AUTHORIZATION_DENIED suggests
projection may no longer be current

known authorization administration
changes current context
```

Tidak diperlukan high-frequency polling.

Default:

```text
capability polling = OFF
```

---

# 45. Old Context Capability Is Never Reused

Example:

```text
Tenant A
permissions = [dormitory.rooms.manage]
```

kemudian:

```text
Tenant B
```

Frontend tidak boleh menampilkan Delete Room pada Tenant B menggunakan projection Tenant A sambil menunggu B.

Protected UI:

```text
fails closed
```

sampai B projection authoritative.

---

# 46. Workspace Capability Isolation

Likewise:

```text
Workspace X
permission = dormitory.rooms.manage
```

tidak memberikan capability tersebut pada:

```text
Workspace Y
```

bahkan di Tenant yang sama.

ADR-026 query partitions dan ADR-024 workspace generations menjadi correctness boundary.

---

# 47. Navigation During Context Transition

Ketika Tenant/Workspace sedang switching:

```text
authorization-dependent navigation
```

tidak boleh menerima input yang akan menjalankan business actions menggunakan old context.

Shell dapat tetap terlihat, tetapi affordances yang tergantung current authorization menjadi transition-safe/non-interactive sampai commit.

---

# 48. Navigation After Workspace Switch

Setelah Workspace switch:

```text
recompute visible navigation
```

menggunakan projection target.

Jika current route tidak lagi tersedia:

```text
navigate to safe route
```

sesuai ADR-024.

---

# 49. Stable Navigation Layout

Hiding unauthorized nodes sebaiknya tidak membuat shell kehilangan structural consistency secara ekstrem.

Module grouping dan ordering tetap berasal dari static catalog.

Capabilities hanya menentukan participation/visibility.

---

# 50. Authorization Policy Ownership

Shared evaluation primitives tinggal pada:

```text
platform/authorization
```

Business module memiliki:

```text
its permission requirements
navigation metadata
route metadata
action policy metadata
```

Platform tidak boleh memiliki giant registry yang mengetahui seluruh business permissions.

---

# 51. Business Module Example

Conceptually:

```text
Dormitory public contract
      │
      ├── navigation contribution
      │      requires dormitory.rooms.view
      │
      └── route contribution
             requires dormitory.rooms.view
```

Application composes contribution.

Platform authorization evaluates permission.

Dormitory tidak membangun authorization engine sendiri.

---

# 52. No Client Role Store

Frontend tidak membutuhkan:

```text
currentRole
```

sebagai canonical runtime authorization state.

Role catalog dan role assignment dapat tetap ada sebagai **administration domain data**.

Tetapi:

```text
administrating roles
≠ authorizing UI by role name
```

---

# 53. Role Management UI

Future Role Administration dapat menampilkan:

```text
roles
permissions
assignments
```

karena itu adalah domain data yang sedang dikelola.

Namun shell/route authorization untuk membuka fitur tersebut tetap harus didasarkan pada canonical effective permission/capability contract yang sesuai.

---

# 54. Feature Flags

Jika feature flags ditambahkan kemudian:

```text
feature flag
AND
capability
```

dapat digunakan.

Tetapi:

```text
feature flag
≠ authorization
```

A disabled rollout flag dapat menyembunyikan feature.

An enabled rollout flag tidak dapat memberi permission.

---

# 55. Business Conditions

Likewise:

```text
capability
AND
business condition
```

mungkin diperlukan untuk action.

Contoh:

```text
permission:
payment.approve

business:
payment.status === PENDING
```

Authorization layer hanya menjawab permission.

Domain layer menjawab status.

---

# 56. Accessibility

Hidden unauthorized controls tidak boleh meninggalkan:

```text
focusable invisible elements
or
orphaned accessible labels
```

Disabled business controls harus menyampaikan alasan secara accessible, tidak hanya melalui color.

Route denial state juga harus mempunyai semantic heading/focus behavior yang benar.

---

# 57. No Security Through Obscurity Claim

Navigation hiding membantu UX, bukan perlindungan data.

EduCore tidak menyatakan:

```text
route hidden
therefore secure
```

Security tetap:

```text
Laravel middleware/services
+
canonical authorization
+
resource ownership checks
```

---

# 58. Observability

Safe authorization UX events dapat mencakup:

```text
capability_projection_loaded

capability_projection_failed

capability_projection_mismatch

authorization_denied_reconciled

protected_route_denied
```

Hindari high-volume telemetry untuk setiap:

```text
hasPermission()
```

render call.

Itu akan menciptakan noise dan performance cost.

---

# 59. Permission Data and Privacy

Permission names bukan bearer secrets.

Namun full permission sets tidak perlu dikirim ke analytics pada setiap event.

Observability cukup memakai minimal metadata yang relevan.

---

# 60. Testing Requirements

Implementation harus membuktikan minimal:

```text
1. runtime UI authorization uses permission names.

2. role-name checks are not used for authorization UX.

3. token role/permission claims are never consumed.

4. is_global_superadmin does not bypass permission evaluation.

5. Tenant capability scope must match active Membership/Tenant.

6. Workspace capability scope must match active assignment.

7. mismatched projection fails closed.

8. unresolved capability does not flash protected UI.

9. empty permissions is READY with zero permissions.

10. unknown backend permissions do not crash frontend.

11. exact permission matching is used.

12. synthetic permission wildcard is not inferred.

13. navigation item is hidden when required capability is absent.

14. empty navigation groups are hidden.

15. direct protected route is denied when permission absent.

16. direct route waits while authorization is unresolved.

17. organizational route does not auto-select arbitrary Workspace.

18. authorization-controlled action is hidden.

19. business-state-controlled action is disabled with reason.

20. Tenant-scoped policy uses Tenant capability projection.

21. Workspace-scoped policy uses Workspace capability projection.

22. old Tenant capabilities cannot authorize new Tenant UI.

23. old Workspace capabilities cannot authorize new Workspace UI.

24. AUTHORIZATION_DENIED does not logout.

25. denied mutation is never automatically retried.

26. backend denial triggers bounded capability reconciliation.

27. permission still present after backend denial
    does not override backend.

28. ORGANIZATIONAL_CONTEXT_DENIED routes to Workspace recovery.

29. module navigation remains module-owned.

30. business modules cannot build independent role-based guards.
```

---

# 61. Critical Superadmin Test

One test deserves explicit architectural status.

Given:

```text
is_global_superadmin = true
```

and Workspace projection:

```text
permissions = []
```

frontend must evaluate:

```text
has("dormitory.rooms.manage")
= false
```

It MUST NOT evaluate:

```text
true because global superadmin
```

This directly protects alignment with current backend workspace semantics.

---

# 62. Architecture Enforcement

Lint/static analysis should detect where practical:

```text
role ===
role.name ===
isGlobalSuperadmin ? allow
permission wildcard evaluation
```

inside authorization policy code.

Not every textual use of `role` is forbidden because role-administration screens legitimately manipulate role data.

The rule targets:

```text
authorization decisions
```

rather than domain-management data.

---

# 63. Architectural Invariants

Jika ADR-027 diterima:

```text
Frontend runtime authorization vocabulary
= canonical permission names

Role-name authorization
= FORBIDDEN

Token permission claims
= FORBIDDEN

is_global_superadmin authorization bypass
= FORBIDDEN

Permission matching
= exact

Synthetic wildcard
= FORBIDDEN

Frontend permission inheritance
= FORBIDDEN

Backend
= final authorization authority

Capability projection
= UX/read-model authority only

Navigation catalog
= frontend-owned

Navigation ownership
= module-owned

Backend menu tree
= NOT REQUIRED

Route guards
= UX/performance guard

Capability unresolved
= fail closed

permissions=[]
= READY / zero permissions

Authorization restriction
= HIDDEN by default

Business restriction
= DISABLED + explanation

Direct unauthorized route
= controlled Access Denied

Context-required route
= explicit context UX

Automatic Workspace selection
= FORBIDDEN

Per-button authorization API
= FORBIDDEN

AUTHORIZATION_DENIED
= backend wins

Denied mutation retry
= FORBIDDEN

Capability reconciliation
= bounded

Tenant/Workspace projection isolation
= REQUIRED
```

---

# 64. Consequences

### Positive

- Frontend dan backend memakai authorization vocabulary yang konsisten.
- Tidak ada role-name coupling pada UI.
- Scoped authorization tetap dapat berkembang tanpa rewrite frontend berdasarkan role.
- Navigation tetap modular dan presentation-owned.
- Direct URL UX aman dan deterministic.
- `is_global_superadmin` tidak menciptakan bypass yang berbeda dari backend.
- Permission changes dapat direkonsiliasi tanpa token refresh.
- Business state tidak dicampur dengan authorization state.
- Tidak diperlukan request authorization per button.

### Costs

- Setiap feature harus mendeklarasikan permission requirement dengan disiplin.
- Backend route yang hanya memiliki role-based semantics belum otomatis cocok dengan capability-aware frontend.
- Route/navigation metadata memerlukan architecture contract yang jelas.
- Capability scope Tenant vs Workspace harus dipahami module developer.
- Race/context tests menjadi mandatory.

Biaya tersebut diterima karena implicit authorization rules jauh lebih sulit dipelihara dan lebih berisiko pada aplikasi multi-tenant.

---

# 65. Explicit Non-Decisions

ADR-027 belum menentukan:

```text
exact TypeScript policy interfaces

exact React hook names

exact Access Denied component

exact permission Set implementation

exact navigation metadata syntax

exact route configuration syntax

safe-route algorithm details

icons/menu rendering library

feature flag provider
```

Hal tersebut masuk TDD/ADR routing berikutnya.

---

# 66. Follow-Up Dependency

Setelah authorization UX terkunci, pertanyaan selanjutnya adalah:

```text
How are routes composed,
lazy-loaded,
guarded,
and split into bundles
without breaking module boundaries?
```

Maka langkah berikutnya:

```text
ADR-028
Routing & Code-Splitting Strategy
```

ADR-028 akan memakai:

```text
ADR-021
module public contracts

ADR-024
Workspace route behavior

ADR-026
state/cache boundaries

ADR-027
route/navigation authorization metadata
```

untuk mengunci route composition dan lazy-loading architecture.

---

# ADR-027 Proposed State

```text
ADR-027 — Capability-Aware Navigation
& Authorization UX

Status:
🔒 ACCEPTED / LOCKED

Runtime authorization:
permissions[]

Role-name checks:
❌ FORBIDDEN

is_global_superadmin bypass:
❌ FORBIDDEN

Frontend permission inheritance:
❌ FORBIDDEN

Permission wildcard inference:
❌ FORBIDDEN

Navigation:
frontend-owned + module-owned

Backend menu tree:
❌ NOT REQUIRED

Capability unresolved:
FAIL CLOSED

No capability:
HIDDEN

Business restriction:
DISABLED + EXPLANATION

Direct URL without capability:
ACCESS DENIED

Tenant authorization:
Tenant capability projection

Workspace authorization:
Workspace capability projection

Backend 403:
FINAL AUTHORITY

403 mutation retry:
❌ FORBIDDEN

Capability reconciliation:
✅ BOUNDED

Old-context capability reuse:
❌ FORBIDDEN
```
