Baik. **FE-4 kita lock**.

Sekarang kita lanjut hanya ke **FE-5 — Workspace / Organizational Context PRD**. Di tahap ini kita mengunci semantics workspace, discovery, selection, restoration, transport header, stale-context recovery, serta hubungannya dengan capability projection. Belum masuk implementasi React, TanStack Query, atau ADR.

# FE-5 — Workspace / Organizational Context PRD

## 1. Tujuan & Konsep Fundamental

Workspace EduCore **bukan domain entity baru**.

Kita tidak akan membuat asumsi:

```text
Workspace
→ model
→ table
→ CRUD
```

Sebaliknya:

```text
Workspace
=
frontend/read projection
+
runtime navigation context
```

yang diturunkan dari canonical organizational topology:

```text
Tenant
  │
  └── Organization
        │
        └── OrganizationUnit
```

Canonical discovery tetap:

```http
GET /api/v1/user/my-workspaces
```

dan Organizational Assignment hanya menjadi locator yang diverifikasi backend.

---

# 2. Tiga Jenis Workspace

Saya merekomendasikan FE-5 mengunci tiga jenis context.

```text
Workspace
│
├── TENANT
│
├── ORGANIZATION
│
└── ORGANIZATION_UNIT
```

Contoh:

```text
Yayasan Al-Hikmah                    TENANT
│
├── SMA Al-Hikmah                    ORGANIZATION
│   ├── Akademik                     ORGANIZATION_UNIT
│   ├── Keuangan                     ORGANIZATION_UNIT
│   └── Kesiswaan                    ORGANIZATION_UNIT
│
└── Pesantren Al-Hikmah              ORGANIZATION
    ├── Asrama Putra                 ORGANIZATION_UNIT
    └── Asrama Putri                 ORGANIZATION_UNIT
```

Ini cukup untuk foundation saat ini.

Kita **tidak menambahkan**:

```text
Department
Campus
Branch
Dormitory
Class
Building
```

sebagai level Core baru.

Business domain seperti Dormitory tetap downstream domain.

---

# 3. Tenant-Level Workspace

Tenant-level workspace adalah context paling luas.

Secara konseptual:

```text
type = TENANT

organizational_assignment_id = null
organization_id = null
organization_unit_id = null
```

Untuk request tenant-level:

```http
Authorization: Bearer <credential>
```

tanpa:

```http
X-EduCore-Organizational-Assignment-Id
```

Ini penting.

Jangan mengirim:

```text
X-EduCore-Organizational-Assignment-Id: null
```

atau:

```text
X-EduCore-Organizational-Assignment-Id: tenant-id
```

Header tersebut hanya valid untuk verified organizational assignment.

---

# 4. Organization Workspace

Contoh:

```text
Tenant
Yayasan Al-Hikmah

Workspace
SMA Al-Hikmah
```

Workspace state memiliki locator:

```text
organizational_assignment_id
```

dan request yang memang memakai current organizational context membawa:

```http
X-EduCore-Organizational-Assignment-Id: <assignment-uuid>
```

Frontend tidak menentukan sendiri bahwa:

```text
organization_id = X
→ user authorized
```

Backend melakukan resolution dari Assignment.

---

# 5. OrganizationUnit Workspace

Misalnya:

```text
SMA Al-Hikmah
└── Akademik
```

Frontend masih mengirim satu locator yang sama jenisnya:

```http
X-EduCore-Organizational-Assignment-Id: <unit-assignment-id>
```

Bukan kombinasi:

```http
X-Organization-Id:
X-Organization-Unit-Id:
```

Backend memperoleh canonical:

```text
Membership
Tenant
Organization
OrganizationUnit
```

dari verified assignment.

Dengan demikian kita menghindari client-built authorization context.

---

# 6. Workspace Discovery

Canonical lifecycle setelah authenticated context siap:

```text
/auth/me
   ↓
Membership + Tenant verified
   ↓
GET /user/my-workspaces
   ↓
workspace projection
```

Frontend hanya menampilkan workspace yang diberikan backend.

Jangan:

```text
GET all organizations
+
GET all units
+
frontend determines which ones user can access
```

Karena hal itu menduplikasi authorization/discovery logic.

---

# 7. Workspace Discovery Response

Frontend sebaiknya membutuhkan projection minimal seperti:

```text
WorkspaceOption
│
├── type
├── label
├── organizational_assignment_id?
├── organization_id?
├── organization_unit_id?
└── hierarchy metadata
```

Conceptual:

```json
{
  "type": "ORGANIZATION_UNIT",
  "organizational_assignment_id": "...",
  "organization_id": "...",
  "organization_unit_id": "...",
  "label": "Akademik"
}
```

Namun penting:

```text
organization_id
organization_unit_id
```

adalah display/domain metadata.

Yang menjadi transport locator untuk current organizational context tetap:

```text
organizational_assignment_id
```

---

# 8. Workspace Selection Bukan Server-Side Switch

Ini perbedaan besar dari Tenant switch.

### Tenant switch

```text
Membership switch
→ API mutation
→ new credential
→ new Tenant
```

### Workspace switch

```text
Select existing projection
→ change frontend runtime context
→ no credential exchange
```

Kita **tidak membutuhkan**:

```http
POST /switch-workspace
```

hanya untuk membuat server menyimpan current Organization.

Workspace tidak menjadi mutable session state backend.

---

# 9. Workspace Selection State Machine

Saya menyarankan explicit state:

```text
UNRESOLVED
    │
    ▼
DISCOVERING
    │
    ▼
READY
    │
    ├── select workspace
    ▼
SWITCHING
    │
    ▼
READY
```

Error paths:

```text
DISCOVERING
    ↓
ERROR

SWITCHING
    ↓
STALE / DENIED
    ↓
RECOVERING
```

Kita tidak cukup memakai:

```typescript
workspace = object | null;
```

karena:

```text
null
```

bisa berarti banyak hal:

```text
Tenant-level context?
Belum load?
Discovery gagal?
Context dihapus?
```

Itu harus dibedakan secara konsep.

---

# 10. Tenant-Level ≠ Missing Workspace

Ini subtle tetapi penting.

Tenant-level merupakan **valid context**.

Jadi:

```text
workspace.type = TENANT
```

berbeda dengan:

```text
workspace = UNRESOLVED
```

Secara transport keduanya mungkin sama-sama tidak mengirim organizational header, tetapi secara application semantics berbeda.

Ini mencegah bug:

```text
if (!assignmentId) {
   workspaceNotLoaded();
}
```

padahal Tenant-level memang sengaja memiliki no assignment.

---

# 11. Default Workspace

Setelah **fresh Tenant switch**, FE-4 sudah menetapkan:

```text
Tenant-level safe context
```

sebagai default Foundation v1.

Saya mempertahankan keputusan itu.

```text
Membership B selected
        ↓
Tenant B bootstrap
        ↓
my-workspaces
        ↓
TENANT workspace
        ↓
capabilities
        ↓
dashboard
```

Ini deterministic dan tidak bergantung ordering response.

---

# 12. Workspace Setelah Normal Login

Untuk Foundation v1 saya juga merekomendasikan:

```text
Fresh login
→ Tenant-level context
```

kecuali product flow di masa depan mempunyai explicit deep-link/context intent yang sudah diverifikasi.

Mengapa?

Karena lebih predictable daripada:

```text
login
→ magically enter last Organization from 2 weeks ago
```

ketika assignment tersebut mungkin sudah berubah.

---

# 13. Workspace Restoration Setelah Browser Reload

Reload berbeda dari login/switch.

Misalnya sebelum refresh:

```text
Tenant A
Workspace:
SMA A / Akademik
```

Frontend boleh mencoba mengingat locator per-tab.

Tetapi restoration flow harus:

```text
Browser reload
      ↓
restore authentication
      ↓
/auth/me
      ↓
GET /my-workspaces
      ↓
does saved assignment still exist?
      │
  ┌───┴───┐
 yes      no
  │        │
  ▼        ▼
restore   TENANT
workspace safe context
```

Jadi persisted workspace hanya:

> **restoration hint**

bukan authority.

---

# 14. Jangan Mengembalikan Workspace Berdasarkan ID Saja

Jangan:

```text
saved assignment ID exists
→ immediately inject header
```

sebelum workspace discovery selesai.

Correct:

```text
saved assignment_id
      +
fresh my-workspaces projection
      ↓
matching valid workspace
      ↓
restore
```

Kalau tidak ditemukan:

```text
discard saved assignment
```

Ini menangani assignment yang sudah inactive/deleted.

---

# 15. Workspace Persistence Harus Per-Tab

Requirement multi-tab tetap berlaku.

```text
Tab A
Tenant A
SMA / Academic

Tab B
Tenant A
SMA / Finance
```

harus valid.

Maka workspace state tidak boleh disinkronkan global secara otomatis.

```text
Tab A workspace switch
≠
Tab B workspace switch
```

Detail storage mechanism tetap ADR concern.

---

# 16. Workspace Selector UX

Untuk user dengan workspace sedikit:

```text
Workspace ▼

✓ Tenant Home
  SMA / Academic
  SMA / Finance
```

cukup.

Tetapi EduCore harus siap terhadap institusi besar.

Contoh:

```text
20 Organizations
×
15 Organizational Units
=
300 selectable contexts
```

Jadi requirement P0:

> Workspace selector harus mampu mendukung search dan hierarchy.

Contoh UX:

```text
Switch workspace

[ Search workspace... ]

Tenant
  Yayasan Al-Hikmah

SMA Al-Hikmah
  Academic
  Finance
  Student Affairs

Pesantren Al-Hikmah
  Dormitory
  Administration
```

---

# 17. Jangan Tampilkan UUID ke Pengguna

UI menampilkan:

```text
SMA Al-Hikmah
Academic
```

bukan:

```text
019c3863-...
```

`organizational_assignment_id` adalah technical locator.

Tidak boleh menjadi primary user-facing identity.

---

# 18. Workspace Switch Lifecycle

Misalnya current:

```text
Tenant A
Workspace SMA / Academic
```

user memilih:

```text
SMA / Finance
```

Flow:

```text
User selects Finance
       ↓
WORKSPACE_SWITCHING
       ↓
new assignment selected
       ↓
invalidate/isolate workspace-scoped data
       ↓
load workspace capabilities
       ↓
validate current navigation
       ↓
READY
```

Tidak ada:

```text
new token
```

Tidak ada:

```text
database mutation
```

Tidak ada:

```text
server-side current workspace session
```

---

# 19. Capability Loading Berdasarkan Workspace

Current backend memiliki dua conceptual capability projections.

### Tenant context

```http
GET /api/v1/core/authorization/capabilities
```

### Organizational workspace

```http
GET /api/v1/core/authorization/workspace-capabilities
X-EduCore-Organizational-Assignment-Id: ...
```

Jadi:

```text
TENANT
   ↓
tenant capability projection

ORGANIZATION
   ↓
workspace capability projection

ORGANIZATION_UNIT
   ↓
workspace capability projection
```

Workspace-capability backend memang diuji sebagai context yang memerlukan verified organizational context, dan authorization tests membuktikan scoped inheritance/fail-closed semantics.

---

# 20. Capability State Selama Workspace Switch

Kita tidak boleh melakukan:

```text
Workspace Finance
+
Capabilities Academic lama
```

meskipun hanya beberapa milidetik sebagai interactive state.

Saat workspace transition dimulai:

```text
Capabilities
→ STALE / UNKNOWN
```

Kemudian:

```text
new workspace
      ↓
load capabilities
      ↓
compose navigation/actions
```

baru state dianggap `READY`.

---

# 21. Scoped Authorization Semantics yang Harus Dihormati UX

Backend rules saat ini:

```text
Tenant-wide role
→ effective di seluruh Tenant
→ termasuk saat berada di Organization/Unit context
```

```text
Organization role
→ effective di Organization tersebut
→ inherited ke unit di bawahnya
```

```text
OrganizationUnit role
→ exact unit only
→ tidak naik ke Organization
→ tidak pindah ke sibling unit
```

Frontend tidak perlu menghitung algoritma tersebut sendiri.

Frontend menerima hasil projection.

Tetapi desain UX harus compatible dengan hasil tersebut.

---

# 22. Penting: Workspace Bukan “Permission Filter Mutlak”

Misalnya seseorang punya:

```text
Tenant-wide:
finance.report.view

Organization SMA:
academic.students.view
```

Ketika ia memilih workspace:

```text
SMA
```

tenant-wide capability secara backend bisa tetap efektif.

Jadi kita jangan membuat assumption:

```text
Workspace SMA
→ only SMA-specific permissions may exist
```

Workspace lebih tepat menjadi:

```text
selected operating/presentation context
+
scoped authorization context
```

bukan sandbox yang otomatis menghapus semua tenant-level privileges.

Ini sesuai dengan foundation scoped authorization yang sudah dibuktikan oleh regression.

---

# 23. Presentation Policy vs Authorization Policy

Ini distinction yang sangat penting.

Misalnya seseorang:

```text
Tenant-wide Finance Admin
+
Teacher at SMA
```

Saat workspace:

```text
SMA / Academic
```

frontend mungkin ingin memprioritaskan:

```text
Academic
Students
Classes
Assessment
```

daripada memenuhi sidebar dengan seluruh Finance actions.

Tetapi kalau tenant-level Finance capability tetap effective, kita tidak boleh mengklaim bahwa Finance authorization hilang.

Maka:

```text
Backend Capability
        ↓
what user CAN access

Workspace Presentation
        ↓
what UI should emphasize/show in current operating context
```

FE-6 akan merinci rule ini.

---

# 24. Business Server-State Scope

Kita sekarang dapat mengunci hierarchy cache:

```text
Server State
│
├── Tenant-scoped
│
└── Workspace-scoped
```

Contoh:

```text
Tenant configuration
→ Tenant scoped

Organization academic students
→ Workspace scoped

Dormitory resources for Organization
→ Workspace scoped
```

Query identity konseptual:

```text
resource
+
tenant/membership identity
+
workspace assignment when relevant
+
parameters
```

Ini mencegah:

```text
Academic workspace data
```

muncul ketika user pindah ke Finance workspace.

---

# 25. Stale Requests Setelah Workspace Switch

Scenario:

```text
Workspace A
GET /students
        │
        │ slow
        ▼

User switches Workspace B
        │
        ▼

GET /students for B
        │
        ▼

old A response completes
```

Old response tidak boleh mengganti UI Workspace B.

Rule sama seperti Tenant switch:

> Response dari superseded workspace context tidak boleh memperbarui active-context UI.

Implementasinya nanti dapat menggunakan:

```text
query keys
request cancellation
context generation
stale result rejection
```

tetapi invariannya kita lock sekarang.

---

# 26. Mutations Saat Workspace Transition

Saat:

```text
WORKSPACE_SWITCHING
```

mutation context-sensitive harus disabled.

Contoh:

```text
Create Student
Update Employee
Check-in Resident
Submit Grade
```

Tujuannya sama:

```text
mutation must have unambiguous active workspace
```

---

# 27. Current Route Setelah Workspace Switch

Workspace switch lebih ringan daripada Tenant switch.

Misalnya:

```text
SMA / Academic
/academic/students
```

berpindah ke:

```text
SMA / Finance
```

Flow:

```text
switch workspace
      ↓
reload capabilities
      ↓
is current route still valid?
```

Jika yes:

```text
remain route
+
reload data
```

Jika no:

```text
redirect /dashboard
```

Jadi berbeda dengan Tenant switch yang selalu kembali ke safe landing.

---

# 28. Route Validity Tidak Berdasarkan Menu Visibility Saja

Kita jangan membuat:

```text
route absent from sidebar
→ automatically forbidden
```

Karena route bisa valid tetapi sengaja tidak menjadi primary navigation.

Validitas route harus didasarkan pada:

```text
route metadata
+
capability projection
+
context requirement
```

Backend tetap final authorization authority.

---

# 29. Missing Organizational Context

Ada endpoint yang memang membutuhkan Organization/Unit context.

Jika user sedang:

```text
TENANT workspace
```

kemudian mencoba feature yang membutuhkan organizational context, backend dapat menolak karena context tidak tersedia.

Product UX sebaiknya tidak langsung menampilkan generic:

```text
403 Forbidden
```

jika penyebabnya adalah missing required workspace.

Lebih baik:

```text
This feature requires an organization workspace.

[ Select workspace ]
```

Canonical machine-readable error seperti:

```text
ORGANIZATIONAL_CONTEXT_REQUIRED
```

dapat digunakan untuk branching UX. Source contract sebelumnya memang mendefinisikan missing organizational context sebagai fail-closed condition.

---

# 30. Stale Workspace / Assignment Revoked

Scenario:

```text
User currently:
SMA / Academic

Admin deactivates assignment
```

request berikutnya mengembalikan:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

Frontend harus:

```text
receive context-denied
      ↓
STOP retrying request with stale assignment
      ↓
clear current organizational workspace
      ↓
GET /my-workspaces
      ↓
remove stale saved assignment
      ↓
fallback TENANT
      ↓
reload Tenant capabilities
      ↓
dashboard / safe state
```

Ini sudah konsisten dengan frontend transport foundation sebelumnya.

---

# 31. Jangan Infinite Retry Stale Context

Ini P0.

Jangan:

```text
request
→ context denied
→ retry
→ context denied
→ retry
→ ...
```

Central API handling nantinya harus mengenali:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

sebagai context invalidation event.

Maximum canonical recovery:

```text
1. invalidate
2. rediscover
3. safe fallback
```

Setelah itu, error ditampilkan jika recovery gagal.

---

# 32. Malformed Assignment

Jika application state menghasilkan malformed assignment ID, itu lebih dekat ke:

```text
frontend state corruption / bug
```

daripada normal user denial.

Frontend harus:

```text
discard invalid workspace state
→ return Tenant context
→ telemetry safe error
```

Tidak mencoba “memperbaiki UUID”.

---

# 33. Workspace Menghilang dari Discovery tetapi Current Request Belum Gagal

Jika `/my-workspaces` refresh menunjukkan current workspace sudah tidak tersedia:

```text
current workspace
→ STALE
```

Frontend sebaiknya proactively melakukan recovery yang sama:

```text
clear assignment
→ Tenant context
→ refresh capabilities
```

Kita tidak perlu menunggu mutation pertama gagal.

---

# 34. Empty Workspace Set

Secara conceptual Tenant-level workspace tetap tersedia selama authenticated Tenant valid.

Jadi user tanpa organizational assignments tidak berarti broken state.

Mereka dapat memiliki:

```text
Tenant Home
```

saja.

Example:

```text
Workspace

✓ Yayasan Al-Hikmah
```

No Organization assignment adalah legitimate state.

---

# 35. Workspace Name Changes

Jika Organization berubah nama:

```text
SMA Lama
→ SMA Nusantara
```

frontend tidak boleh menjadikan cached label sebagai canonical identity.

Setelah workspace discovery refresh:

```text
label updated
```

Locator/domain ID tetap menentukan identity.

Ini alasan lain saved workspace sebaiknya menyimpan identifier minimal, bukan seluruh projection selamanya.

---

# 36. Workspace Deletion / Reorganization

Kalau Organization/Unit direstrukturisasi:

```text
old assignment unavailable
```

frontend tidak mencoba mapping berdasarkan label:

```text
"Academic" lama
→ cari "Academic" baru
```

Tidak aman.

Fallback:

```text
Tenant context
```

dan pengguna memilih context baru.

---

# 37. Deep Links

Misalnya nanti ada URL:

```text
/academic/students/123
```

Workspace **tidak saya rekomendasikan disisipkan sebagai raw assignment UUID di URL foundation** seperti:

```text
?workspace=019c...
```

secara default.

Alasannya:

- context menjadi mudah bocor ke history/log/referrer;
- saved link bisa stale;
- assignment bukan resource identity bagi user;
- per-tab runtime state sudah memadai.

Namun deep-linking lintas workspace dapat menjadi ADR/product enhancement kemudian jika kebutuhan bisnis konkret muncul.

---

# 38. Header Injection Harus Centralized

Walaupun belum coding, requirement architecture sudah bisa kita tetapkan:

Business component tidak boleh melakukan:

```typescript
headers: {
  'X-EduCore-Organizational-Assignment-Id': currentWorkspace.id
}
```

di setiap page.

Transport semantics harus dimiliki oleh centralized API layer.

Conceptual:

```text
Feature
   ↓
API client
   ↓
Auth credential injection
   ↓
Workspace locator injection if required/current
   ↓
HTTP
```

Ini mencegah 30 module mengimplementasikan header logic masing-masing.

---

# 39. Tetapi Header Tidak Boleh Dikirim Membabi Buta

Kita juga jangan membuat:

```text
every API request
→ organizational header
```

secara global tanpa mempertimbangkan semantics.

Contoh:

```text
/auth/me
/my-memberships
```

tidak membutuhkan organizational context.

ADR API client nanti harus mampu membedakan request yang:

```text
TENANT_CONTEXT
```

dan:

```text
ORGANIZATIONAL_CONTEXT
```

Daripada semua request mendapatkan semua header.

---

# 40. Context Requirement sebagai Route/API Metadata

Ini menghasilkan kandidat design yang kuat.

Conceptual route:

```text
Route:
Academic Students

requires:
AUTHENTICATED
TENANT
ORGANIZATIONAL_WORKSPACE
capability: student.view
```

Sedangkan:

```text
Route:
Dashboard

requires:
AUTHENTICATED
TENANT
organizational workspace optional
```

Kita belum mengunci syntax TypeScript-nya, tetapi requirement metadata ini layak dibawa ke ADR routing.

---

# 41. Workspace Visual Context

Topbar harus membuat current workspace mudah dikenali.

Contoh:

```text
Institution
Yayasan Al-Hikmah

Workspace
SMA › Academic
```

Jangan hanya:

```text
Academic
```

karena bisa terdapat beberapa Organization dengan unit bernama sama.

Pada compact mobile UI boleh disingkat visualnya, tetapi accessible/full context tetap tersedia.

---

# 42. Context Change Confirmation

Untuk normal workspace switch saya **tidak menyarankan modal confirmation setiap kali**.

Itu terlalu mengganggu.

Tetapi jika page mempunyai:

```text
unsaved form changes
```

workspace switch harus melalui generic unsaved-changes protection:

```text
You have unsaved changes.

Discard changes and switch workspace?
```

Ini bukan workspace-specific confirmation, melainkan global navigation protection.

Detailnya masuk FE-7.

---

# 43. Workspace Switch Performance

Karena tidak ada credential exchange:

```text
workspace switch
```

seharusnya lebih ringan daripada Tenant switch.

Product expectation:

```text
select workspace
→ transition indicator
→ capabilities + required data refresh
→ usable
```

Tidak perlu full browser reload.

Dan jelas tidak perlu Laravel authentication ulang.

---

# 44. Workspace Capability Cache

Capabilities dapat dicache berdasarkan context identity.

Conceptual:

```text
tenant capabilities:
(Tenant A)

workspace capabilities:
(Tenant A, Assignment X)

workspace capabilities:
(Tenant A, Assignment Y)
```

Tetapi jika authorization berubah di backend, server tetap final authority.

Capability cache adalah optimization/UX projection.

FE-6 akan menentukan freshness/revalidation policy lebih detail.

---

# 45. Security Boundary

Header:

```http
X-EduCore-Organizational-Assignment-Id
```

bukan sensitive credential seperti bearer token.

Tetapi jangan salah artikan:

```text
knowing assignment ID
≠
having authorization
```

Backend melakukan validasi terhadap current:

```text
Membership
Tenant
Assignment
Organization
OrganizationUnit
active state
```

dan regression yang tersedia memang membuktikan missing, malformed, denied, stale, cross-membership, serta sibling scope fail closed.

---

# 46. P0 / P1 Classification

| Requirement                                     |  Priority |
| ----------------------------------------------- | --------: |
| `my-workspaces` canonical discovery             |        P0 |
| TENANT / ORGANIZATION / UNIT workspace types    |        P0 |
| Workspace remains read/runtime projection       |        P0 |
| Tenant-level context without assignment header  |        P0 |
| Assignment ID as locator only                   |        P0 |
| Client-side workspace selection                 |        P0 |
| No token exchange for workspace                 |        P0 |
| Hierarchical/searchable selector                |        P0 |
| Per-tab workspace isolation                     |        P0 |
| Workspace-scoped capability refresh             |        P0 |
| Workspace-scoped cache isolation                |        P0 |
| Stale-request protection                        |        P0 |
| Stale-assignment recovery                       |        P0 |
| Tenant fallback                                 |        P0 |
| Current-route revalidation                      |        P0 |
| Central context transport                       |        P0 |
| Workspace reload restoration after verification |        P0 |
| Last workspace preference across new sessions   |        P1 |
| Workspace deep links                            | P1/Future |
| Favorite workspaces                             |    Future |
| Recently used workspaces                        |    Future |

---

# 47. Non-Negotiable FE-5 Guardrails

Saya merekomendasikan kita lock:

```text
1. Workspace is not a Core persistence entity.

2. Workspace is a frontend/read/runtime projection.

3. Canonical workspace types are:
   TENANT, ORGANIZATION, ORGANIZATION_UNIT.

4. Tenant-level context does not send an
   organizational-assignment header.

5. Organization/Unit context uses only the canonical
   OrganizationalAssignment locator.

6. Assignment ID never becomes authorization authority.

7. Workspace switching is client-side context selection,
   not authentication switching.

8. Workspace switching does not issue a new bearer token.

9. Workspace switching does not mutate server-side
   current-context session state.

10. A fresh Tenant switch always clears old workspace.

11. Browser reload may restore a workspace only after
    fresh discovery confirms it is still valid.

12. Workspace state remains isolated per browser tab.

13. Workspace-scoped capabilities become stale immediately
    when workspace changes.

14. Workspace-scoped server state must not leak across
    workspace boundaries.

15. Superseded workspace responses must not update
    current-context UI.

16. Context-sensitive mutations are blocked during
    workspace transition.

17. Missing required organizational context is a
    recoverable UX condition, not authorization invention.

18. Denied/stale assignments are cleared and must not
    be retried indefinitely.

19. Stale workspace recovery falls back to the safe
    Tenant-level context.

20. Frontend never reimplements scoped-role inheritance logic.

21. Tenant-wide capabilities may remain effective inside
    Organization/Unit context according to backend projection.

22. Workspace presentation policy must remain separate
    from authorization policy.

23. Business modules use shared workspace infrastructure
    rather than implementing their own context mechanism.
```

---

# 48. State Hierarchy Setelah FE-5

Sekarang frontend state model kita menjadi jauh lebih jelas:

```text
Authentication Session
│
├── User
├── Person
├── Membership
└── Tenant
      │
      ▼
Workspace Context
│
├── TENANT
│
├── ORGANIZATION
│
└── ORGANIZATION_UNIT
      │
      ▼
Capability Projection
      │
      ▼
Navigation / UX
      │
      ▼
Business Server State
```

Dengan dependency direction:

```text
Authentication
      ↓
Workspace
      ↓
Capabilities
      ↓
Navigation / Business UI
```

Bukan:

```text
Business module
→ changes auth state

Capability
→ determines Tenant

Workspace
→ creates authorization
```

---

# 49. Perbedaan Tenant Switch vs Workspace Switch Sekarang Sudah Final

|                         | Tenant/Membership Switch | Workspace Switch       |
| ----------------------- | ------------------------ | ---------------------- |
| Boundary                | Security/Tenant          | Organizational runtime |
| Changes Membership      | ✅                       | ❌                     |
| Changes Tenant          | ✅                       | ❌                     |
| New credential          | ✅                       | ❌                     |
| `/auth/me` bootstrap    | ✅                       | ❌                     |
| Clear workspace         | ✅                       | N/A                    |
| Capability reload       | ✅                       | ✅                     |
| Data isolation          | Tenant + Workspace       | Workspace              |
| Default route           | Dashboard                | Preserve if valid      |
| Backend switch mutation | ✅                       | ❌                     |
| Per-tab                 | ✅                       | ✅                     |

Ini saya rasa akan menjadi salah satu tabel paling penting dalam PRD final.

---

# FE-5 Gate Recommendation

Saya menyarankan status:

```text
FE-5 — Workspace / Organizational Context PRD

Workspace Model
✅ Read/runtime projection
✅ No Workspace aggregate/table
✅ TENANT
✅ ORGANIZATION
✅ ORGANIZATION_UNIT

Discovery
✅ GET /user/my-workspaces
✅ Current Membership/Tenant derived
✅ Backend filters active assignments

Selection
✅ Client-side context selection
✅ No token exchange
✅ No server-side workspace session
✅ Per-tab isolated

Transport
✅ Tenant context → no organizational header
✅ Organization/Unit → Assignment locator header
✅ Centralized API transport
✅ Header never authorization authority

Capabilities
✅ Tenant capability for Tenant context
✅ Workspace capability for Org/Unit
✅ Refresh on workspace transition
✅ Backend scoped authorization remains authority

Data Safety
✅ Workspace-scoped cache isolation
✅ Stale request protection
✅ Mutations blocked during transition

Recovery
✅ Stale assignment clears workspace
✅ Rediscover my-workspaces
✅ Tenant-level safe fallback
✅ No infinite stale-context retry

UX
✅ Hierarchical selector
✅ Searchable at scale
✅ Current context always identifiable
✅ Preserve current route only when still valid
```

**FE-5 menurut saya siap di-lock.**

Satu architectural consequence yang sekarang makin jelas: frontend akan membutuhkan **satu canonical Context Layer** yang mengorkestrasi `Auth Session → Workspace → Capabilities`, tetapi ketiganya tetap mempunyai ownership terpisah. Kita jangan membuat satu `globalStore` yang mencampur semuanya.

Setelah persetujuan Anda, kita masuk hanya ke **FE-6 — Capability / Authorization UX PRD**. Di sana kita akan mengunci static route/navigation catalog, capability matching, hidden-vs-disabled policy, tenant vs workspace capabilities, direct-route behavior, capability freshness, serta bagaimana menu tetap sederhana meskipun user memiliki kombinasi tenant-wide dan scoped roles.
