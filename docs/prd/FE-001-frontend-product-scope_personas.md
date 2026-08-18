Baik. Kita lanjut **satu langkah saja** ke **FE-1 — Frontend Product Scope & Personas**. Pada tahap ini kita belum menentukan layout detail, routing, atau menulis ADR.

Baseline tetap mengikuti kontrak backend yang sudah dikunci: `Person`, `User`, `Membership`, `Tenant`, organizational context, capability projection, dan backend sebagai final authorization authority.

# FE-1 — Frontend Product Scope & Personas

## 1. Tujuan Langkah & Konsep

Tujuan FE-1 adalah menjawab dua pertanyaan:

> **Siapa yang akan menggunakan Frontend Foundation EduCore?**

dan:

> **Kemampuan minimum apa yang harus tersedia sebelum Academic, HR, Dormitory, PPDB, dan modul bisnis lain mulai dibangun?**

Hal pentingnya: jangan mendesain frontend berdasarkan nama role seperti:

```text
if role === "admin"
if role === "teacher"
```

Karena authorization EduCore sudah berbasis **role/permission/capability dengan scope**, bukan hardcoded role di frontend.

Jadi persona di PRD digunakan untuk memahami **kebutuhan pengguna**, bukan menjadi authorization mechanism.

---

# 2. Product Boundary yang Saya Rekomendasikan

Saya menyarankan frontend EduCore dibagi menjadi tiga lapisan produk.

```text
EduCore Frontend
│
├── A. Platform Foundation
│
├── B. Identity & Access Administration
│
└── C. Business Modules
```

### A. Platform Foundation — PRD pertama

Ini yang kita desain sekarang.

```text
Authentication
Authenticated session
User/person context
Membership context
Tenant context
Workspace context
Capability projection
Application shell
Navigation
Routing
API infrastructure
Error handling
Logout
Security baseline
Observability
```

### B. Identity & Access Administration

Ini masih termasuk platform utama, tetapi saya sarankan sebagai **PRD berikutnya**, karena sebagian contract backend administrasinya belum lengkap.

```text
Person management
User account management
Membership management
Tenant administration
Role management
Permission management
Role assignment
Permission assignment
```

### C. Business Modules

Dibangun setelah foundation stabil.

```text
Academic
HR
Dormitory
PPDB
Finance
Library
Attendance
dan seterusnya
```

Dengan separation ini kita tidak membuat satu PRD raksasa yang mencoba menyelesaikan seluruh EduCore sekaligus.

---

# 3. Persona 1 — Multi-Tenant Authenticated User

Ini justru persona foundation **paling penting**.

Contoh:

```text
Ahmad
│
├── Membership → Pesantren A
│
└── Membership → Sekolah B
```

User yang sama dapat mempunyai lebih dari satu Membership.

### Kebutuhan

Setelah login:

```text
Login
  ↓
Authentication successful
  ↓
GET /auth/me
  ↓
Authenticated context
  ↓
Membership/Tenant available
  ↓
Application
```

Jika mempunyai beberapa membership:

```text
Tenant A
Tenant B
Tenant C
```

pengguna harus dapat berpindah context.

Dan browser harus tetap memungkinkan:

```text
Tab A → Tenant A

Tab B → Tenant B
```

Persona ini menjadi alasan mengapa architecture session/token kita nanti sangat penting.

### Foundation requirement

User harus bisa:

- login;
- melihat active tenant;
- melihat active membership;
- berpindah membership;
- mengetahui context aktif;
- logout;
- recovery ketika session kedaluwarsa.

**Priority: P0**

---

# 4. Persona 2 — Tenant Administrator

Contohnya:

```text
Administrator
Pesantren Al-Falah
```

Administrator beroperasi di dalam satu tenant.

Ia mungkin mempunyai capability seperti:

```text
membership.view
membership.assign-role
organization.view
organization.manage
```

Tetapi UI tidak boleh menyimpulkan privilege hanya dari nama:

```text
"Tenant Admin"
```

Frontend memperoleh capability projection dari backend.

### Foundation needs

Administrator membutuhkan:

```text
Application Shell
Tenant context
Workspace selector
Capability-aware navigation
Protected routes
Consistent API errors
```

### Administration needs

Pada PRD IAM berikutnya ia mungkin membutuhkan:

```text
Manage People
Manage Accounts
Manage Memberships
Assign Roles
Manage Roles
Manage Permissions
```

**Foundation Priority: P0**

**IAM Administration Priority: P1**

---

# 5. Persona 3 — Organization Administrator / Operator

Misalnya tenant mempunyai:

```text
Yayasan ABC
│
├── SMP
├── SMA
└── Pesantren
```

Seseorang mungkin mempunyai authorization hanya terhadap:

```text
Organization = SMA
```

atau terhadap:

```text
OrganizationUnit = SMA / Finance
```

Inilah alasan Workspace Context diperlukan.

### Kebutuhan

User harus memahami bahwa ia sedang bekerja pada:

```text
Tenant
   ↓
Organization
   ↓
OrganizationUnit
```

misalnya:

```text
Yayasan ABC
SMA ABC
Bagian Akademik
```

Frontend perlu menyediakan workspace selector.

Namun selector tersebut hanya menentukan:

```text
runtime/navigation context
```

bukan authorization authority.

Backend tetap memvalidasi:

```text
X-EduCore-Organizational-Assignment-Id
```

### Priority

**P0**

Karena hampir semua business module berikutnya akan bergantung pada organizational context.

---

# 6. Persona 4 — Operational Staff

Contohnya nanti:

```text
Academic Operator
HR Staff
Dormitory Staff
Finance Staff
```

Persona ini bukan administrator sistem.

Mereka masuk EduCore untuk melakukan pekerjaan sehari-hari.

Contohnya Academic Staff mungkin nanti melihat:

```text
Dashboard
Students
Classes
Attendance
Assessments
```

Sedangkan Dormitory Staff:

```text
Dashboard
Residents
Rooms
Beds
Check-in
```

Tetapi application shell mereka tetap berasal dari platform foundation yang sama:

```text
Authentication
        ↓
Membership
        ↓
Workspace
        ↓
Capabilities
        ↓
Navigation
        ↓
Business Module
```

### Priority untuk Foundation

**P0 sebagai consumer platform**, tetapi fitur bisnisnya **Out of Scope**.

---

# 7. Persona 5 — Restricted / Read-Only User

Ini persona yang sering terlupakan.

Misalnya pengguna hanya mempunyai:

```text
view.student
view.report
```

dan tidak mempunyai:

```text
student.create
student.update
student.delete
```

Frontend harus mampu membuat UX seperti:

```text
Student List       ✅ visible
Student Detail     ✅ visible

Create Student     ❌ hidden
Edit Student       ❌ hidden
Delete Student     ❌ hidden
```

Namun sekalipun tombol disembunyikan:

```text
Backend authorization tetap wajib.
```

Jika user mencoba direct API request:

```text
DELETE /students/...
```

backend tetap menentukan hasil akhirnya.

Persona ini penting untuk memastikan capability architecture bukan sekadar admin/non-admin.

### Priority

**P0**

---

# 8. Persona 6 — Platform Administrator

Ini berbeda dengan Tenant Administrator.

Secara konseptual:

```text
EduCore Platform
│
├── Tenant A
├── Tenant B
└── Tenant C
```

Platform Administrator mungkin nantinya mengelola:

```text
Tenant lifecycle
Platform configuration
Global monitoring
System health
Cross-tenant administration
```

Tetapi dari audit contract sekarang, kita **belum mempunyai cukup hardened API contract untuk menjadikan seluruh Platform Administration bagian Frontend Foundation v1**.

Jadi persona ini tetap kita dokumentasikan, tetapi UI administrasinya ditunda.

### Priority

**Foundation: P1**

**Full Platform Administration: Future PRD**

---

# 9. Persona yang Belum Menjadi Foundation Persona

Misalnya:

```text
Student
Parent
Teacher
Applicant
Dormitory Resident
Alumni
```

Bukan berarti mereka tidak penting.

Tetapi kebutuhan khusus mereka akan muncul dari business PRD masing-masing.

Misalnya:

```text
Academic PRD
    ↓
Teacher
Student

PPDB PRD
    ↓
Applicant
Parent

Dormitory PRD
    ↓
Resident
Dormitory Staff
```

Foundation hanya menyediakan platform runtime bagi mereka.

---

# 10. Persona Matrix

| Persona                | Foundation | IAM Admin | Business Feature |
| ---------------------- | ---------: | --------: | ---------------: |
| Multi-Tenant User      |     **P0** |         — |         Consumer |
| Tenant Administrator   |     **P0** |    **P1** |         Consumer |
| Organization Admin     |     **P0** |        P1 |         Consumer |
| Operational Staff      |     **P0** |         — |       **Future** |
| Restricted User        |     **P0** |         — |         Consumer |
| Platform Administrator |         P1 |    Future |                — |
| Teacher                |   Consumer |         — |           Future |
| Student                |   Consumer |         — |           Future |
| Parent                 |   Consumer |         — |           Future |

---

# 11. Dari Persona → Foundation Product Requirements

Dari persona di atas, menurut saya **Frontend Foundation v1 harus mempunyai**:

### P0 — Mandatory

```text
Authentication
Application bootstrap
Authenticated user/person context

Membership context
Tenant context
Membership/Tenant switch

Workspace discovery
Workspace selection
Workspace context transport

Capability loading
Capability-aware navigation

Protected routes

Application shell

Canonical API client
Canonical API error handling

Session expiration handling
Logout

Loading states
Empty states
Error states
Recovery states

Responsive baseline
Accessibility baseline

Security baseline
Observability baseline
```

### P1 — Important, tetapi dapat mengikuti foundation

```text
User preferences
Last workspace preference
Advanced session recovery
Advanced audit UX
Global command/search
Notification infrastructure
```

### Future / Business PRD

```text
Student management
Employee management
Academic management
Dormitory management
PPDB
Finance
Reports domain-specific
```

---

# 12. Tentang Core, User, Auth, Role, Permission

Karena sebelumnya Anda memang menyebut modul ini, saya ingin memberikan posisi yang sangat jelas.

Frontend foundation **tidak akan mempunyai menu `Core`**.

`Core` adalah architectural/platform concept, bukan business menu.

Sebaliknya UX kemungkinan nanti menjadi:

```text
Platform
│
├── Dashboard
│
├── People
├── Users
├── Memberships
│
├── Roles
├── Permissions
│
└── Organization
```

Tetapi tidak semuanya langsung masuk PRD pertama.

Saya sarankan pembagiannya:

```text
PRD 1
EduCore Frontend Platform Foundation
│
├── Auth
├── Session
├── Tenant
├── Membership runtime
├── Workspace
├── Capabilities
├── Shell
└── Infrastructure


PRD 2
EduCore Identity & Access Administration
│
├── Person
├── User
├── Membership
├── Role
├── Permission
└── Assignment
```

Ini menurut saya jauh lebih maintainable daripada menyatukan semuanya.

---

# 13. Critical User Journey Foundation

Dari semua persona tadi, journey yang menjadi tulang belakang frontend adalah:

```text
Visitor
   │
   ▼
Login
   │
   ▼
Authentication
   │
   ▼
Bootstrap /auth/me
   │
   ▼
Membership + Tenant
   │
   ▼
Discover Workspaces
   │
   ▼
Select Workspace
   │
   ▼
Load Capabilities
   │
   ▼
Compose Navigation
   │
   ▼
Application Ready
   │
   ├── switch workspace
   │
   ├── switch membership/tenant
   │
   └── logout
```

Kalau journey ini kuat, setiap modul berikutnya tinggal masuk ke platform.

```text
Platform Foundation
       │
       ├── Academic
       ├── HR
       ├── Dormitory
       ├── PPDB
       └── Finance
```

Tanpa setiap module menciptakan ulang auth/session/navigation sendiri.

---

# 14. Product Principle yang Saya Usulkan untuk Dikunci

Untuk PRD nanti saya menyarankan lima principle berikut.

### Principle 1 — Context must always be explicit

Pengguna harus selalu dapat mengetahui:

```text
Saya siapa?
Tenant mana?
Workspace mana?
```

### Principle 2 — Capabilities shape UX, not security

Frontend:

```text
capability → UX
```

Backend:

```text
authorization → security
```

### Principle 3 — Switching context must be predictable

Tenant/workspace switching tidak boleh menghasilkan state ambigu.

### Principle 4 — Business modules consume platform

Academic, HR, Dormitory tidak boleh membuat sistem auth/navigation/session sendiri.

### Principle 5 — Frontend must survive growth

Architecture harus tetap sehat ketika:

```text
5 modules
→
20 modules
→
50 modules
```

dan:

```text
10 developers
→
50 developers
```

tanpa satu global frontend dependency graph yang sulit dipelihara.

---

# FE-1 Gate Recommendation

Saya menyarankan kita lock FE-1 sebagai berikut:

```text
FE-1 — Frontend Product Scope & Personas

Primary Product:
EduCore Frontend Platform Foundation

Primary Personas:
✅ Multi-Tenant Authenticated User
✅ Tenant Administrator
✅ Organization/Unit Administrator
✅ Operational Staff
✅ Restricted/Read-Only User

Secondary Persona:
✅ Platform Administrator

Deferred Domain Personas:
⏭ Teacher
⏭ Student
⏭ Parent
⏭ Applicant
⏭ Dormitory Resident

Foundation Scope:
✅ Auth
✅ Session
✅ Membership/Tenant Context
✅ Workspace
✅ Capabilities
✅ Navigation
✅ Routing
✅ API Infrastructure
✅ Error/Recovery UX
✅ Application Shell
✅ Security
✅ Observability

Separate Next PRD:
Identity & Access Administration
(Person/User/Membership/Role/Permission)

Business Modules:
⏭ Deferred
```

Dengan demikian **FE-1 siap di-lock**.

**Langkah berikutnya, setelah konfirmasi Anda, adalah FE-2 — Application Shell & Navigation UX Requirements.** Di sana kita akan menentukan struktur konkret aplikasi: sidebar/topbar, tenant selector, workspace selector, profile menu, responsive behavior, navigation hierarchy, breadcrumb, dan bagaimana capability projection membentuk menu—tetap tanpa mulai coding.
