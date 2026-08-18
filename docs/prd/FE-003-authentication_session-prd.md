Baik. **FE-2 kita lock** dan kita masuk hanya ke **FE-3 — Authentication & Session PRD**.

Ada satu tambahan penting dari audit source sebelumnya: kontrak backend saat ini memang sudah mempunyai **tenant-aware login**, `/auth/me`, logout dengan token revocation, dan membership switch yang menerbitkan token baru. Jadi FE-3 tidak perlu merancang ulang authentication backend; tugas kita adalah mendefinisikan bagaimana browser/frontend mengonsumsinya secara aman dan konsisten.

# FE-3 — Authentication & Session PRD

## 1. Tujuan

FE-3 harus menetapkan lifecycle berikut:

```text
Unauthenticated
      │
      ▼
Login
      │
      ▼
Credential established
      │
      ▼
Authenticated Bootstrap
      │
      ▼
Application Session
      │
      ├── Reload
      ├── Tenant/Membership Switch
      ├── Session Expiration
      ├── Token Revocation
      └── Logout
```

Dan ada empat property yang menurut saya wajib:

```text
SECURE
PREDICTABLE
PER-TAB ISOLATED
RECOVERABLE
```

---

# 2. Canonical Backend Contract yang Tidak Diubah

Backend authentication tetap membawa:

```text
user_id
tenant_id
membership_id
expires_at
```

dan bukan:

```text
role
permission
organization
organization_unit
```

Setelah login atau reload, `/api/v1/auth/me` menjadi sumber verified application identity untuk `User`, `Person`, `Membership`, dan `Tenant`. Membership switch menghasilkan credential baru untuk Membership/Tenant target; credential lama tidak otomatis hilang hanya karena switch dilakukan.

Dengan demikian frontend **tidak boleh menjadikan decoded token sebagai application state authority**.

Jangan:

```typescript
const claims = decodeToken(token);

auth.userId = claims.user_id;
auth.tenantId = claims.tenant_id;
```

Sebaliknya konsepnya:

```text
Bearer credential
      ↓
GET /api/v1/auth/me
      ↓
Verified Authenticated Context
      ↓
Frontend Context
```

Token adalah **credential**, bukan frontend domain model.

---

# 3. Session State Machine

Saya menyarankan kita mengunci explicit state machine berikut:

```text
UNAUTHENTICATED
       │ login
       ▼
AUTHENTICATING
       │ success
       ▼
BOOTSTRAPPING
       │ /auth/me success
       ▼
AUTHENTICATED
       │
       ├── tenant switch
       │       ↓
       │   CONTEXT_SWITCHING
       │
       ├── logout
       │       ↓
       │   LOGGING_OUT
       │
       └── credential invalid/expired
               ↓
            EXPIRED
               ↓
        UNAUTHENTICATED
```

Ini lebih aman daripada boolean:

```typescript
isLoggedIn: true | false;
```

karena pada SPA nyata ada kondisi:

```text
punya credential
tetapi bootstrap belum selesai
```

yang tidak sama dengan application-ready authenticated state.

---

# 4. Login Contract

Evidence backend saat ini menunjukkan login membutuhkan:

```text
email
password
tenant_uuid
```

dan backend menolak wrong tenant, malformed tenant UUID, invalid credentials, dan invalid input.

Namun saya **tidak merekomendasikan pengguna biasa mengetik UUID Tenant secara manual**.

UUID adalah identifier teknis:

```text
019c...
```

bukan product-facing institution identity.

Maka product requirement-nya sebaiknya:

> **Login harus memiliki Tenant context, tetapi raw Tenant UUID tidak boleh menjadi primary user-facing credential.**

Conceptual login:

```text
Institution
[ Pesantren Al-Falah ]

Email
[ ahmad@example.com ]

Password
[ **************** ]

[ Sign in ]
```

Frontend kemudian mengirim:

```json
{
  "email": "ahmad@example.com",
  "password": "...",
  "tenant_uuid": "<resolved UUID>"
}
```

---

# 5. Tenant Resolution Sebelum Login

Di sini ada requirement baru yang perlu kita catat secara eksplisit.

Current frozen API contract menjelaskan `tenant_uuid`, tetapi **belum memberikan user-facing pre-auth tenant discovery contract**.

Saya tidak ingin diam-diam membuat:

```text
GET /tenants?q=...
```

karena tenant enumeration sebelum authentication mempunyai security/product implications.

Saya menyarankan requirement:

```text
Login Entry
    ↓
Tenant context already resolvable
    ↓
Login API
```

Tenant context dapat nantinya berasal dari salah satu strategy:

```text
tenant-specific URL
subdomain
institution code
invitation/login link
controlled tenant discovery
```

**Pilihan teknisnya belum kita lock pada FE-3.**

Jika membutuhkan tambahan backend contract, itu menjadi additive workstream/ADR baru; bukan membuka ulang Identity/Tenancy foundation.

---

# 6. “Remember Me”

Untuk Foundation v1 saya menyarankan:

```text
Remember Me
❌ NOT IN SCOPE
```

Alasannya bukan karena fitur tersebut buruk, tetapi backend sekarang mempunyai finite bearer-token lifetime dan kita belum mempunyai hardened refresh-token/session-renewal contract.

Jangan membuat checkbox:

```text
☑ Remember me
```

yang pada akhirnya hanya berarti:

```text
put bearer token in localStorage forever
```

Itu akan menurunkan security hanya demi UX.

---

# 7. Reload Behaviour

Requirement penting:

> **Normal browser refresh tidak seharusnya memaksa user login kembali selama credential masih valid.**

Flow yang diinginkan:

```text
Browser Reload
      ↓
Recover tab credential
      ↓
GET /auth/me
      ↓
valid
      ↓
Rebuild authenticated context
      ↓
Application Ready
```

Jika credential sudah expired/revoked:

```text
Reload
   ↓
/auth/me → 401
   ↓
Clear local authentication state
   ↓
Login
```

Ini membuat `/auth/me` menjadi canonical **session verification boundary**.

---

# 8. Bootstrap Screen

Jangan menampilkan sidebar setengah jadi sebelum bootstrap selesai.

Saat application dibuka:

```text
Credential exists
      ↓
BOOTSTRAPPING
```

UX dapat berupa application-level loading state:

```text
EduCore

Restoring your session...
```

Bukan:

```text
Sidebar appears
User = undefined
Tenant = undefined
Workspace = old state
```

Baru setelah authenticated context verified:

```text
AUTHENTICATED
```

shell dirender.

---

# 9. Jangan Mempercayai Cached User Context Saat Reload

Misalnya frontend sebelumnya menyimpan:

```json
{
  "user": "...",
  "tenant": "Tenant A",
  "membership": "..."
}
```

Cached projection tersebut boleh membantu UI sementara, tetapi tidak boleh dianggap verified session setelah application start.

Canonical bootstrap tetap:

```text
GET /auth/me
```

karena membership bisa:

```text
inactive
```

Tenant bisa:

```text
inactive
```

User bisa:

```text
suspended
```

atau token dapat:

```text
expired/revoked
```

setelah browser terakhir ditutup.

---

# 10. Session Expiration

Frontend kemungkinan mengetahui token lifetime dari:

```text
expires_in
```

Tetapi timer frontend hanya:

> **UX hint**

bukan final credential validity.

Frontend boleh memperkirakan:

```text
Session expires in ~10 minutes
```

tetapi backend response tetap authority.

Canonical rule:

```text
Client timer says valid
+
API says 401

→ session is invalid
```

bukan sebaliknya.

---

# 11. Tidak Ada Silent Refresh pada Foundation v1

Saya menyarankan:

```text
Silent token refresh
❌ OUT OF SCOPE
```

karena tidak ada refresh-token contract pada frozen foundation.

Saat credential benar-benar berakhir:

```text
401 credential expired
      ↓
clear auth state
      ↓
redirect login
```

Kita jangan menciptakan pseudo-refresh menggunakan username/password cache atau teknik lain di browser.

Kalau nanti UX membutuhkan long-lived session:

```text
Refresh Session PRD
        ↓
Security review
        ↓
Backend contract
        ↓
ADR
```

baru ditambahkan.

---

# 12. Session Expiration UX

Jika user hanya sedang browsing:

```text
Session expired.
Please sign in again.
```

Jika user sedang mengisi form, kita harus sebisa mungkin tidak menghancurkan unsaved UI state secara instan.

Desired behavior:

```text
API request
   ↓
401 session expired
   ↓
mark auth state EXPIRED
   ↓
prevent further protected mutation
   ↓
redirect/authenticate
```

Preservasi draft form secara aman akan kita bahas lebih detail pada **FE-7 Error/Recovery UX**.

---

# 13. Logout

Backend saat ini sudah mempunyai actual token revocation; regression membuktikan revoked token tidak dapat kembali menggunakan tenant context dan raw token tidak disimpan sebagai revocation record.

Frontend logout flow saya rekomendasikan:

```text
User clicks Sign out
       ↓
state = LOGGING_OUT
       ↓
POST logout
       ↓
server revokes current bearer credential
       ↓
clear auth credential
       ↓
clear User/Person
       ↓
clear Membership/Tenant
       ↓
clear Workspace
       ↓
clear capability cache
       ↓
clear tenant/workspace server cache
       ↓
Login
```

---

# 14. Logout Harus Fail Secure

Ada edge case:

```text
POST logout
       ↓
network failure
```

Frontend tetap harus membuang credential lokal ketika user secara eksplisit meminta logout.

Jadi:

```text
Server logout
=
best attempt to revoke

Client logout
=
always remove local credential
```

Tetapi jika server revocation gagal karena network, UI jangan mengklaim bahwa seluruh server session sudah pasti dicabut.

Observability/logging dapat mencatat failure **tanpa token value**.

---

# 15. Logout Scope

Ini penting karena multi-tab.

Misalnya:

```text
Tab A → Token A → Tenant A
Tab B → Token B → Tenant B
```

User logout dari Tab A.

Current backend semantics mendukung token-specific revocation.

Maka:

```text
Tab A
→ logged out

Tab B
→ SHOULD remain authenticated
```

Ini konsisten dengan requirement multi-tab isolation.

Jadi Foundation v1 mendefinisikan:

> **Logout = logout current browser-tab authentication context.**

Bukan:

> Logout seluruh device/account.

---

# 16. “Logout All Devices”

Saya sarankan:

```text
Sign out all sessions
⏭ FUTURE
```

Karena itu membutuhkan server-side operation yang berbeda:

```text
revoke all tokens for User

atau

revoke all tokens for Membership

atau

credential generation/version rotation
```

Tidak boleh kita fake hanya dengan menghapus storage browser saat ini.

---

# 17. Multi-Tab Isolation

Ini non-negotiable.

```text
Tab A
────────────
Tenant A
Membership A
Workspace A
Token A


Tab B
────────────
Tenant B
Membership B
Workspace B
Token B
```

Frontend tidak boleh melakukan:

```text
Tab A changes tenant
      ↓
Broadcast token
      ↓
Tab B suddenly becomes same tenant
```

Karena itu:

```text
cross-tab credential synchronization
❌
```

dan:

```text
cross-tab tenant synchronization
❌
```

Default state isolation adalah **per tab**.

---

# 18. Kenapa `localStorage` Kita Tolak

Ini sekarang layak dikunci sebagai requirement, bukan sekadar kandidat ADR.

`localStorage` bersifat origin-wide dan persistent, sedangkan `sessionStorage` dipartisi berdasarkan origin **dan tab**. Dengan demikian `localStorage` secara semantics bertentangan dengan kebutuhan Tenant A/Tab A dan Tenant B/Tab B. ([MDN Web Docs][1])

Lebih penting lagi, OWASP secara eksplisit menyarankan agar authentication token, session ID, refresh token, atau credential tidak disimpan di `localStorage` **maupun `sessionStorage`**, karena JavaScript yang berjalan akibat XSS dapat membaca storage tersebut. OWASP lebih memilih HttpOnly cookies atau pola BFF. ([OWASP Cheat Sheet Series][2])

Jadi:

```text
localStorage for bearer credential
🔴 REJECTED
```

---

# 19. Tetapi Kita Juga Belum Mengunci `sessionStorage`

`sessionStorage` sangat menarik dari sisi semantics:

```text
reload persistence     ✅
per-tab isolation      ✅
auto-clear on tab end  ✅ secara umum
```

Browser memang mempartisi `sessionStorage` per top-level browsing context/tab. ([MDN Web Docs][1])

Tetapi dari sisi security:

```text
JavaScript-readable
→ XSS can steal bearer credential
```

sehingga **belum boleh kita lock sebagai solusi final**. ([OWASP Cheat Sheet Series][3])

FE-3 hanya menetapkan requirements.

ADR nanti harus mencari implementation yang memenuhi:

```text
reload continuity
+
per-tab isolation
+
XSS resistance
+
logout semantics
```

sebanyak mungkin tanpa merusak frozen backend contract.

---

# 20. Cookie/BFF Juga Belum Otomatis Menang

HttpOnly cookie mengurangi kemampuan JavaScript membaca credential dan merupakan security improvement penting. Secure/HttpOnly/SameSite juga merupakan cookie hardening yang direkomendasikan. ([MDN Web Docs][4])

Tetapi cookies pada origin yang sama secara normal tidak mempunyai semantics per-tab seperti `sessionStorage`.

Jadi jika kita langsung berkata:

```text
"pakai HttpOnly cookie"
```

kita dapat memperbaiki XSS exposure tetapi **merusak Tenant A / Tenant B per-tab isolation**.

Inilah alasan final implementation harus mendapat ADR sendiri.

---

# 21. Authentication Credential Requirements

Saya menyarankan requirement berikut menjadi mandatory:

```text
Credential MUST NOT:
────────────────────────────
appear in URL
appear in query string
appear in browser history
be logged to console
be logged to telemetry
be placed in localStorage
be copied to analytics
be included in error reporting
be globally synchronized across tabs
```

Dan:

```text
Credential SHOULD:
────────────────────────────
survive normal reload while valid
remain isolated per browser tab
be replaceable atomically on membership switch
be disposable immediately on logout
be treated as opaque by application code
```

---

# 22. Authentication Context Ownership

Saya menyarankan session subsystem hanya memiliki:

```text
Authentication Session
│
├── credential lifecycle
├── status
│
├── User
├── Person
├── Membership
└── Tenant
```

Tidak:

```text
authStore {
  students,
  employees,
  rooms,
  permissions,
  forms,
  sidebar,
  notifications
}
```

Workspace juga sebaiknya menjadi state subsystem terpisah karena workspace bukan authentication identity.

Conceptual:

```text
Auth Session
     │
     ├── User
     ├── Person
     ├── Membership
     └── Tenant

Workspace Context
     │
     └── Organizational Assignment

Capability State
     │
     └── capability projection
```

---

# 23. Membership Switch Credential Replacement

FE-4 akan membahas Tenant switch secara penuh, tetapi FE-3 perlu menetapkan session rule-nya.

Canonical backend behaviour:

```text
Token A
Membership A
Tenant A
     ↓
POST membership switch
     ↓
Token B
Membership B
Tenant B
```

Frontend harus mengganti credential **secara atomic**.

Jangan:

```text
set tenant = B

...network work...

replace token = B
```

karena intermediate state menjadi:

```text
UI says Tenant B
credential still Tenant A
```

Itu berbahaya.

Correct semantic:

```text
Receive Token B
      ↓
commit authentication context transition
      ↓
bootstrap Tenant B
```

Detailnya kita kunci di FE-4.

---

# 24. Concurrent Requests Saat Session Transition

Ketika state adalah:

```text
CONTEXT_SWITCHING
```

protected mutating operations harus diblok sementara.

Tujuannya mencegah:

```text
switch Tenant
     +
user double-click mutation
     +
old credential accidentally used
```

Read request yang sudah berjalan dapat dibatalkan/diabaikan hasilnya jika berasal dari context lama.

Ini nanti berpengaruh pada API client dan TanStack Query cache keys.

---

# 25. Login Errors

Frontend tidak boleh membuat logic:

```typescript
if (message.includes("password")) ...
```

Branching harus berdasarkan:

```text
HTTP status
+
canonical error code
```

sesuai error contract foundation.

Contoh UX category:

```text
invalid credentials
→ generic login error

inactive/suspended context
→ account/context unavailable

validation
→ field-level error

429
→ retry-later state

5xx/network
→ operational failure
```

Exact mapping kita selesaikan pada FE-7.

---

# 26. Security Against Account Enumeration

Login error sebaiknya tidak membocorkan secara berlebihan:

```text
email exists
tenant exists
membership exists
password incorrect
```

kepada unauthenticated client.

Frontend cukup menampilkan safe backend message.

Frontend juga tidak boleh mencoba “memperbaiki” generic backend message menjadi:

```text
"This email exists, but your password is wrong."
```

berdasarkan dugaan sendiri.

---

# 27. Password Handling

Password:

```text
must stay inside login-form lifecycle
```

Setelah request selesai:

```text
password value
→ discarded
```

Tidak masuk:

```text
global store
query cache
localStorage
sessionStorage
analytics
error context
logs
URL
```

Browser password-manager integration tetap boleh digunakan.

Kita tidak membuat custom password vault.

---

# 28. Post-Login Redirect

Saya merekomendasikan default:

```text
Login successful
      ↓
Bootstrap
      ↓
Resolve initial workspace/capabilities
      ↓
Dashboard
```

Bukan langsung mengembalikan route yang berasal dari tenant berbeda.

Safe return URL hanya dapat digunakan jika:

```text
same application
+
same authenticated Membership/Tenant context
+
route still authorized
```

Untuk Foundation v1, defaulting ke `/dashboard` setelah fresh login adalah policy paling predictable.

---

# 29. Refresh Setelah Tenant Switch

Karena credential baru mewakili context berbeda, semua authentication-derived state lama dianggap stale:

```text
User
→ likely same

Person
→ likely same

Membership
→ changed

Tenant
→ changed
```

Maka frontend tetap melakukan:

```text
new credential
      ↓
GET /auth/me
```

Tidak membangun context hanya dari response switch.

Ini menjaga satu bootstrap mechanism.

---

# 30. Session Performance

Walaupun target user bisa mencapai ratusan ribu, browser tidak perlu terus-menerus memanggil `/auth/me`.

Recommended lifecycle:

```text
Application start
→ bootstrap once

Tenant switch
→ bootstrap once

explicit recovery
→ bootstrap

every route navigation
→ NO
```

Kita tidak perlu:

```text
GET /auth/me
GET /auth/me
GET /auth/me
```

setiap pindah halaman.

Authentication context relatif stabil selama credential yang sama masih valid.

---

# 31. P0 vs P1

| Requirement                        |                      Priority |
| ---------------------------------- | ----------------------------: |
| Tenant-aware login                 |                            P0 |
| No raw tenant UUID as normal UX    |                            P0 |
| Explicit auth state machine        |                            P0 |
| `/auth/me` bootstrap               |                            P0 |
| Reload session verification        |                            P0 |
| Per-tab auth isolation             |                            P0 |
| Session expiration handling        |                            P0 |
| Current-token logout/revocation    |                            P0 |
| Clear auth context on logout       |                            P0 |
| Credential never in URL/logs       |                            P0 |
| Reject `localStorage` credential   |                            P0 |
| Atomic credential replacement      |                            P0 |
| No silent refresh without contract |                            P0 |
| No hardcoded role auth             |                            P0 |
| Remember Me                        |                      Deferred |
| MFA                                |                    Future PRD |
| Password recovery                  | Separate PRD/backend contract |
| Logout all devices                 |                        Future |
| Refresh token / long-lived session |                        Future |

---

# 32. Non-Negotiable FE-3 Guardrails

Saya merekomendasikan kita lock ini:

```text
1. Bearer token is an opaque credential, not frontend domain state.

2. /auth/me is the canonical verified authentication bootstrap.

3. Application shell does not become authenticated-ready
   until bootstrap succeeds.

4. Role/permission are never trusted from bearer claims.

5. Normal reload should preserve a still-valid tab session.

6. Authentication context must remain isolated per browser tab.

7. localStorage must not contain bearer credentials.

8. sessionStorage is not automatically accepted;
   its XSS trade-off requires ADR review.

9. A tenant/membership switch replaces credentials atomically.

10. A fresh authenticated context is rebuilt after credential exchange.

11. Logout revokes the current credential when reachable
    and always clears client-side authentication state.

12. Logout in one tab must not implicitly destroy an independent
    authentication context in another tab.

13. No silent refresh exists until a formal refresh/session contract exists.

14. Password and bearer token must never enter telemetry,
    logs, URL, analytics, or generic state persistence.

15. Backend 401 is authoritative regardless of client expiry timer.

16. Authentication state, workspace state, capability state,
    server state, and UI state remain separate ownership concerns.
```

---

# 33. ADR yang FE-3 Sekarang Terbukti Membutuhkan

Dari FE-3 ini, satu ADR menjadi **mandatory**, bukan sekadar candidate:

```text
Authentication Credential Storage
& Browser Session Isolation
```

Problem statement-nya sudah sangat jelas:

```text
Need
───────────────────────────
security
reload persistence
per-tab isolation
multi-tenant contexts
logout/revocation

while

localStorage
→ wrong isolation + XSS exposure

sessionStorage
→ correct isolation but XSS exposure

HttpOnly cookie
→ stronger token secrecy but shared same-origin context

memory only
→ stronger non-persistence but poor reload UX
```

Kita **belum memilih jawabannya sekarang**, karena PRD harus menetapkan kebutuhan sebelum ADR memilih solusi.

---

# FE-3 Gate Recommendation

Saya merekomendasikan:

```text
FE-3 — Authentication & Session PRD

Authentication Model
✅ Existing bearer-token backend retained
✅ Tenant-aware authentication retained
✅ /auth/me canonical bootstrap
✅ Token opaque to application

Session UX
✅ Explicit lifecycle/state machine
✅ Reload continuity required
✅ 401 backend authoritative
✅ No silent refresh in Foundation v1
✅ Remember Me deferred

Multi-Tab
✅ Independent authentication context per tab
✅ Independent Tenant/Membership context per tab
✅ No cross-tab credential synchronization

Logout
✅ Revoke current token
✅ Clear current tab authentication state
✅ Other tab contexts remain independent
⏭ Logout-all future

Security
✅ Credential never in URL/log/analytics
✅ Password never persisted
✅ localStorage token rejected
🟡 Final credential-storage mechanism requires ADR
🟡 Pre-auth human-friendly Tenant resolution requires
   explicit architecture/product decision

Backend Foundation
🔒 NOT REOPENED
```

**FE-3 menurut saya siap di-lock.**

Ada dua hasil arsitektural penting dari langkah ini: **credential-storage/session-isolation wajib mempunyai ADR**, dan **login perlu mekanisme human-friendly untuk memperoleh Tenant context tanpa meminta pengguna mengetik UUID**. Yang kedua bisa kita selesaikan tanpa mengubah model Tenant; kalau ternyata membutuhkan endpoint baru, kita perlakukan sebagai additive frontend-support contract.

Setelah persetujuan Anda, langkah berikutnya adalah **FE-4 — Tenant / Membership Context PRD**, dengan fokus pada membership discovery, switch transaction, cache invalidation, stale-request protection, default landing, dan exact multi-tab behaviour.

[1]: https://developer.mozilla.org/en-US/docs/Web/API/Window/sessionStorage?utm_source=chatgpt.com "Window: sessionStorage property - Web APIs | MDN"
[2]: https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html?utm_source=chatgpt.com "Session Management - OWASP Cheat Sheet Series"
[3]: https://cheatsheetseries.owasp.org/cheatsheets/HTML5_Security_Cheat_Sheet.html?utm_source=chatgpt.com "HTML5 Security - OWASP Cheat Sheet Series"
[4]: https://developer.mozilla.org/en-US/docs/Web/Security/Practical_implementation_guides/Cookies?utm_source=chatgpt.com "Secure cookie configuration - MDN Web Docs"
