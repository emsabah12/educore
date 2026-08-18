# FE-8 — Frontend Non-Functional Requirements

**Status:** 🔒 LOCKED / COMPLETE
**Scope:** EduCore Frontend Platform Foundation
**Depends on:** FE-0 sampai FE-7 🔒 LOCKED

---

## 1. Tujuan

Frontend EduCore harus tetap:

- cepat,
- aman,
- observable,
- accessible,
- resilient,
- maintainable,

ketika jumlah pengguna terdaftar berkembang hingga **ratusan ribu pengguna**.

Jumlah pengguna terdaftar tidak boleh disamakan dengan beban runtime.

Model kapasitas harus membedakan:

```text
Registered Users
        ↓
Monthly / Daily Active Users
        ↓
Concurrent Active Browser Sessions
        ↓
Concurrent API Requests
        ↓
API RPS
        ↓
Database / Queue / External Service Load
```

Frontend tidak bertanggung jawab menentukan kapasitas database atau backend.

Frontend bertanggung jawab memastikan bahwa pertumbuhan jumlah pengguna tidak menyebabkan arsitektur browser menjadi bottleneck melalui:

```text
oversized bundles
unbounded requests
unnecessary polling
global shared state
cache contamination
expensive rendering
uncontrolled telemetry
```

---

# 2. Scalability Model

Frontend menggunakan model:

```text
Browser
   │
   ├── static assets
   │      ↓
   │     CDN / Edge
   │
   └── API requests
          ↓
       Laravel API
```

Konsekuensi:

```text
Frontend static asset traffic
≠
API workload
```

Static frontend harus dapat dilayani secara horizontal melalui CDN tanpa membutuhkan application server khusus untuk setiap browser session.

Tidak boleh terdapat requirement bahwa frontend SPA membutuhkan:

```text
sticky session
server-side React process
per-user Node.js runtime
```

untuk operasi normal.

---

# 3. Performance Objectives

Frontend harus menggunakan **user-perceived performance** sebagai metrik utama.

Target produksi:

| Metric                    |                                          Target |
| ------------------------- | ----------------------------------------------: |
| LCP                       |                                 ≤ 2.5 detik p75 |
| INP                       |                                    ≤ 200 ms p75 |
| CLS                       |                                       ≤ 0.1 p75 |
| Initial application shell | harus usable tanpa memuat semua business module |
| Route/module loading      |                                     lazy-loaded |
| Background refetch        |        tidak memblok UI valid yang sedang aktif |

Target tersebut dievaluasi dari real-user telemetry ketika production monitoring tersedia.

Development machine benchmark tidak boleh menjadi satu-satunya performance evidence.

---

# 4. JavaScript Bundle Budget

Frontend tidak boleh menghasilkan satu application bundle monolitik.

Baseline target:

```text
Initial critical JS:
≤ 300 KB gzip

Normal route incremental chunk:
target ≤ 150 KB gzip

Large specialized feature:
harus lazy-loaded
```

Nilai ini merupakan **engineering budget**, bukan jaminan bahwa setiap feature selalu berada tepat di bawah angka tersebut.

CI harus mendeteksi bundle regression.

Peningkatan bundle signifikan harus memiliki alasan eksplisit.

Contoh:

```text
Current:
280 KB

New build:
410 KB

→ CI / review warning
→ dependency investigation
→ architecture justification jika memang diperlukan
```

Business module seperti:

```text
Academic
HR
Dormitory
Finance
Library
```

tidak boleh menjadi bagian dari initial bundle apabila belum dibutuhkan oleh route aktif.

---

# 5. Code Splitting Strategy

Code splitting minimum dilakukan pada:

```text
Application Foundation
        │
        ├── Authentication
        ├── Dashboard
        │
        └── Business Modules
                ├── Academic
                ├── HR
                ├── Dormitory
                └── ...
```

Business module kemudian dapat melakukan code splitting internal.

Contoh:

```text
Dormitory
├── Dashboard
├── Buildings
├── Rooms
├── Residents
└── Placements
```

Tidak semua halaman Dormitory harus dimuat ketika pengguna hanya membuka Dormitory Dashboard.

---

# 6. Static Asset Caching

Build artifacts harus menggunakan content hashing.

Contoh:

```text
assets/app.42d91f.js
assets/vendor.7ac821.js
```

Hashed assets:

```text
Cache-Control:
public, max-age=31536000, immutable
```

Application HTML/bootstrap document tidak boleh menggunakan immutable long-term caching.

Tujuannya:

```text
index.html
→ selalu dapat menemukan deployment terbaru

hashed assets
→ dapat dicache agresif
```

Deployment harus menjaga compatibility selama transition sehingga browser yang masih menggunakan document lama tidak langsung menerima missing asset.

---

# 7. Service Worker Policy

Service Worker / full offline application support **tidak menjadi requirement Foundation v1**.

Alasan:

```text
service worker
+
authenticated multi-tenant application
+
rapid deployments
+
context-sensitive data
```

meningkatkan risiko:

```text
stale application shell
stale API data
version mismatch
context leakage
complex cache invalidation
```

PWA/offline support dapat ditambahkan kemudian melalui requirement dan ADR tersendiri.

---

# 8. Server-State Caching

TanStack Query direkomendasikan sebagai server-state layer.

Query identity harus context-aware.

Canonical conceptual identity:

```text
resource
+
tenant/membership
+
workspace
+
request parameters
```

Contoh:

```text
[
  "academic",
  "students",
  membershipId,
  workspaceId,
  filters
]
```

Tidak boleh hanya:

```text
["students"]
```

karena:

```text
Tenant A students
≠
Tenant B students
```

dan:

```text
Workspace X result
≠
Workspace Y result
```

---

# 9. Context-Safe Cache

Ketika Tenant/Membership berubah:

```text
old Tenant server state
→ no longer active
```

Ketika Workspace berubah:

```text
workspace-sensitive queries
→ stale / invalidated
```

Response dari request lama harus diperiksa terhadap context generation/current context sebelum memengaruhi active UI.

Canonical principle:

```text
Request started under Context A
        ↓
Context changes to B
        ↓
Request A completes
        ↓
MUST NOT update Context B UI
```

---

# 10. Concurrent Request Management

Frontend harus menghindari request amplification.

Required behavior:

```text
duplicate GET
→ deduplicate where possible

superseded request
→ cancel / ignore result

route abandoned
→ obsolete request should not mutate active UI

context switch
→ cancel or invalidate old-context requests
```

Frontend tidak boleh menggunakan polling tanpa bounded interval dan explicit business requirement.

Default:

```text
polling = OFF
```

Realtime/WebSocket/SSE harus menjadi requirement tersendiri apabila business module membutuhkannya.

---

# 11. Mutation Safety

Automatic retries untuk mutation tetap:

```text
OFF by default
```

untuk:

```text
POST
PUT
PATCH
DELETE
Tenant Switch
Role Assignment
Permission Assignment
Payment
Capacity Allocation
Placement
```

Retry hanya dapat digunakan jika endpoint secara eksplisit memiliki idempotency guarantee.

Mutation UI harus mencegah accidental double-submit.

---

# 12. Authentication Security

Keputusan credential storage tetap deferred ke:

```text
ADR — Authentication Credential Storage
& Browser Session Isolation
```

Namun FE-8 menetapkan security invariants berikut:

```text
Bearer credential MUST NOT:

- masuk URL
- masuk query string
- masuk browser history
- masuk logs
- masuk analytics
- masuk telemetry
- disimpan localStorage
- tersinkronisasi global antar-tab
```

Password:

```text
MUST NOT

→ persisted
→ cached
→ logged
→ tracked
→ included in telemetry
```

---

# 13. XSS Protection

Frontend harus memperlakukan XSS sebagai ancaman utama, terutama selama browser credential masih dapat berada dalam JavaScript runtime.

Baseline:

```text
No unsafe HTML injection by default
No eval-based application logic
No rendering raw untrusted HTML
No secrets embedded in frontend build
```

Penggunaan API seperti:

```text
dangerouslySetInnerHTML
```

harus dilarang secara default.

Jika business requirement benar-benar membutuhkan rich HTML:

```text
untrusted HTML
→ approved sanitizer
→ constrained rendering
```

bukan rendering langsung.

---

# 14. Content Security Policy

Production deployment harus mendukung CSP.

Baseline direction:

```text
default-src 'self'
object-src 'none'
base-uri 'self'
frame-ancestors 'none'
```

Directive:

```text
script-src
style-src
img-src
font-src
connect-src
```

harus dibatasi berdasarkan resource yang benar-benar digunakan.

Tidak boleh membuka CSP menggunakan wildcard luas hanya untuk mengatasi konfigurasi library.

Final CSP detail diselesaikan melalui:

```text
Frontend Security ADR
+
deployment configuration
```

---

# 15. CSRF Strategy

CSRF requirement bergantung pada keputusan credential architecture.

Jika frontend menggunakan:

```text
Authorization: Bearer ...
```

yang secara eksplisit ditambahkan oleh JavaScript:

```text
traditional cookie CSRF exposure
→ significantly reduced
```

Jika ADR memilih:

```text
HttpOnly authentication cookie
```

maka arsitektur wajib memiliki:

```text
SameSite policy
+
CSRF protection/token strategy
+
Origin / request validation where appropriate
```

Karena itu FE-8 tidak mengunci mekanisme CSRF sebelum ADR credential storage selesai.

---

# 16. Frontend Secrets

Frontend dianggap public environment.

Tidak ada build-time variable yang dikirim ke browser boleh dianggap secret.

Contoh yang boleh:

```text
API base URL
application version
public observability endpoint
feature configuration intended for clients
```

Contoh yang tidak boleh:

```text
database credentials
private API keys
signing keys
service account credentials
backend secrets
```

Prefix environment variable frontend bukan security boundary.

---

# 17. Dependency Security

Frontend dependencies harus:

```text
version controlled
lockfile committed
reviewable
auditable
```

CI harus melakukan dependency vulnerability scanning.

Dependency baru harus dievaluasi terhadap:

```text
necessity
bundle size
maintenance
security history
licensing
browser impact
```

Tidak boleh memasukkan package besar untuk functionality kecil yang dapat diselesaikan dengan platform API secara aman dan sederhana.

---

# 18. Accessibility

Foundation target:

```text
WCAG 2.2 AA
```

terutama untuk shared platform components.

Required baseline:

```text
keyboard navigation
visible focus
semantic HTML
accessible form labels
accessible validation messages
accessible dialogs
accessible menus
accessible loading state
screen-reader compatible navigation
sufficient color contrast
```

Accessibility tidak boleh ditambahkan sebagai tahap kosmetik setelah aplikasi selesai.

Shared UI component harus membawa accessibility behavior secara default.

---

# 19. Reduced Motion

UI yang menggunakan animation harus menghormati:

```text
prefers-reduced-motion
```

Animasi tidak boleh menjadi requirement untuk memahami state aplikasi.

---

# 20. Browser Support

Frontend Foundation harus mendukung browser evergreen modern yang masih menerima security updates.

Baseline policy:

```text
latest stable Chrome family
latest stable Edge
latest stable Firefox
latest stable Safari
```

Support matrix harus dikelola sebagai policy CI/build, bukan dengan browser-specific hacks tersebar di feature code.

Legacy browser support tidak menjadi requirement kecuali terdapat business requirement eksplisit.

---

# 21. Responsive Support

Primary usage:

```text
desktop-first
```

tetapi aplikasi tetap harus responsive.

Minimum layout classes:

```text
Desktop
Tablet
Mobile
```

Responsive tidak berarti setiap data-heavy administrative screen harus identik pada mobile.

Pada layar kecil frontend dapat:

```text
reflow
collapse
prioritize
hide secondary presentation information
```

selama functional capability utama tetap accessible bila memang didukung oleh product requirement.

---

# 22. Observability

Frontend harus memiliki observability layer terpusat.

Minimum categories:

```text
Runtime errors
API failures
Performance metrics
Application version
Route/module context
non-sensitive runtime metadata
```

Business feature tidak boleh memanggil observability vendor secara langsung.

Architecture:

```text
Business Feature
      ↓
Observability Port
      ↓
Observability Adapter
      ↓
Vendor / Platform
```

Dengan demikian vendor dapat diganti tanpa mengubah semua feature.

---

# 23. Sensitive Data Redaction

Observability system harus default ke:

```text
privacy-first
```

Tidak boleh mengirim:

```text
Authorization header
bearer credential
password
session credential
raw sensitive form data
full API request payload
full API response payload
```

Tenant ID, Membership ID, atau User identifier hanya boleh digunakan jika memiliki operational justification dan mengikuti privacy policy aplikasi.

---

# 24. Correlation

Frontend sebaiknya mempertahankan correlation identifier yang diberikan backend apabila tersedia.

Contoh:

```text
request_id
trace_id
correlation_id
```

Sehingga incident dapat ditelusuri:

```text
Browser event
↓
API request
↓
backend log
↓
database/service operation
```

tanpa merekam credential.

---

# 25. Application Version Observability

Setiap deployment frontend harus memiliki version identifier.

Contoh:

```text
git SHA
release version
build identifier
```

Runtime error report minimal dapat mengidentifikasi:

```text
environment
frontend version
route
error class
```

agar error production dapat dikaitkan dengan release tertentu.

---

# 26. Privacy

Frontend harus mengikuti prinsip:

```text
minimum necessary collection
```

Analytics tidak boleh menjadi tempat dumping application state.

Sensitive domain data dari:

```text
students
employees
residents
parents
financial transactions
```

tidak boleh otomatis masuk ke analytics atau error telemetry.

Event analytics harus mendeskripsikan interaction, bukan mereplikasi domain payload.

Contoh:

```text
GOOD:
student_list_filter_applied

BAD:
student_list_filter_applied {
  students: [...]
}
```

---

# 27. Runtime Resilience

Frontend harus memiliki:

```text
Application Error Boundary
+
Route/Module Error Boundaries
```

Failure pada satu module sebisa mungkin tidak menghilangkan platform controls.

Contoh:

```text
Dormitory runtime crash
        ↓

Sidebar              survives where safe
Topbar               survives where safe
Tenant context       survives
Workspace controls   survives where safe
Logout               survives
```

Jika root application state sendiri tidak dapat dipercaya, frontend harus fail safely.

---

# 28. Network Resilience

Frontend harus membedakan:

```text
offline/network failure
authentication failure
authorization failure
server error
```

Network failure:

```text
MUST NOT automatically logout user
```

UX harus memungkinkan bounded retry untuk read operation.

Current valid data boleh tetap ditampilkan saat background refetch gagal, dengan stale/error indication jika diperlukan.

---

# 29. Deployment Resilience

Frontend static deployment harus dapat:

```text
roll forward
+
roll back
```

tanpa melakukan rebuild manual di production server.

Deployment artifact harus immutable.

Target model:

```text
CI Build
   ↓
Immutable Artifact
   ↓
CDN/Object Storage
   ↓
Release Activation
```

Rollback:

```text
Previous tested artifact
→ reactivate
```

bukan:

```text
edit production files manually
```

---

# 30. API Compatibility

Frontend deployment dan backend deployment tidak boleh diasumsikan terjadi pada millisecond yang sama.

Frontend harus menggunakan versioned API:

```text
/api/v1
```

dan OpenAPI contract.

Breaking API changes:

```text
MUST NOT
```

dilakukan diam-diam.

Jika breaking contract diperlukan:

```text
backend requirement
↓
contract change
↓
OpenAPI
↓
client generation
↓
frontend adaptation
```

---

# 31. OpenAPI Client Generation

Canonical API schema menjadi source untuk generated TypeScript contracts/client.

Frontend tidak boleh menduplikasi secara manual DTO yang sudah didefinisikan oleh canonical API contract tanpa alasan.

Contoh undesirable:

```text
Backend:
UserDto

Frontend manually recreated:
interface User {...}
```

jika type yang sama dapat dihasilkan dari OpenAPI.

Generated code harus ditempatkan di boundary yang jelas dan tidak diedit manual.

---

# 32. Type Safety

TypeScript:

```text
strict = true
```

Required.

Tidak boleh menjadikan:

```text
any
```

sebagai escape hatch umum.

Unknown external data harus diperlakukan sebagai:

```text
unknown
```

sampai tervalidasi atau ditentukan type-nya oleh canonical generated contract.

---

# 33. Runtime Contract Failure

Compile-time TypeScript tidak menjamin backend runtime selalu benar.

Frontend harus mempunyai canonical handling untuk:

```text
unexpected response
unsupported error code
missing required response state
malformed contract
```

Unknown contract error harus:

```text
fail safely
+
be observable
```

bukan menyebabkan silent corruption.

---

# 34. Testability

Frontend architecture harus mendukung empat lapisan testing:

```text
Unit
Component
Integration
End-to-End
```

Critical platform flows harus memiliki integration/E2E coverage.

Minimum critical flows:

```text
Login
/auth/me bootstrap
Logout

Membership discovery
Tenant switch
Tenant switch failure

Workspace discovery
Workspace switch
Stale workspace recovery

Capability loading
Capability denied
Capability refresh

401 authentication invalid
403 authorization denied
validation errors
network failures
```

Code coverage percentage bukan satu-satunya quality measurement.

Critical behavior tidak boleh dianggap aman hanya karena global coverage tinggi.

---

# 35. Test Isolation

Testing harus dapat memverifikasi secara eksplisit bahwa:

```text
Tenant A state
MUST NOT contaminate
Tenant B
```

dan:

```text
Workspace X state
MUST NOT contaminate
Workspace Y
```

Race-condition tests diperlukan untuk:

```text
request A starts
context switch occurs
request A finishes
```

dan memastikan old response diabaikan.

---

# 36. Maintainability

Frontend mengikuti modular ownership.

Target direction:

```text
src/
├── app/
├── platform/
├── features/
├── shared/
└── modules/
```

Platform foundation tidak boleh mengetahui detail internal setiap business module.

Business modules harus mengonsumsi platform contracts.

Contoh:

```text
Dormitory
        ↓
uses Authentication API
uses Context API
uses Authorization API
uses Error API
uses Observability API
```

bukan membuat sistem sendiri.

---

# 37. Architecture Boundary Enforcement

CI/lint tooling sebaiknya mampu mendeteksi forbidden imports.

Contoh:

```text
Dormitory
→ Academic internal implementation
```

harus ditolak jika tidak melalui explicit shared/public contract.

Demikian pula:

```text
shared/
```

tidak boleh bergantung kepada:

```text
modules/dormitory
```

Boundary harus menghasilkan dependency direction yang konsisten.

---

# 38. State Ownership

State harus dipisah berdasarkan responsibility.

```text
Server State
→ TanStack Query

Authentication Credential State
→ Session architecture

Context State
→ Membership / Workspace layer

Form State
→ form-local / form library

Transient UI State
→ component/local UI state
```

Tidak boleh membuat satu global store yang menjadi source untuk semuanya.

Rejected direction:

```text
globalStore = {
    auth,
    permissions,
    workspace,
    students,
    employees,
    rooms,
    forms,
    navigation,
    ...
}
```

---

# 39. CI Quality Gates

Frontend CI minimum harus menjalankan:

```text
dependency install from lockfile
↓
format/lint validation
↓
TypeScript type check
↓
unit/component tests
↓
production build
↓
bundle budget check
↓
OpenAPI/client drift check
↓
security/dependency audit
↓
integration tests
↓
critical E2E smoke tests
```

PR tidak boleh digabung jika mandatory quality gate gagal.

---

# 40. Production Build

Production build harus:

```text
minified
tree-shaken
code-split
content-hashed
source-map policy controlled
```

Production error UI tidak boleh menampilkan:

```text
raw stack trace
source code details
internal filesystem path
```

Jika source maps dikirim ke observability provider, aksesnya harus dibatasi dan tidak otomatis dipublikasikan ke end user.

---

# 41. CDN / Static Deployment

Target deployment:

```text
Browser
   ↓
CDN
   ↓
Static Frontend Assets

Browser
   ↓
API Domain
   ↓
Laravel
```

Frontend static assets dapat discale melalui CDN independently dari Laravel application capacity.

Ini menjadi scalability property penting untuk target jumlah pengguna besar.

---

# 42. Environment Strategy

Minimum environment classes:

```text
Development
Test / CI
Staging
Production
```

Environment-specific behavior tidak boleh mengubah core authorization semantics.

Contoh yang dapat berbeda:

```text
API endpoint
observability endpoint
telemetry enabled/disabled
debug metadata
```

Contoh yang tidak boleh berbeda:

```text
authorization bypass
tenant isolation
credential security rules
```

---

# 43. Feature Flagging

Foundation tidak memerlukan enterprise feature-flag platform pada hari pertama.

Namun architecture tidak boleh mengasumsikan deployment selalu berarti feature langsung aktif untuk semua pengguna.

Jika feature flag ditambahkan:

```text
flag
≠ authorization
```

Feature flag menentukan:

```text
feature rollout
```

Capability menentukan:

```text
whether current context is allowed to use it
```

Backend tetap final authority.

---

# 44. Availability

Frontend availability bergantung pada:

```text
CDN availability
DNS
network
API availability
```

Static frontend yang berhasil dimuat tidak berarti application operational jika API unavailable.

Health UX harus membedakan:

```text
frontend boot failure
API unavailable
network unavailable
authentication invalid
```

---

# 45. Performance Monitoring

Production monitoring harus mampu melihat regression berdasarkan release.

Minimal dimensions:

```text
application version
route
device/browser category
performance metric
error category
```

Tujuan:

```text
Release N
LCP = healthy

Release N+1
LCP regression

→ observable
→ traceable
→ actionable
```

---

# 46. Scalability Anti-Patterns

Foundation secara eksplisit melarang menjadikan hal berikut sebagai default:

```text
one gigantic bundle
one gigantic global store
loading all modules at startup
unbounded polling
unbounded retries
duplicated requests
automatic mutation retries
cross-tenant shared cache keys
cross-tab credential synchronization
raw telemetry payloads
browser-side authorization calculation
```

---

# 47. Non-Functional Acceptance Criteria

FE-8 dianggap terpenuhi ketika architecture dan implementation nanti dapat membuktikan bahwa:

```text
1. Business modules lazy-load independently.

2. Initial application does not load every business module.

3. Static hashed assets support long-term immutable caching.

4. Tenant/Workspace cache identity is context-aware.

5. Old-context response cannot replace active-context data.

6. Duplicate/superseded reads can be deduplicated or cancelled.

7. Mutation retries are disabled unless explicitly safe.

8. localStorage is not used for bearer credentials.

9. Password/credential data never enters telemetry.

10. CSP can be applied in production.

11. Shared components meet accessibility baseline.

12. Runtime module failures are isolated through error boundaries.

13. TypeScript strict mode remains enabled.

14. OpenAPI is canonical API contract input.

15. CI checks type safety, tests, build and bundle regression.

16. Frontend artifacts can be delivered through CDN/static infrastructure.

17. Deployment artifact is immutable and rollback-capable.

18. Production performance and runtime errors are observable per release.

19. No business module implements independent authentication,
    tenant context or authorization architecture.

20. Frontend capacity model distinguishes registered users from
    concurrency/API/database workload.
```

---

# 48. Explicitly Deferred

FE-8 does not decide:

```text
exact bearer credential storage
refresh token architecture
BFF architecture
MFA
offline/PWA
realtime/WebSocket/SSE
observability vendor
analytics vendor
feature flag vendor
```

Those items require either:

```text
ADR
```

or:

```text
future product requirement
```

depending on architectural significance.

---

# 49. ADR Inputs Created by FE-8

FE-8 strengthens the need for:

```text
ADR-020 Frontend Framework & Rendering Strategy

ADR-021 Frontend Modular Application Architecture

ADR-022 Authentication Credential Storage
        & Browser Session Isolation

ADR-023 Tenant / Membership Context Switching

ADR-024 Workspace / Organizational Context Management

ADR-025 API Client, OpenAPI
        & Canonical Error Handling

ADR-026 Server-State & Client-State Ownership

ADR-027 Capability-Aware Navigation
        & Authorization UX

ADR-028 Routing & Code-Splitting Strategy

ADR-029 Frontend Testing Strategy

ADR-030 Frontend Security Baseline

ADR-031 Frontend Observability
        & Performance Strategy
```

Final ADR numbering tetap harus diverifikasi terhadap repository sebelum ADR pertama dibuat.

---

# 50. FE-8 Decision Summary

Proposed foundation:

```text
Static SPA + CDN delivery
                ✅

React/Vite route-level code splitting
                ✅

Initial bundle budget
                ✅

Context-safe server-state caching
                ✅

Bounded concurrent requests
                ✅

Default polling
                ❌

Default mutation retry
                ❌

Service worker / offline v1
                ❌

TypeScript strict
                ✅

OpenAPI-generated contract
                ✅

WCAG 2.2 AA target
                ✅

CSP production baseline
                ✅

Privacy-safe observability
                ✅

Immutable deployment artifact
                ✅

Rollback capability
                ✅

Frontend vendor lock-in
                ❌
```

---

# FE-8 Locked State

```text
FE-8 — Frontend Non-Functional Requirements

🔒 LOCKED / COMPLETE
```
