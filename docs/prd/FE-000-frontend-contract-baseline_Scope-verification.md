Baik. Dari handoff dan snapshot repository, **FE-0 dapat kita nyatakan cukup matang untuk mulai menyusun Frontend PRD**, tetapi ada beberapa boundary penting yang perlu kita sepakati sebelum masuk ke desain UX.

Handoff sebelumnya sudah menetapkan backend foundation sebagai frozen contract: identity `Person → Membership → Tenant`, bootstrap lewat `/auth/me`, membership switching, workspace projection, capability projection, canonical error contract, dan OpenAPI 3.1.

## FE-0 — Frontend Contract Baseline & Scope Verification

### 1. Kondisi repository saat ini

Saya memeriksa snapshot `educore.zip`.

Frontend praktis masih kosong:

```text
resources/js/app.js
└── kosong
```

Tetapi build foundation sudah tersedia:

```text
Laravel 13.8
Vite 8
Tailwind CSS 4
ES Modules
```

ADR backend saat ini berhenti pada:

```text
ADR-019 — Dormitory Integration Boundary
```

Artinya kita **tidak perlu melakukan migration dari frontend lama**. Kita bisa membangun frontend architecture dengan clean baseline sejak awal.

Vite 8 sendiri baru dirilis pada Maret 2026 dan sudah memakai Rolldown sebagai bundler utamanya; Vite juga mendukung production code splitting. Jadi build foundation repository saat ini layak dipertahankan. ([vitejs][1])

---

# 2. Jangan mulai dari “Role & Permission UI”

Saya ingin membedakan dua hal.

### Platform Foundation

```text
Frontend Platform
│
├── Application Shell
├── Authentication
├── Authenticated Context
├── Membership / Tenant Context
├── Organizational Workspace
├── Capability Projection
├── API Client
├── Routing
├── Error Handling
├── State Management
├── Design System
└── Observability
```

### Platform Administration

```text
Administration
│
├── Tenant Management
├── User / Person Management
├── Membership Management
├── Role Management
├── Permission Management
└── Role / Permission Assignment
```

Dari 32 `/api/v1` routes yang ada sekarang, backend memang sudah mempunyai foundation untuk authentication, memberships, tenants, capability projection, role listing dan role assignment.

Tetapi **belum ada public hardened API lengkap untuk CRUD User/Person/Role/Permission**.

Jadi frontend PRD pertama jangan mengasumsikan bahwa kita sudah bisa membuat:

```text
/users
/roles/create
/permissions
/roles/{id}/permissions
```

secara penuh.

Kalau UI tersebut dibutuhkan, kita akan mendefinisikannya sebagai:

```text
Frontend Requirement
        ↓
Missing Backend Contract
        ↓
Backend API workstream / ADR jika perlu
        ↓
OpenAPI hardened
        ↓
Frontend implementation
```

Dengan begitu frontend tidak merusak foundation backend hanya demi memenuhi UI.

---

# 3. Arsitektur frontend yang saya sarankan sebagai baseline candidate

Saya belum ingin mengunci ini sebagai ADR, tetapi untuk EduCore saya menilai baseline paling kuat adalah:

| Concern              | Candidate                                    |
| -------------------- | -------------------------------------------- |
| Language             | **TypeScript strict**                        |
| UI library           | **React 19.x**                               |
| Build                | **Vite 8**                                   |
| Styling              | **Tailwind CSS 4**                           |
| Routing              | **React Router 7**                           |
| Server state         | **TanStack Query**                           |
| API contract         | **OpenAPI 3.1 → generated TypeScript types** |
| HTTP client          | native `fetch` / `openapi-fetch` wrapper     |
| Client state         | small dedicated store, kandidat Zustand      |
| Form                 | React Hook Form + schema validation          |
| Unit/component tests | Vitest + Testing Library                     |
| API mocking          | MSW                                          |
| E2E                  | Playwright                                   |
| Deployment           | static assets + CDN, API tetap Laravel       |
| Architecture         | feature/domain-oriented modular SPA          |

React documentation saat ini menunjukkan React **19.2** sebagai versi terbaru, sementara TypeScript memang dirancang memberi static type checking dan tooling untuk codebase berskala besar. ([React][2])

Untuk server state, TanStack Query secara khusus menyediakan query caching, background refetching, garbage collection dan mutation lifecycle, sehingga kita tidak perlu memasukkan hasil API ke satu global state store. ([TanStack][3])

---

# 4. Mengapa TypeScript harus menjadi requirement

Saya akan memasukkan ini sebagai **non-negotiable engineering requirement** PRD/ADR.

Karena EduCore akan memiliki contract seperti:

```text
User
Person
Membership
Tenant
Organization
OrganizationUnit
Workspace
Role
Permission
Capability
```

tanpa TypeScript, kesalahan seperti:

```javascript
membership.user_id;
workspace.id;
user.tenant_id;
```

bisa sangat mudah muncul kembali padahal domain tersebut sudah kita larang.

Dengan generated types dari OpenAPI:

```text
docs/api/openapi.yaml
        ↓
openapi-typescript
        ↓
generated API types
        ↓
API client
        ↓
feature layer
```

frontend bisa mendapat compile-time protection terhadap contract drift.

`openapi-typescript` saat ini mendukung OpenAPI 3.0/3.1 dan dapat menghasilkan TypeScript types tanpa runtime cost; `openapi-fetch` dapat menggunakan schema tersebut sebagai type-safe fetch client. ([OpenAPI TypeScript][4])

Ini sangat cocok dengan investasi yang sudah kita lakukan pada `docs/api/openapi.yaml`.

---

# 5. React vs Vue vs Next.js vs Inertia

Semua sebenarnya viable. Saya tidak ingin memilih React hanya karena populer.

| Pilihan              | Kelebihan                                                                  | Kekurangan untuk EduCore                                                      |
| -------------------- | -------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| **React + Vite SPA** | API-first, ecosystem besar, modular, cocok TanStack Query/OpenAPI          | Butuh architectural discipline lebih tinggi                                   |
| **Vue + Vite SPA**   | Convention lebih kuat, learning curve bagus, integrasi Vite sangat natural | Ecosystem enterprise React sedikit lebih luas                                 |
| **Next.js**          | SSR/RSC, public web/SEO sangat kuat                                        | Menambah Node rendering/runtime architecture yang belum dibutuhkan            |
| **Inertia**          | Sangat nyaman dengan Laravel, development cepat                            | Lebih server-route oriented dan mengurangi manfaat API-first/OpenAPI boundary |

Vue sendiri secara resmi menggunakan Vite untuk SPA scaffolding dan mempunyai TypeScript support yang baik. Jadi **Vue tetap alternatif sangat valid**. ([Vue.js][5])

Namun Inertia secara desain menekankan server-side routes dan bahkan tidak membutuhkan API. Itu justru berbeda dengan arah EduCore yang sudah membangun `/api/v1` dan OpenAPI sebagai contract formal. ([Inertia.js][6])

Next.js membawa App Router, Server Components dan server/client rendering architecture. Itu sangat berguna untuk website publik atau SEO-heavy application, tetapi belum memberikan keuntungan sebanding untuk authenticated ERP-like application EduCore. ([Next.js][7])

### Karena itu kandidat utama saya

```text
Laravel API
        │
        │ /api/v1
        ▼
React + TypeScript SPA
        │
        ├── React Router
        ├── TanStack Query
        ├── OpenAPI typed client
        └── Tailwind
```

Bukan:

```text
Laravel
   ↓
Blade / Inertia page routing
```

dan belum perlu:

```text
Laravel API
   ↓
Next.js SSR server
   ↓
Browser
```

---

# 6. Tentang target “ratusan ribu user”

Ini penting: **jumlah registered users tidak secara langsung menentukan framework frontend**.

React maupun Vue tidak perlu menjalankan satu server process per user jika kita menggunakan SPA.

Production architecture dapat berbentuk:

```text
                    ┌──────────────┐
Browser ───────────►│ CDN / Edge   │
                    └──────┬───────┘
                           │
                   JS/CSS/static
                           │

Browser
   │
   │ API requests
   ▼
Load Balancer
   │
   ▼
Laravel API
   │
   ├── Redis
   ├── Cache
   ├── Queue workers
   ├── PostgreSQL
   ├── Object storage
   └── Observability
```

Frontend bundle bisa di-cache dan disajikan dari CDN, sementara route-level chunks hanya dimuat sesuai kebutuhan. Vite menyediakan production code splitting untuk model tersebut. ([vitejs][8])

Jadi ketika nanti EduCore memiliki:

```text
100.000 users
500.000 users
1.000.000 users
```

frontend static delivery relatif mudah di-scale.

Yang jauh lebih menentukan adalah:

```text
concurrent active users
API request rate
database query efficiency
cache hit rate
queue throughput
file/storage traffic
WebSocket connections
reporting workload
```

Itu nanti menjadi bagian **NFR platform secara keseluruhan**, bukan alasan memakai framework frontend yang lebih kompleks.

---

# 7. Saya menyarankan tetap satu repository dahulu

Untuk fase pertama:

```text
educore/
│
├── app/
├── Modules/
├── docs/
│
└── resources/
    └── js/
        └── src/
```

bukan langsung:

```text
educore-backend/
educore-frontend/
```

Kita tetap memperoleh logical separation:

```text
Laravel
    = Backend/API

resources/js/src
    = Frontend Application
```

tetapi mendapat keuntungan:

```text
1 Pull Request
1 OpenAPI source of truth
1 CI pipeline
atomic backend + frontend contract changes
same-origin deployment
simpler local development
```

Nanti kalau organisasi/team/deployment memang membutuhkan independent frontend release cadence, pemisahan repo bisa dilakukan melalui ADR tersendiri.

---

# 8. Yang paling kritis: token architecture

Ini menurut saya kandidat **ADR frontend terpenting**.

Backend memberikan:

```json
{
  "access_token": "...",
  "token_type": "Bearer",
  "expires_in": 3600
}
```

Dan backend sengaja mengizinkan:

```text
Tab A
Tenant A
Token A

Tab B
Tenant B
Token B
```

`sessionStorage` memang dipartisi per browser tab, sehingga secara semantics sangat cocok untuk requirement tersebut. ([MDN Web Docs][9])

Tetapi ada masalah security.

OWASP sekarang secara eksplisit menyarankan agar authentication tokens tidak disimpan baik di `localStorage` maupun `sessionStorage`, karena JavaScript yang dieksekusi melalui XSS dapat membacanya. Mereka lebih memilih HttpOnly cookie atau BFF-style architecture. ([OWASP Cheat Sheet Series][10])

Kita punya trade-off:

| Strategy           | Reload | Multi-tab isolation |  XSS exposure |     Backend change |
| ------------------ | -----: | ------------------: | ------------: | -----------------: |
| Memory only        |     ❌ |                  ✅ |  lebih rendah |              tidak |
| `sessionStorage`   |     ✅ |                  ✅ |            ⚠️ |              tidak |
| `localStorage`     |     ✅ |                  ❌ |            ⚠️ |              tidak |
| HttpOnly cookie    |     ✅ |     biasanya shared | ✅ lebih baik |             **ya** |
| BFF/session broker |     ✅ |      bisa dirancang | ✅ lebih baik | **ya, signifikan** |

Karena itu saya sudah bisa mengatakan:

**`localStorage` sebaiknya kita eliminasi sebagai kandidat token storage EduCore.**

Ia buruk dari dua arah sekaligus:

```text
security
+
multi-tab tenant isolation
```

Sedangkan keputusan final antara:

```text
memory-only

vs

sessionStorage + strict XSS defenses

vs

new HttpOnly/BFF architecture
```

harus mempunyai ADR sendiri.

---

# 9. State architecture juga jangan dibuat satu global store

Saya mengusulkan ownership seperti ini:

```text
Frontend State
│
├── Authentication State
│   └── token lifecycle
│
├── Authenticated Context
│   ├── User
│   ├── Person
│   ├── Membership
│   └── Tenant
│
├── Organizational Context
│   └── selected workspace
│
├── Server State
│   └── TanStack Query
│
├── Capability Projection
│   └── server state/cache
│
├── Form State
│   └── local/form library
│
└── UI State
    ├── sidebar
    ├── dialogs
    └── preferences
```

Bukan:

```text
globalStore = {
  user,
  students,
  permissions,
  rooms,
  employees,
  grades,
  ...
}
```

Model kedua akan cepat menjadi sumber coupling ketika Academic, HR, Dormitory, PPDB dan modul-modul berikutnya masuk.

---

# 10. Proposed Frontend Module Boundaries

Frontend juga tidak harus meniru folder backend satu per satu.

Saya lebih menyukai:

```text
src/
├── app/
│
├── platform/
│   ├── api/
│   ├── auth/
│   ├── session/
│   ├── tenancy/
│   ├── workspace/
│   ├── authorization/
│   ├── routing/
│   └── observability/
│
├── features/
│   ├── tenant-management/
│   ├── membership-management/
│   └── role-assignment/
│
├── shared/
│   ├── ui/
│   ├── forms/
│   ├── errors/
│   └── utilities/
│
└── modules/
    ├── academic/      ← kemudian
    ├── hr/            ← kemudian
    └── dormitory/     ← kemudian
```

Dengan demikian:

```text
Core backend
≠
satu folder raksasa "core" frontend
```

Frontend Core lebih tepat menjadi **platform services + application infrastructure**.

---

# 11. Scope PRD pertama yang saya rekomendasikan

PRD pertama sebaiknya bernama kira-kira:

**EduCore Frontend Platform Foundation PRD**

Scope-nya:

```text
Application Shell
Authentication
Authenticated Bootstrap
Session Lifecycle
Membership Discovery
Tenant/Membership Switching
Workspace Discovery
Workspace Context
Capability-Aware UI
Route Protection
API Client
OpenAPI Integration
Canonical API Error Handling
Loading / Empty / Error / Recovery states
Logout
Frontend Observability
Responsive Application Shell
Accessibility baseline
Performance baseline
Frontend security baseline
```

Sedangkan:

```text
Student
Teacher
Employee
Dormitory
Academic Year
Grade
Attendance
PPDB
Finance
```

masuk PRD business-module berikutnya.

Untuk `Role/Permission`, foundation awal cukup mencakup:

```text
capability projection
role discovery
role assignment yang backend-nya sudah tersedia
route/menu authorization UX
```

CRUD permission/role yang belum mempunyai hardened API **tidak kita invent sendiri di frontend**.

---

# 12. Candidate ADR set setelah PRD locked

Saya akan sedikit merapikan kandidat handoff sebelumnya menjadi:

| ADR         | Decision                                             |
| ----------- | ---------------------------------------------------- |
| **ADR-020** | Frontend Rendering & Framework Strategy              |
| **ADR-021** | Frontend Modular Application Architecture            |
| **ADR-022** | Authentication Token Storage & Browser Session Model |
| **ADR-023** | Membership/Tenant Context Switching                  |
| **ADR-024** | Organizational Workspace Context                     |
| **ADR-025** | API Client, OpenAPI & Error Contract                 |
| **ADR-026** | Server State & Client State Ownership                |
| **ADR-027** | Capability-Aware Navigation & Route Authorization UX |
| **ADR-028** | Frontend Routing & Code-Splitting Strategy           |
| **ADR-029** | Frontend Testing Strategy                            |
| **ADR-030** | Frontend Security Baseline                           |
| **ADR-031** | Frontend Observability & Performance Strategy        |

Nomor itu **belum kita lock**, tetapi setelah pemeriksaan repository tadi, `ADR-020` memang nomor berikutnya yang tersedia.

---

# FE-0 Gate

Menurut saya FE-0 bisa ditutup dengan status:

```text
FE-0 — Frontend Contract Baseline & Scope Verification

Backend Contract        🔒 FROZEN
Current Frontend        ✅ CLEAN / NO LEGACY
Vite Foundation         ✅ KEEP
Tailwind Foundation     ✅ KEEP
OpenAPI                 ✅ CANONICAL CONTRACT
TypeScript              🟢 RECOMMENDED NON-NEGOTIABLE
SPA Architecture        🟢 RECOMMENDED
React                   🟢 PRIMARY CANDIDATE
Vue                     🟡 VALID ALTERNATIVE
Next.js                 ⚪ NOT JUSTIFIED YET
Inertia                 ⚪ NOT ALIGNED WITH API-FIRST DIRECTION
localStorage Token      🔴 REJECT CANDIDATE
Token Strategy          🟡 ADR REQUIRED
Role/Permission CRUD    ⚠️ BACKEND CONTRACT INCOMPLETE
Business Module UI      ⏭️ DEFER
```

Untuk target EduCore, baseline yang menurut saya paling layak kita bawa ke pembahasan PRD adalah:

```text
Laravel 13 API
+
React 19
+
TypeScript strict
+
Vite 8
+
Tailwind CSS 4
+
React Router
+
TanStack Query
+
OpenAPI-generated types/client
+
feature/domain modular architecture
+
CDN-ready static deployment
```

Ini cukup sederhana untuk dikembangkan sekarang, tetapi tidak membuat kita terjebak ketika jumlah user, modul, developer, dan volume data meningkat.

**Langkah berikutnya adalah FE-1 — Frontend Product Scope & Personas.** Di sana kita belum coding; kita akan menentukan siapa saja pengguna frontend awal—misalnya Super Admin Platform, Tenant Admin, Organization Admin, Staff/Operator, Teacher, dan user biasa—lalu dari persona tersebut menentukan **apa yang benar-benar harus masuk PRD Frontend Foundation v1** sebelum menyusun PRD lengkap.

[1]: https://vite.dev/blog/announcing-vite8?utm_source=chatgpt.com "Vite 8.0 is out!"
[2]: https://react.dev/versions?utm_source=chatgpt.com "React Versions"
[3]: https://tanstack.com/query/latest/docs/framework/react/overview?utm_source=chatgpt.com "Overview | TanStack Query React Docs"
[4]: https://openapi-ts.dev/?utm_source=chatgpt.com "OpenAPI TypeScript"
[5]: https://vuejs.org/guide/quick-start?utm_source=chatgpt.com "Quick Start"
[6]: https://inertiajs.com/?utm_source=chatgpt.com "Inertia.js - The Modern Monolith"
[7]: https://nextjs.org/docs?utm_source=chatgpt.com "Next.js Docs | Next.js"
[8]: https://vite.dev/guide/build?utm_source=chatgpt.com "Building for Production"
[9]: https://developer.mozilla.org/en-US/docs/Web/API/Window/sessionStorage?utm_source=chatgpt.com "Window: sessionStorage property - Web APIs | MDN"
[10]: https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html?utm_source=chatgpt.com "HTML5 Security - OWASP Cheat Sheet Series"
