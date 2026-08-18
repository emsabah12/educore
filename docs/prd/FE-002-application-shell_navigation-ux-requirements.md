Baik. **FE-1 kita lock** dan lanjut hanya ke **FE-2 — Application Shell & Navigation UX Requirements**. Pada fase ini kita menetapkan perilaku produk dan information architecture; belum masuk React component, routing implementation, ataupun ADR.

Backend foundation tetap menjadi constraint: Tenant/Membership context, organizational workspace, capability projection, dan backend authorization tidak berubah karena desain shell frontend.

# FE-2 — Application Shell & Navigation UX Requirements

## 1. Tujuan FE-2

Application Shell adalah kerangka yang akan dipakai hampir seluruh modul EduCore.

Targetnya:

```text
┌─────────────────────────────────────────────────────────────┐
│ EduCore                    Tenant / Workspace     User      │
├────────────────┬────────────────────────────────────────────┤
│                │                                            │
│ Navigation     │              Page Content                  │
│                │                                            │
│                │                                            │
│                │                                            │
└────────────────┴────────────────────────────────────────────┘
```

Shell harus menjawab dengan jelas:

```text
WHO
→ saya login sebagai siapa?

TENANT
→ saya sedang bekerja di institusi mana?

WORKSPACE
→ saya berada di Organization / Unit mana?

CAPABILITY
→ fitur apa yang relevan untuk context ini?

LOCATION
→ saya sedang berada di bagian aplikasi mana?
```

---

# 2. Rekomendasi Layout Utama

Untuk EduCore saya merekomendasikan:

> **Desktop-first responsive application shell dengan Sidebar + Topbar.**

Bukan desktop-only.

Alasannya karena EduCore secara karakter lebih dekat dengan:

```text
ERP
SIS
HRIS
Administrative Information System
```

yang memiliki banyak:

- modul;
- tabel;
- form;
- filter;
- hierarchy;
- administrative workflow.

Sidebar lebih scalable ketika jumlah modul bertambah.

## Desktop

```text
┌─────────────────────────────────────────────────────────────────┐
│ Logo / EduCore       Context                Search?      Profile │
├──────────────────┬──────────────────────────────────────────────┤
│                  │ Breadcrumb                                   │
│ Dashboard        │                                              │
│                  │ Page title                                   │
│ PLATFORM         │                                              │
│ People           │                                              │
│ Memberships      │               CONTENT                        │
│ Organization     │                                              │
│                  │                                              │
│ ACADEMIC         │                                              │
│ Students         │                                              │
│ Classes          │                                              │
│                  │                                              │
│ DORMITORY        │                                              │
│ Residents        │                                              │
└──────────────────┴──────────────────────────────────────────────┘
```

## Mobile / narrow viewport

Sidebar berubah menjadi drawer:

```text
┌──────────────────────────────┐
│ ☰ EduCore       Context   👤 │
├──────────────────────────────┤
│ Breadcrumb                   │
│                              │
│ Page                         │
│                              │
└──────────────────────────────┘
```

Dengan demikian business application tetap usable dari tablet atau smartphone tanpa membuat mobile UX sebagai primary design constraint.

---

# 3. Topbar Responsibility

Saya menyarankan topbar **tidak menjadi tempat seluruh navigasi**.

Topbar menangani global/runtime context.

```text
Topbar
│
├── Application identity
├── Tenant context
├── Workspace context
├── optional global search
├── notifications (future)
└── User/Profile menu
```

Sedangkan:

```text
Sidebar
=
feature/module navigation
```

Ini separation yang penting.

---

# 4. Tenant / Membership Selector

Tenant selector sebenarnya secara domain lebih tepat dipahami sebagai:

> **Membership Context Selector**

karena backend melakukan membership switch, bukan sekadar mengganti `tenant_id`.

Namun untuk UX, user kemungkinan lebih mudah melihat:

```text
Pesantren Al-Falah
```

daripada:

```text
Membership #f923...
```

Jadi visual selector menampilkan Tenant identity tetapi secara internal merepresentasikan Membership.

Contoh:

```text
┌──────────────────────────────┐
│ Pesantren Al-Falah        ▼  │
│ Administrator                │
└──────────────────────────────┘
```

Dropdown:

```text
Switch Institution

✓ Pesantren Al-Falah
  Administrator

  SMA Nusantara
  Academic Staff

  Yayasan ABC
  HR Operator
```

### Requirement

Frontend tidak boleh menggunakan:

```text
tenant_id
```

saja untuk melakukan switch.

Canonical operation tetap:

```text
membership
→ switch
→ obtain new authentication context/token
```

sesuai backend contract.

---

# 5. Tenant Context Harus Selalu Terlihat

Saya rekomendasikan ini menjadi requirement P0.

Pengguna tidak boleh melakukan tindakan administratif tanpa mengetahui tenant aktif.

Contoh buruk:

```text
Students
─────────────────
John
Sarah
Ahmad
```

Pengguna bisa lupa bahwa tab tersebut sedang berada di tenant tertentu.

Lebih aman:

```text
Pesantren Al-Falah
SMA → Academic

Students
─────────────────
John
Sarah
Ahmad
```

Context tidak harus besar, tetapi harus identifiable.

Ini makin penting karena EduCore memang mendukung:

```text
Tab A → Tenant A
Tab B → Tenant B
```

---

# 6. Workspace Selector

Tenant dan Workspace harus tetap dipisahkan secara mental.

```text
Tenant
↓
Institution security boundary

Workspace
↓
Organizational operating context
```

Contoh:

```text
Tenant:
Yayasan Edukasi Nusantara

Workspace:
SMA Nusantara / Akademik
```

Selector dapat tampil seperti:

```text
Yayasan Edukasi Nusantara
SMA Nusantara › Akademik       ▼
```

atau pada viewport besar:

```text
Institution                     Workspace
Yayasan Nusantara ▼             SMA / Akademik ▼
```

Saya lebih memilih model kedua secara konseptual karena membuat dua context berbeda menjadi eksplisit.

---

# 7. Workspace Selector Tidak Boleh Menjadi Authorization Engine

Frontend boleh melakukan:

```text
GET /user/my-workspaces
```

kemudian menampilkan assignment yang tersedia.

Tetapi jangan melakukan logic seperti:

```typescript
if (workspace.organizationId === ...)
   userIsAuthorized = true;
```

Tidak.

Frontend hanya menentukan:

```text
selected organizational assignment
```

dan mengirim locator:

```text
X-EduCore-Organizational-Assignment-Id
```

Backend tetap melakukan verification.

---

# 8. Workspace Hierarchy UX

Untuk jumlah workspace kecil:

```text
Workspace
▼
SMA / Academic
SMA / Finance
SMP / Academic
```

cukup.

Tetapi untuk institution besar kita harus anticipate:

```text
20 organizations
×
20 units
=
400 possible contexts
```

Sehingga PRD sebaiknya tidak mewajibkan plain dropdown sederhana.

Requirement-nya sebaiknya:

> Workspace selector harus mendukung hierarchy dan searchable selection ketika jumlah workspace meningkat.

Contoh:

```text
Search workspace...
──────────────────────────

SMA Nusantara
  Academic
  Finance
  Student Affairs

SMP Nusantara
  Academic
  Finance

Pesantren
  Dormitory
  Administration
```

Ini jauh lebih scalable.

---

# 9. Sidebar Information Architecture

Saya merekomendasikan sidebar dengan tiga level konseptual maksimum.

```text
MODULE
  ↓
FEATURE
  ↓
PAGE
```

Contoh:

```text
Academic
├── Students
├── Classes
├── Subjects
└── Assessment
```

Jangan membuat:

```text
Academic
└── Student Management
    └── Student Administration
        └── Active Students
            └── Student List
```

Navigation hierarchy terlalu dalam akan sulit dipahami.

### Rule

> Sidebar navigation maksimum dua level visible hierarchy.

Detail berikutnya ditangani routing/page UX.

---

# 10. Navigation Group

Ketika modul bertambah, navigation dapat dikelompokkan.

Contoh future state:

```text
HOME
  Dashboard

PLATFORM
  People
  Memberships
  Organization

ACADEMIC
  Students
  Classes
  Subjects
  Assessment

HR
  Employees
  Attendance
  Leave

DORMITORY
  Residents
  Buildings
  Rooms
  Placements

FINANCE
  Billing
  Payments
```

Namun grouping tidak boleh berarti semua item selalu tersedia.

Navigation catalog bersifat static:

```text
Application Route Catalog
```

lalu availability-nya diproyeksikan berdasarkan capabilities/context.

---

# 11. Capability-Aware Navigation

Model yang saya sarankan:

```text
Static Navigation Catalog
          +
Capability Projection
          +
Current Workspace
          ↓
Effective Navigation
```

Contoh route catalog:

```text
Students
required capability:
student.view
```

Backend projection mengatakan capability tersedia:

```text
student.view = true
```

Maka:

```text
Students ✅
```

Jika capability tidak tersedia:

```text
Students hidden
```

Tetapi ini hanya **navigation UX**.

Tidak berarti:

```text
hidden menu
=
secure endpoint
```

Backend tetap final authority.

---

# 12. Hidden vs Disabled

Saya merekomendasikan default policy:

### Feature tidak accessible

```text
HIDDEN
```

daripada:

```text
DISABLED
```

Misalnya user sama sekali tidak memiliki capability:

```text
Delete Student
```

maka tombol tidak perlu ditampilkan.

### Feature accessible tetapi temporary unavailable

Barulah:

```text
DISABLED + explanation
```

contoh:

```text
Submit Assessment
disabled

"Assessment period has ended."
```

Jadi:

```text
Authorization absence
→ usually hidden

Business-state restriction
→ usually disabled + reason
```

Ini menghasilkan UX lebih bersih.

---

# 13. Direct Route Access

Ini wajib didefinisikan karena menu hidden tidak mencegah user mengetik URL.

Misalnya:

```text
/academic/students
```

User masuk langsung.

Frontend route guard boleh melakukan capability check untuk UX.

Tetapi request backend tetap dilakukan berdasarkan authorization canonical.

Possible result:

```text
403
```

Frontend kemudian menampilkan standardized Forbidden state.

Jadi:

```text
Navigation Guard
      ↓
UX optimization

Backend Authorization
      ↓
Security boundary
```

---

# 14. Breadcrumb

Saya merekomendasikan breadcrumb untuk administrative interface.

Contoh:

```text
Academic
› Students
› Ahmad Fauzan
› Edit
```

Tetapi breadcrumb tidak perlu membawa Tenant/Workspace karena informasi itu sudah ada di context area.

Jangan:

```text
Yayasan ABC
› SMA
› Academic
› Academic Module
› Students
› Ahmad
› Edit
```

Terlalu panjang.

Jadi separation:

```text
Topbar
→ runtime context

Breadcrumb
→ navigation/page hierarchy
```

---

# 15. Page Header Standard

Setiap halaman utama sebaiknya memiliki standar:

```text
Breadcrumb

Page Title                    Primary Action
Optional description          Secondary actions

Filters / search

Content
```

Contoh:

```text
Academic › Students

Students                         + Add Student
Manage students in this workspace

[ Search students... ] [Status ▼]

------------------------------------------------
Name                  Class              Status
------------------------------------------------
...
```

Business modules kemudian tinggal memakai pattern yang sama.

---

# 16. Dashboard

Saya merekomendasikan foundation menyediakan **Dashboard route**, tetapi tidak langsung mendefinisikan dashboard bisnis kompleks.

Foundation Dashboard bisa menjadi:

```text
Dashboard
│
├── Current Tenant
├── Current Workspace
├── available modules
├── shortcuts
└── basic user context
```

Sedangkan:

```text
Academic statistics
HR metrics
Dormitory occupancy
Finance balance
```

ditambahkan oleh masing-masing domain PRD.

Dengan demikian dashboard tidak menjadi monolith.

---

# 17. Navigation Setelah Tenant Switch

Ini salah satu keputusan penting.

Misalnya user berada di:

```text
Tenant A
Academic
Students
```

kemudian berpindah ke Tenant B.

Tenant B belum tentu memiliki capability `student.view`.

Jadi kita **tidak sebaiknya mempertahankan current page secara otomatis**.

Saya rekomendasikan:

```text
Membership Switch
      ↓
New token/context
      ↓
Re-bootstrap
      ↓
Clear old tenant-scoped state/cache
      ↓
Discover workspaces
      ↓
Resolve default workspace
      ↓
Load capabilities
      ↓
Navigate to safe landing route
```

Safe landing route:

```text
/dashboard
```

Ini lebih predictable daripada mencoba mempertahankan:

```text
/academic/students/123
```

yang mungkin tidak valid di tenant baru.

---

# 18. Navigation Setelah Workspace Switch

Workspace switch berbeda.

Token authentication tidak perlu berubah hanya karena organizational context berubah.

Recommended flow:

```text
Workspace switch
      ↓
Set assignment context
      ↓
Invalidate workspace-scoped queries
      ↓
Reload capabilities
      ↓
Recompute navigation
      ↓
Check current route
```

Jika current route masih valid:

```text
stay on page
```

Jika tidak:

```text
redirect → dashboard
```

Ini memberikan UX lebih baik dibanding selalu membuang posisi user.

---

# 19. Loading Context

Tenant/workspace switching tidak boleh terasa seperti aplikasi diam.

Kita perlu explicit UX state:

```text
Switching institution...
```

atau:

```text
Changing workspace...
```

Selama context sedang berubah, action yang bergantung pada context sebaiknya dicegah sementara.

Karena race condition seperti ini berbahaya:

```text
User clicks switch Tenant A → B

context updating...

User clicks "Delete Student"

request accidentally uses stale Tenant A context
```

Maka context transition harus bersifat atomic dari perspektif UX.

---

# 20. Empty Navigation

Ada kemungkinan authenticated user berhasil login tetapi hampir tidak mempunyai capability.

Jangan menampilkan sidebar kosong tanpa penjelasan.

Tampilkan state seperti:

```text
No features are available for this workspace.

You are signed in successfully, but your current membership
does not have access to any application modules.

Contact your administrator if you believe this is incorrect.
```

Backend status tetap valid authentication; ini bukan necessarily `403` global.

---

# 21. Profile Menu

Foundation Profile menu minimal:

```text
User
├── identity summary
├── current membership summary
├── Account/Profile       [future if API exists]
└── Sign out
```

Jangan mencampur:

```text
Tenant switch
Workspace switch
```

ke profile menu saja.

Kedua context tersebut terlalu penting sehingga harus visible di shell.

---

# 22. Notifications

Saya sarankan notification center:

```text
P1 / future
```

bukan P0 foundation.

Kita boleh menyediakan architectural slot:

```text
Topbar
[notifications placeholder]
```

tetapi tidak perlu membangun notification infrastructure sebelum ada contract bisnis yang jelas.

---

# 23. Global Search

Sama seperti notifications:

```text
P1 / future
```

Global search across:

```text
Person
Student
Employee
Room
Document
```

memerlukan API/search architecture tersendiri.

Jangan memasukkan search icon yang belum memiliki semantics.

Page-level search tetap boleh dikembangkan per module.

---

# 24. Responsive Strategy

Saya merekomendasikan:

> **Desktop-first, responsive, mobile-operable.**

Bukan:

> desktop-only.

Target conceptual breakpoints:

### Large desktop

```text
Expanded sidebar
Full context selectors
Full table tooling
```

### Laptop/tablet landscape

```text
Compact/collapsible sidebar
Full page operations
```

### Tablet/mobile

```text
Navigation drawer
Compact context selector
Cards or responsive tables
Touch-friendly controls
```

Tetapi untuk data-heavy screen, tidak semua tabel harus dipaksa menjadi kartu.

Horizontal scrolling yang terkendali kadang lebih benar daripada menghilangkan data penting.

---

# 25. Accessibility Baseline

Saya ingin ini P0, bukan tambahan setelah aplikasi selesai.

Application shell harus mendukung:

```text
keyboard navigation
visible focus
semantic landmarks
screen-reader labels
sufficient contrast
accessible menus/dialogs
reduced-motion respect
```

Khusus context selector dan sidebar:

```text
Tab
Enter
Escape
Arrow navigation
```

harus dapat digunakan tanpa mouse.

---

# 26. Navigation Performance

Saat nantinya mempunyai puluhan modul, jangan download seluruh code module ketika login.

Conceptual:

```text
Application Shell
     ↓
small initial bundle

Academic
     ↓
lazy chunk

HR
     ↓
lazy chunk

Dormitory
     ↓
lazy chunk
```

Sehingga:

```text
user who only uses HR
```

tidak perlu otomatis memuat JavaScript seluruh Academic + Dormitory + PPDB.

Ini nantinya masuk ADR routing/code splitting, tetapi product requirement-nya bisa kita tetapkan sekarang:

> Business module navigation harus mendukung lazy loading tanpa mengubah UX shell.

---

# 27. Proposed Application Shell

Jika semua keputusan di atas digabung:

```text
┌─────────────────────────────────────────────────────────────────────┐
│ EduCore     Institution ▼     Workspace ▼              User Name ▼ │
├───────────────────┬─────────────────────────────────────────────────┤
│                   │ Academic › Students                             │
│ Dashboard         │                                                 │
│                   │ Students                     + Add Student       │
│ PLATFORM          │ Manage students in current workspace            │
│ People            │                                                 │
│ Organization      │ Search / Filters                                │
│                   │                                                 │
│ ACADEMIC          │ ┌─────────────────────────────────────────────┐ │
│ Students          │ │                                             │ │
│ Classes           │ │                  CONTENT                    │ │
│ Subjects          │ │                                             │ │
│                   │ └─────────────────────────────────────────────┘ │
│ HR                │                                                 │
│ Employees         │                                                 │
│ Attendance        │                                                 │
│                   │                                                 │
│ DORMITORY         │                                                 │
│ Residents         │                                                 │
│ Rooms             │                                                 │
└───────────────────┴─────────────────────────────────────────────────┘
```

Sidebar content berubah berdasarkan:

```text
Membership
+
Workspace
+
Capabilities
```

sementara shell tetap konsisten.

---

# 28. Requirement Classification

### P0 — Foundation

| Requirement                 | Priority |
| --------------------------- | -------- |
| Sidebar + Topbar shell      | P0       |
| Current Tenant visible      | P0       |
| Current Workspace visible   | P0       |
| Membership/Tenant selector  | P0       |
| Workspace selector          | P0       |
| Capability-aware navigation | P0       |
| Route guard UX              | P0       |
| Breadcrumb                  | P0       |
| Standard page header        | P0       |
| Context transition state    | P0       |
| Responsive shell            | P0       |
| Accessibility baseline      | P0       |
| Empty-capability state      | P0       |
| Profile/logout menu         | P0       |

### P1

| Requirement               | Priority |
| ------------------------- | -------- |
| Last workspace preference | P1       |
| Sidebar preference        | P1       |
| Global search             | P1       |
| Notifications             | P1       |
| Advanced shortcuts        | P1       |

### Explicitly Deferred

```text
Academic navigation detail
HR navigation detail
Dormitory navigation detail
PPDB navigation detail
Finance navigation detail
```

Domain PRD masing-masing menentukan isinya.

---

# 29. Non-Negotiable Shell Guardrails

Saya menyarankan bagian ini nantinya masuk PRD final secara eksplisit:

```text
1. Tenant context must always be identifiable.

2. Workspace context must always be identifiable.

3. Tenant and Workspace are distinct contexts.

4. Tenant switch operates through Membership switching.

5. Workspace is a frontend/runtime projection, not a Core aggregate.

6. Capability projection determines UX availability, not backend security.

7. Navigation must never hardcode authorization by role name.

8. A tenant switch clears tenant-scoped frontend state.

9. A workspace switch invalidates workspace-scoped state.

10. Business modules consume the shared application shell.

11. Business modules may not implement separate auth/session systems.

12. Navigation must remain scalable as modules grow.

13. Direct URL access must not bypass backend authorization.

14. Multi-tab tenant isolation must remain possible.
```

---

# FE-2 Gate Recommendation

Saya merekomendasikan kita lock FE-2 dengan keputusan berikut:

```text
FE-2 — Application Shell & Navigation UX

Application Style
✅ Desktop-first responsive SPA

Primary Shell
✅ Sidebar + Topbar

Topbar
✅ Membership/Tenant context
✅ Workspace context
✅ User/Profile
⏭ Global search future
⏭ Notifications future

Sidebar
✅ Module/feature navigation
✅ Capability-aware
✅ Maximum two visible navigation levels
✅ Scalable module grouping

Context
✅ Tenant always identifiable
✅ Workspace always identifiable
✅ Tenant and workspace separated

Tenant Switch
✅ Membership-based
✅ Re-bootstrap context
✅ Clear tenant-scoped state
✅ Redirect to safe landing page

Workspace Switch
✅ Keep auth session
✅ Refresh capability/context
✅ Invalidate workspace data
✅ Preserve route only when valid

Navigation Security
✅ Capability for UX only
✅ Backend remains authority
✅ Direct-route access protected

UX Foundation
✅ Breadcrumb
✅ Standard page header
✅ Loading/context transition
✅ Empty capability state
✅ Responsive baseline
✅ Accessibility baseline
```

Dengan itu, **FE-2 siap di-lock**.

Langkah berikutnya setelah persetujuan Anda adalah **FE-3 — Authentication & Session PRD**. Itu akan menjadi langkah yang cukup penting karena kita akan merancang login flow, bootstrap `/auth/me`, session expiry, reload behavior, logout, error recovery, dan terutama requirement yang nantinya menjadi dasar **ADR token-storage/multi-tab isolation**—tanpa terlebih dahulu mengunci implementation storage tertentu.
