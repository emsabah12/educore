Baik. **FE-3 kita lock** dan sekarang masuk hanya ke **FE-4 — Tenant / Membership Context PRD**.

Pada langkah ini kita tidak memilih library/state-store atau menulis kode. Kita mengunci **semantik produk dan lifecycle** agar implementasi React nanti tidak ambigu.

# FE-4 — Tenant / Membership Context PRD

## 1. Tujuan Langkah & Konsep

Di EduCore, “switch tenant” sebenarnya bukan:

```text
currentTenantId = tenantB
```

melainkan:

```text
Membership A
→ Tenant A

switch authentication context

Membership B
→ Tenant B
```

Ini fundamental karena:

```text
Person
   ├── Membership A → Tenant A
   ├── Membership B → Tenant B
   └── Membership C → Tenant C
```

User account tetap global. **Tenant context berasal dari Membership**, bukan ownership pada `User`.

Backend foundation juga sudah membuktikan bahwa switch hanya berhasil untuk Membership aktif milik Person yang sama dan Tenant yang aktif, kemudian menerbitkan credential baru.

---

# 2. Canonical Membership Discovery

Endpoint existing:

```http
GET /api/v1/user/my-memberships
```

menjadi sumber canonical untuk daftar context yang dapat dipilih user.

Secara konseptual response:

```text
Membership Context
│
├── membership_id
├── tenant_id
├── tenant_name
├── status
└── is_current
```

### Frontend requirement

Frontend **tidak boleh**:

```text
load all tenants
→ menentukan sendiri tenant mana yang boleh dipakai
```

Sebaliknya:

```text
Authenticated Person
        ↓
GET /my-memberships
        ↓
Backend filtered memberships
        ↓
Frontend presents switch choices
```

Daftar tersebut merupakan discovery projection; target tetap diverifikasi lagi oleh backend ketika switch dilakukan.

---

# 3. Kapan Membership Selector Ditampilkan?

Saya merekomendasikan:

### User hanya memiliki satu Membership aktif

Tidak perlu selector dropdown besar.

Cukup tampilkan:

```text
Pesantren Al-Falah
```

sebagai current institution.

### User memiliki lebih dari satu Membership aktif

Tampilkan switcher:

```text
Pesantren Al-Falah                  ▼
```

Dropdown:

```text
Switch institution

✓ Pesantren Al-Falah
  Administrator

  SMA Nusantara
  Academic Operator

  Yayasan ABC
  HR Staff
```

Namun label seperti `Administrator` di atas hanyalah **descriptive metadata jika API mendukungnya**.

Frontend tidak boleh menggunakan label tersebut untuk authorization.

---

# 4. Membership Switch Canonical Transaction

Backend endpoint tetap:

```http
POST /api/v1/user/memberships/{membership_id}/switch
```

Canonical flow:

```text
Current Context
Membership A / Tenant A
        │
        ▼
User selects Membership B
        │
        ▼
CONTEXT_SWITCHING
        │
        ▼
POST membership B /switch
        │
        ▼
Backend validates
├── same canonical Person?
├── Membership ACTIVE?
└── Tenant ACTIVE?
        │
        ▼
Issue NEW credential
        │
        ▼
Frontend commits new credential
        │
        ▼
GET /auth/me
        │
        ▼
Verified Membership B / Tenant B
```

Backend contract yang sudah ada memang mendefinisikan switch sebagai **stateless token exchange**, bukan mutable server-side current tenant.

---

# 5. Switch Harus Atomic

Ini salah satu requirement terpenting FE-4.

Kita tidak boleh melakukan:

```text
1. UI set Tenant B
2. clear old Tenant A
3. call API
4. API gagal
```

Karena frontend sudah berada di state palsu.

Correct transaction:

```text
Tenant A
credential A
workspace A
        │
        │ user requests switch
        ▼
CONTEXT_SWITCHING

Tenant A masih canonical current context
        │
        │ backend success
        ▼
credential B received
        │
        ▼
COMMIT context transition
```

Jadi sebelum response switch valid diterima:

```text
Current Membership = A
Current Tenant     = A
Current Credential = A
```

tetap authoritative bagi frontend.

---

# 6. Kalau Switch Gagal

Misalnya:

```text
Membership B sudah inactive
```

atau:

```text
Tenant B sudah inactive
```

antara waktu membership list dimuat dan user menekan switch.

Flow:

```text
Membership A
      ↓
request switch B
      ↓
403 / canonical error
      ↓
remain Membership A
      ↓
refresh /my-memberships
      ↓
show safe failure message
```

**Tidak boleh**:

```text
clear Tenant A
→ switch failed
→ application has no valid context
```

Dengan demikian switch failure bersifat non-destructive.

---

# 7. Network Failure Saat Switch

Kasus:

```text
POST switch
      ↓
network timeout
```

Frontend belum tahu apakah server sempat menerbitkan token baru.

Tetapi karena backend tidak menyimpan mutable global `current Tenant`, **old credential tetap legitimate sampai expiry/revocation**.

Maka frontend:

```text
retain credential A
retain Tenant A
retain Workspace A

show:
"Unable to switch institution. Try again."
```

Kita tidak melakukan speculative switch.

---

# 8. Double-Click / Concurrent Switch

Bayangkan:

```text
User clicks Tenant B
User immediately clicks Tenant C
```

Kita tidak boleh membiarkan dua switch transaction saling berlomba.

Requirement:

> **Hanya satu Membership context transition boleh aktif per tab pada satu waktu.**

UX:

```text
Switching institution...
```

Selector sementara disabled.

Konseptual:

```text
A → B request
```

harus selesai/gagal sebelum switch lain dapat dimulai.

Ini KISS dan jauh lebih aman daripada mencoba menyelesaikan race:

```text
A → B
A → C
response C arrives
response B arrives
```

yang bisa membuat final credential tidak sesuai UI.

---

# 9. Credential Replacement

Setelah response B diterima:

```text
credential A
      ↓
atomic replacement
      ↓
credential B
```

Tetapi kita **belum menganggap switch selesai sepenuhnya**.

Selanjutnya:

```text
credential B
      ↓
GET /auth/me
```

harus memverifikasi:

```text
Membership B
Tenant B
User
Person
```

Jadi state sementara:

```text
CONTEXT_SWITCHING
```

tetap aktif sampai `/auth/me` berhasil.

---

# 10. Kenapa Re-Bootstrap Tetap Dibutuhkan?

Response switch mungkin sudah memberikan:

```text
membership_id
tenant_id
tenant_name
```

Tetapi kita tidak ingin memiliki dua cara membangun authenticated context:

```text
Login → /auth/me

Switch → manually construct auth state
```

Lebih bersih:

```text
Credential changes
        ↓
always /auth/me
        ↓
canonical authenticated context
```

Dengan demikian:

```text
login
reload
membership switch
session recovery
```

semuanya bertemu pada bootstrap mechanism yang sama.

---

# 11. Workspace Harus Dihapus Setelah Tenant Switch

Ini **non-negotiable**.

Misalnya:

```text
Tenant A
└── Workspace:
    SMA A / Academic
```

kemudian pindah:

```text
Tenant B
```

assignment Tenant A tidak boleh ikut terbawa.

Flow canonical backend/frontend sebelumnya memang menetapkan bahwa OrganizationalAssignment harus di-clear setelah tenant switch, lalu workspaces Tenant baru ditemukan kembali.

Jadi:

```text
successful credential exchange
        ↓
CLEAR workspace context
```

sebelum:

```text
GET /user/my-workspaces
```

---

# 12. Tenant Switch dan Cache Invalidation

Ini akan sangat penting ketika kita memakai TanStack Query.

Misalnya Tenant A mempunyai:

```text
students
employees
rooms
academic periods
capabilities
workspaces
```

Setelah pindah Tenant B, cache Tenant A **tidak boleh langsung muncul sebagai data Tenant B**.

Requirement:

```text
Tenant Switch
      ↓
invalidate / isolate all tenant-scoped server state
```

Termasuk minimal:

```text
Membership-derived context
Workspace list
Capability projection
Student data
Employee data
Dormitory data
Academic data
Tenant-specific settings
```

Tetapi data global yang benar-benar tidak tenant-scoped tidak harus dibuang.

---

# 13. Jangan Hanya Mengandalkan `queryClient.clear()`

Nanti dalam ADR state architecture saya tidak ingin solusi satu-satunya menjadi:

```typescript
queryClient.clear();
```

setiap switch.

Lebih sehat jika data secara arsitektural memiliki context identity.

Konseptual:

```text
Query Identity
=
resource
+
tenant/membership
+
workspace when applicable
+
parameters
```

Misalnya nanti:

```text
students
Tenant A
Workspace X
```

dan:

```text
students
Tenant B
Workspace Y
```

adalah server states berbeda.

Exact implementation key kita putuskan nanti.

---

# 14. Stale Requests dari Tenant Lama

Ini bahkan lebih penting daripada cache.

Scenario:

```text
Tenant A
GET /students    ← slow request

user switches Tenant B

Tenant B ready

old Tenant A request finishes
```

Result Tenant A **tidak boleh** masuk ke active Tenant B UI.

Requirement:

> Response yang berasal dari superseded authentication context tidak boleh memperbarui active-context UI state.

Strateginya nanti dapat berupa:

```text
request cancellation
context generation/version
context-aware query keys
ignore stale completion
```

Kita belum memilih implementasinya.

Tetapi product invariant-nya kita lock sekarang.

---

# 15. Mutation Saat Switching

Saat:

```text
CONTEXT_SWITCHING
```

protected mutation harus dihentikan.

Contoh:

```text
POST student
DELETE room
PUT employee
POST grading
```

tidak boleh dimulai.

Karena kita tidak ingin ambiguity:

```text
Apakah mutation dilakukan atas Tenant A atau Tenant B?
```

UI boleh tetap menampilkan old page sebagai transitional visual, tetapi mutating controls harus unavailable.

---

# 16. Current Route Setelah Tenant Switch

Di FE-2 kita sudah menyentuh ini.

Sekarang saya rekomendasikan kita lock:

> **Tenant switch selalu mengakhiri current business-route continuity.**

Misalnya:

```text
Tenant A
/academic/students/123/edit
```

kemudian pindah ke Tenant B.

Kita **tidak mencoba**:

```text
Tenant B
/academic/students/123/edit
```

karena ID yang sama dapat:

```text
tidak ada
berbeda makna
tidak authorized
```

Canonical safe landing:

```text
/dashboard
```

---

# 17. Canonical Post-Switch Flow

Saya sarankan full flow berikut menjadi PRD requirement:

```text
Tenant A / Membership A
Workspace A
Business Page
        │
        ▼
User selects Membership B
        │
        ▼
CONTEXT_SWITCHING
        │
        ├── prevent mutations
        └── prevent another switch
        │
        ▼
POST /memberships/B/switch
        │
        ▼
receive credential B
        │
        ▼
atomic credential replacement
        │
        ▼
clear old workspace
        │
        ▼
invalidate/isolate Tenant A active state
        │
        ▼
GET /auth/me
        │
        ▼
verify Membership B + Tenant B
        │
        ▼
GET /user/my-workspaces
        │
        ▼
resolve initial workspace
        │
        ▼
load applicable capabilities
        │
        ▼
navigate /dashboard
        │
        ▼
AUTHENTICATED / READY
```

Ini juga sama dengan canonical tenant-switch flow yang sudah didokumentasikan pada frontend transport baseline.

---

# 18. Default Workspace Setelah Tenant Switch

Di sini saya menyarankan satu refinement.

Backend `my-workspaces` mempunyai conceptual Tenant-level workspace selain Organization/OrganizationUnit projections.

Maka default policy:

### Jika Tenant-level context valid

Setelah tenant switch:

```text
Default Workspace
=
Tenant-level context
```

Lalu user boleh memilih Organization/Unit tertentu.

Ini paling deterministic.

Jangan otomatis memilih:

```text
first array item
```

karena ordering API bukan product semantics.

---

# 19. “Last Workspace” Restoration

Ada dua kemungkinan.

### Option A — selalu Tenant-level setelah Tenant switch

Simple dan predictable.

### Option B — restore last workspace per Membership

Lebih nyaman tetapi menambah persistence/state complexity.

Untuk Foundation v1 saya merekomendasikan:

```text
Tenant switch
→ Tenant-level safe context
```

dan:

```text
Last workspace restoration
→ P1
```

Kita jangan mengoptimalkan UX sebelum lifecycle dasarnya terbukti aman.

---

# 20. Reload Berbeda dengan Tenant Switch

Normal reload:

```text
Membership A
Tenant A
Workspace A

browser reload
```

boleh mencoba restore Workspace A **setelah**:

```text
/auth/me
+
/my-workspaces
```

membuktikan assignment A masih valid.

Jadi:

```text
reload
→ restore valid workspace if possible

explicit tenant switch
→ do not carry old workspace
```

Dua behaviour ini sengaja berbeda.

---

# 21. Multi-Tab Semantics

Backend sengaja tidak otomatis merevoke token lama ketika switch berhasil. Itu memungkinkan dua browser tabs mempertahankan stateless context berbeda.

Maka requirement:

```text
Tab A
Membership A
Tenant A
Workspace A

Tab B
Membership A
Tenant A
Workspace X
```

Kemudian di Tab A:

```text
switch → Membership B
```

hasil:

```text
Tab A
Membership B
Tenant B

Tab B
Membership A
Tenant A
```

**Tab B tidak berubah.**

---

# 22. Tidak Ada Cross-Tab Tenant Broadcast

Jangan:

```text
BroadcastChannel:
"tenant changed to B"
```

lalu semua tab switch.

Jangan juga menggunakan:

```text
storage event
```

untuk menyinkronkan active tenant.

Cross-tab broadcast mungkin berguna kelak untuk hal seperti:

```text
security revocation
account-wide signout
```

tetapi **bukan untuk normal Membership switch**.

---

# 23. Old Token Policy

Ini perlu dibuat sangat eksplisit dalam PRD.

Setelah:

```text
Token A
→ switch
→ Token B
```

Token A **tidak otomatis dicabut**.

Ia masih dapat valid sampai:

```text
expiry
explicit logout/revocation
security revocation
```

Ini bukan bug.

Ini bagian dari multi-tab architecture.

Frontend tidak boleh mengasumsikan:

```text
switch B
=
A revoked globally
```

---

# 24. Current Membership Tidak Sama dengan Preferred Membership

Frontend v1 tidak perlu membuat konsep baru seperti:

```text
default_membership_id
preferred_tenant_id
last_tenant_id
```

pada backend hanya untuk kenyamanan.

Current context ditentukan oleh credential aktif.

Jika kelak diperlukan preference:

```text
preferred institution
```

itu menjadi user preference feature tersendiri.

---

# 25. Membership List Refresh Strategy

Tidak perlu memanggil `/my-memberships` setiap route navigation.

Recommended semantics:

```text
After authenticated bootstrap
→ fetch membership list

Before/opening switcher
→ cached list acceptable within reasonable freshness

After failed stale switch
→ refresh immediately

After successful switch
→ refresh when new context ready
```

Nanti caching details menjadi implementation decision.

---

# 26. Membership Disappears Saat User Sedang Aktif

Ada edge case:

```text
User sedang Tenant A
Administrator menonaktifkan Membership A
```

Browser mungkin masih menampilkan Tenant A sampai request berikutnya.

Backend akan fail closed ketika current context tidak lagi valid.

Frontend response:

```text
401 / context-invalid error
        ↓
authentication/session recovery
```

Jangan mencoba mempertahankan tenant hanya berdasarkan cached membership list.

Backend tetap authority.

---

# 27. Tenant Menjadi Inactive

Sama:

```text
Tenant A becomes inactive
```

Jika backend kemudian menolak credential/context:

```text
frontend
→ clear invalid session
→ login/context recovery
```

Tidak boleh melakukan:

```text
ignore backend
because tenant cached as ACTIVE
```

---

# 28. Capability Lifecycle Setelah Switch

Capabilities lama harus dianggap invalid setelah Membership berubah.

Flow:

```text
Membership A
Capabilities A

switch

Membership B
Capabilities = UNKNOWN
```

Bukan:

```text
Membership B
Capabilities A temporarily reused
```

Setelah bootstrap + workspace resolution:

```text
load capabilities B
```

baru sidebar/action composition dianggap ready.

---

# 29. Application Shell During Transition

Untuk menjaga UX:

```text
Application Shell
```

boleh tetap dirender ketika switch berlangsung.

Tetapi context indicator harus memberi state yang jelas:

```text
Switching to SMA Nusantara...
```

dan content sebaiknya masuk transitional state.

Kita jangan menampilkan:

```text
Tenant B in topbar
+
Tenant A content still interactive
```

Karena itu context indicator baru berubah menjadi committed Tenant B setelah transition mencapai safe point.

---

# 30. Failure Setelah Credential B Diterima tetapi `/auth/me` Gagal

Ini rare tetapi penting.

```text
switch endpoint → success
credential B received
/auth/me → failure
```

Setelah credential B sudah committed, jangan diam-diam kembali ke A menggunakan stale state.

Kita tidak bisa berasumsi credential A masih tersedia atau seharusnya digunakan.

Safe behaviour:

```text
clear incomplete authenticated context
→ session recovery/login
```

dengan appropriate error state.

Ini fail-closed dan lebih aman.

---

# 31. Telemetry yang Berguna

Tanpa merekam credential atau sensitive PII, frontend observability nanti sebaiknya dapat membedakan:

```text
membership_switch_started
membership_switch_succeeded
membership_switch_failed

from_tenant?   → identifier harus mengikuti privacy policy
to_tenant?     → same

duration
error_code
```

Tetapi:

```text
access_token
password
authorization header
```

tidak pernah masuk telemetry.

Exact observability kita putuskan di FE-8/ADR.

---

# 32. P0 / P1 Classification

| Requirement                                |                   Priority |
| ------------------------------------------ | -------------------------: |
| Membership discovery                       |                         P0 |
| Membership-based tenant switch             |                         P0 |
| Server validation on every switch          |                         P0 |
| Atomic transition                          |                         P0 |
| Non-destructive failed switch              |                         P0 |
| Re-bootstrap via `/auth/me`                |                         P0 |
| Workspace cleared after switch             |                         P0 |
| Tenant-scoped cache invalidation/isolation |                         P0 |
| Stale-response protection                  |                         P0 |
| Block mutation during transition           |                         P0 |
| Single switch transaction per tab          |                         P0 |
| Safe `/dashboard` landing                  |                         P0 |
| Capability reload                          |                         P0 |
| Per-tab independence                       |                         P0 |
| Old token not implicitly revoked           |                         P0 |
| Last workspace per Membership              |                         P1 |
| Preferred/default Membership               |                  Future/P1 |
| Cross-tab coordinated context              | Rejected for normal switch |

---

# 33. Non-Negotiable FE-4 Guardrails

Saya merekomendasikan kita lock:

```text
1. Tenant switching always means Membership/authentication-context switching.

2. Frontend may only present Memberships discovered from the authenticated API.

3. Membership discovery is not authorization authority.

4. The server revalidates every target Membership.

5. A switch must be atomic from the user's perspective.

6. Failed switches must preserve the previous valid context.

7. Only one Membership switch may run per tab at a time.

8. The new credential must be verified again through /auth/me.

9. Workspace state must never cross a Tenant boundary.

10. Tenant-scoped server state must not leak across context changes.

11. Responses from superseded contexts must not update active-context UI.

12. Protected mutations are blocked while context is transitioning.

13. Tenant switch navigates to a safe landing route.

14. Capabilities from the old Membership become invalid immediately.

15. Independent tabs may maintain independent Membership/Tenant contexts.

16. Normal Tenant switch must not synchronize credential/context across tabs.

17. The previous bearer token is not assumed revoked after switching.

18. Backend context validation remains authoritative over cached frontend state.

19. Tenant switch does not introduce mutable server-side current-Tenant session.

20. Preferred/default Tenant persistence is not introduced speculatively.
```

---

# 34. State Model yang Dihasilkan FE-4

Secara konseptual kita sekarang punya:

```text
Auth Session
│
├── credential
├── User
├── Person
├── Membership
└── Tenant
        │
        │ owns context boundary
        ▼
Workspace Context
│
└── OrganizationalAssignment
        │
        ▼
Capability Projection
        │
        ▼
Business Server State
```

Saat Membership berubah:

```text
Membership/Tenant
       ↓
Workspace RESET
       ↓
Capabilities RESET
       ↓
Tenant/workspace server state RESET/ISOLATED
```

Ini hierarchy yang menurut saya sangat sehat untuk frontend EduCore.

---

# FE-4 Gate Recommendation

Saya menyarankan status berikut:

```text
FE-4 — Tenant / Membership Context PRD

Membership Discovery
✅ GET /user/my-memberships canonical
✅ Backend-filtered active contexts
✅ Selector only when useful

Tenant Switching
✅ Membership authentication-context exchange
✅ Stateless backend
✅ New credential
✅ Atomic frontend transition
✅ Server revalidation
✅ Non-destructive failure

Post-Switch
✅ Replace credential
✅ Clear workspace
✅ Re-bootstrap /auth/me
✅ Rediscover workspaces
✅ Reload capabilities
✅ Isolate/invalidate old Tenant data
✅ Redirect /dashboard

Concurrency
✅ One switch per tab
✅ Mutations blocked during switch
✅ Stale responses cannot contaminate new context

Multi-Tab
✅ Tab contexts independent
✅ No normal cross-tab Tenant synchronization
✅ Previous token not automatically revoked

Workspace
✅ Never crosses Tenant boundary
✅ Tenant-level safe context as Foundation v1 default
⏭ Last-workspace-per-Membership P1

Security
✅ Backend remains final context authority
✅ Cached membership/workspace state is never trusted
```

**FE-4 menurut saya siap di-lock.**

Satu consequence penting juga mulai terlihat untuk ADR nanti: **server-state cache harus context-aware**, bukan sekadar cache berdasarkan resource name. `students` pada Tenant A dan `students` pada Tenant B harus diperlakukan sebagai dua server states berbeda; demikian pula workspace-scoped data. Ini akan sangat memengaruhi ADR TanStack Query/state ownership nantinya.

Setelah persetujuan Anda, kita masuk satu langkah berikutnya: **FE-5 — Workspace / Organizational Context PRD**. Di sana kita akan mengunci Tenant-level vs Organization vs OrganizationUnit workspace, selection/restoration, header transport, stale-assignment recovery, hierarchy/search UX, dan hubungan workspace dengan scoped capabilities—tanpa menjadikan Workspace sebagai domain entity baru.
