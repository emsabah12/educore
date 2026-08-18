Baik. **FE-6 kita lock** dan sekarang masuk hanya ke **FE-7 — Error, Loading & Recovery UX PRD**.

Tahap ini akan menjadi dasar centralized API handling nanti. Prinsip utamanya:

> **Error tidak boleh diperlakukan hanya berdasarkan HTTP status, dan retry tidak boleh dilakukan secara generik.**

Foundation backend sudah memiliki stable machine-readable codes seperti `AUTHENTICATION_CONTEXT_DENIED`, `AUTHORIZATION_DENIED`, `ORGANIZATIONAL_CONTEXT_REQUIRED`, `ORGANIZATIONAL_CONTEXT_DENIED`, `VALIDATION_FAILED`, `RESOURCE_NOT_FOUND`, `MEMBERSHIP_SWITCH_DENIED`, dan beberapa infrastructure failure codes. Jadi frontend harus menggunakan kombinasi **HTTP status + error code + operation semantics**. Current foundation juga memang secara eksplisit mensyaratkan pola tersebut.

# FE-7 — Error, Loading & Recovery UX PRD

## 1. Tujuan

Frontend harus mampu membedakan:

```text
EXPECTED USER ERROR
≠
AUTHENTICATION FAILURE
≠
AUTHORIZATION FAILURE
≠
STALE CONTEXT
≠
BUSINESS CONFLICT
≠
VALIDATION FAILURE
≠
NETWORK FAILURE
≠
SERVER FAILURE
≠
FRONTEND CONTRACT BUG
```

Kalau semua menjadi:

```text
Something went wrong.
```

kita kehilangan kemampuan recovery.

Sebaliknya kalau setiap component menangani error sendiri, kita mendapatkan inkonsistensi.

Target architecture:

```text
HTTP/API
   ↓
Canonical Error Normalization
   ↓
Error Classification
   ↓
Recovery Policy
   ↓
Feature UX
```

---

# 2. Canonical Error Shape

Frontend foundation harus memahami canonical envelope:

```json
{
  "status": "error",
  "code": "STABLE_MACHINE_CODE",
  "message": "Safe user-facing message."
}
```

Validation:

```json
{
  "status": "error",
  "code": "VALIDATION_FAILED",
  "message": "The submitted data is invalid.",
  "errors": {
    "field": ["Validation message."]
  }
}
```

Frontend **boleh menampilkan `message`**, tetapi decision branching harus memakai:

```text
HTTP status
+
code
```

Bukan:

```typescript
if (message.includes('membership')) ...
```

---

# 3. Frontend Error Taxonomy

Saya merekomendasikan delapan category internal.

```text
Application Error
│
├── AUTHENTICATION
├── AUTHORIZATION
├── CONTEXT
├── VALIDATION
├── NOT_FOUND
├── CONFLICT
├── RATE_LIMIT
├── SERVER
└── NETWORK / CONTRACT
```

Ini bukan vocabulary baru backend.

Ini hanya:

> **frontend recovery classification**

untuk menghindari business component mengetahui seluruh detail HTTP.

---

# 4. 401 Tidak Selalu Berarti Hal yang Sama

Contoh login:

```text
POST login
↓
401 AUTHENTICATION_FAILED
```

UX:

```text
Invalid email, password, or institution credentials.
```

Tetapi itu **bukan**:

```text
session expired
```

Karena belum ada authenticated session.

Sebaliknya, jika protected operation memberi canonical authentication-context failure, frontend perlu melakukan session recovery.

Jadi jangan membuat interceptor:

```typescript
if (response.status === 401) {
  logout();
}
```

Itu terlalu kasar.

---

# 5. Current Backend Bahkan Bisa Menggunakan 403 untuk Invalid Auth Context

Ini sangat penting.

Karena protected routes melewati `InjectTenantContext`, current observable foundation contract memiliki cases seperti:

```text
403 AUTHENTICATION_CONTEXT_DENIED
```

untuk missing/invalid authenticated Tenant/Membership context.

Jadi rule frontend:

```text
SESSION INVALIDATION
```

tidak boleh hanya bergantung pada HTTP `401`.

Ia harus mengenali canonical codes seperti:

```text
AUTHENTICATION_REQUIRED
AUTHENTICATION_CONTEXT_DENIED
```

sesuai route contract.

---

# 6. Authentication Recovery

Jika current authenticated session mendapatkan canonical auth-context invalidation:

```text
AUTHENTICATION_CONTEXT_DENIED
```

flow:

```text
Protected request
      ↓
authentication context rejected
      ↓
mark session invalid
      ↓
prevent new mutations
      ↓
clear credential/client auth state
      ↓
clear Tenant
      ↓
clear Workspace
      ↓
clear Capabilities
      ↓
clear protected server-state cache
      ↓
redirect Login
```

Jangan:

```text
retry same bearer token 3x
```

Token/context sudah ditolak.

Retry tidak memperbaiki credential invalid.

---

# 7. Authentication Failure Tidak Menjadi Global Toast Saja

Session expiration adalah application-level transition.

Jadi jangan hanya:

```text
Toast:
"Session expired"
```

sementara application masih interactive.

Correct:

```text
session state
AUTHENTICATED
      ↓
EXPIRED / INVALID
      ↓
UNAUTHENTICATED
```

Kemudian user diarahkan ke authentication flow.

Toast/banner boleh memberi explanation, tetapi bukan satu-satunya handling.

---

# 8. 403 AUTHORIZATION_DENIED

Ini berbeda total.

Scenario:

```text
User authenticated ✅
Tenant valid ✅
Workspace valid ✅
Permission denied ❌
```

Backend:

```text
403 AUTHORIZATION_DENIED
```

Frontend:

```text
DO NOT logout
DO NOT clear Tenant
DO NOT clear Workspace
```

Sebaliknya:

```text
deny operation
      ↓
refresh capabilities
      ↓
re-evaluate current route/action
```

Jika route sekarang invalid:

```text
redirect /dashboard
```

Jika hanya action yang hilang:

```text
remain page
remove/disable affordance
```

---

# 9. Organizational Context Required

Current code:

```text
ORGANIZATIONAL_CONTEXT_REQUIRED
```

berarti feature membutuhkan Organization/Unit workspace tetapi request tidak membawa valid context.

UX sebaiknya:

```text
This feature requires an organization workspace.

[ Select workspace ]
```

bukan:

```text
Forbidden.
```

Recovery:

```text
open workspace selector
or
navigate to workspace selection UX
```

Tidak perlu logout.

---

# 10. Organizational Context Denied

Ini biasanya lebih serius daripada “required”.

Contoh:

```text
saved assignment inactive
assignment removed
assignment belongs to old context
current assignment stale
```

Response:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

Canonical recovery yang sudah kita tentukan:

```text
STOP current operation
      ↓
clear stale Workspace
      ↓
discard stale workspace persistence
      ↓
GET /my-workspaces
      ↓
Tenant-level safe context
      ↓
reload Tenant capabilities
      ↓
dashboard / safe route
```

Dan **jangan retry dengan assignment yang sama**.

---

# 11. Invalid Organizational Assignment ID

```text
422 INVALID_ORGANIZATIONAL_ASSIGNMENT_ID
```

dalam normal frontend seharusnya hampir tidak terjadi karena assignment ID berasal dari server projection.

Kalau terjadi:

```text
frontend state is likely corrupted/stale
```

Recovery:

```text
clear Workspace
↓
rediscover workspace
↓
Tenant fallback
```

dan record diagnostic telemetry.

Jangan tampilkan UUID validation detail ke user.

---

# 12. Validation Failure — 422

Canonical:

```text
422 VALIDATION_FAILED
```

dengan:

```json
"errors": {
  "email": ["..."],
  "password": ["..."]
}
```

UX rule:

```text
field error
→ inline near corresponding field

form-level message
→ summary/banner when appropriate
```

Contoh:

```text
Email
[ bad-email               ]
Please enter a valid email address.
```

Jangan hanya toast:

```text
Validation failed.
```

untuk seluruh form.

---

# 13. Unknown Validation Field

Backend mungkin nanti mengembalikan:

```text
errors.some_new_field
```

yang belum memiliki visual field mapping.

Frontend tidak boleh crash.

Fallback:

```text
form error summary
```

misalnya:

```text
Some submitted information is invalid.
Please review the form and try again.
```

Ini penting untuk forward compatibility.

---

# 14. Client Validation vs Server Validation

Frontend boleh memiliki schema validation untuk UX cepat.

Tetapi:

```text
client validation
≠
authority
```

Server validation tetap canonical.

Contoh:

```text
Frontend says valid
↓
Backend says VALIDATION_FAILED
```

Frontend wajib menerima backend result.

Jangan menganggap server error sebagai bug hanya karena client schema lolos.

---

# 15. 404 — Resource Not Found

Generic canonical:

```text
RESOURCE_NOT_FOUND
```

UX tergantung operation.

### Detail page

```text
/student/123
```

→ Page-level not-found state.

```text
Student no longer exists or is unavailable.
```

### Mutation target disappeared

Misalnya delete/update resource yang sudah dihapus user lain:

```text
refresh list
+
message
```

Jangan selalu redirect application-wide 404.

---

# 16. 409 — Conflict

Current foundation inventory belum memiliki satu generic canonical `CONFLICT` code yang dipakai lintas foundation.

Jadi FE-7 harus mendefinisikan **support untuk HTTP 409**, tetapi jangan menginventarisasi machine code yang backend belum punya.

Future domain API harus memberi stable code seperti semantically:

```text
RESOURCE_ALREADY_ASSIGNED
CAPACITY_CONFLICT
STATE_TRANSITION_CONFLICT
```

bukan hanya:

```text
409
"Conflict"
```

Frontend kemudian dapat menentukan recovery spesifik.

---

# 17. Conflict Bukan Validation Error

Contoh:

```text
Bed initially available

User A checks in Resident

User B submits old form
```

Server:

```text
409 domain conflict
```

Ini bukan:

```text
"room_id invalid"
```

UX harus menjelaskan bahwa **state berubah setelah halaman dimuat**.

Recovery:

```text
show conflict explanation
+
refresh relevant data
+
preserve safe user input where possible
```

---

# 18. 429 — Rate Limiting

Current foundation stable-code inventory juga belum menunjukkan canonical rate-limit code.

Namun frontend harus siap terhadap:

```text
HTTP 429
```

Policy:

```text
respect Retry-After if available
```

UX:

```text
Too many requests.
Please wait before trying again.
```

Jangan melakukan immediate automatic retry loop karena justru memperburuk throttling.

---

# 19. 500 — Unexpected Server Failure

Canonical code yang tersedia:

```text
INTERNAL_SERVER_ERROR
```

dan beberapa operation-specific server codes.

UX:

```text
We couldn't complete this request.
Please try again.
```

Tetapi error tersebut tidak boleh menampilkan:

```text
SQL
stack trace
exception class
database hostname
filesystem path
raw exception
```

Frontend juga jangan mengubah error menjadi:

```text
Laravel error: ...
```

---

# 20. 503 — Temporary Unavailability

Contoh current foundation:

```text
LOGOUT_UNAVAILABLE
```

juga bisa ada infrastructure unavailable cases.

503 berarti:

```text
service temporarily cannot safely perform operation
```

UX bisa menawarkan retry jika operation memang safely retryable.

Tetapi ada exception penting:

### Logout

Jika server logout/revocation gagal:

```text
LOGOUT_UNAVAILABLE
```

FE-3 sudah menetapkan:

```text
client credential tetap dibuang
```

karena user meminta keluar.

Frontend boleh menjelaskan:

```text
You have been signed out from this tab,
but the server could not confirm token revocation.
```

jika product wording membutuhkan.

---

# 21. Network Failure

Network error berbeda dari HTTP 500.

Network failure berarti:

```text
no valid HTTP response received
```

Contoh:

```text
offline
DNS failure
connection reset
timeout
gateway unreachable
```

Frontend tidak boleh menyebut:

```text
"Server returned 500"
```

jika tidak ada response.

UX:

```text
We couldn't connect to EduCore.
Check your connection and try again.
```

---

# 22. Offline Tidak Sama dengan Logged Out

Ini sangat penting.

Scenario:

```text
Laptop loses Wi-Fi
```

Jangan:

```text
network error
→ clear token
→ login page
```

Authentication belum terbukti invalid.

State:

```text
AUTHENTICATED
+
NETWORK_UNAVAILABLE
```

boleh coexist sementara.

Hanya canonical authentication rejection yang menginvalidasi session.

---

# 23. API Contract Error

Ada category yang sering terlupakan.

Misalnya frontend mengharapkan:

```json
{
  "status": "success",
  "data": {}
}
```

tetapi menerima:

```html
<html>
  ...
</html>
```

atau JSON tanpa expected contract.

Itu bukan:

```text
user error
```

Itu:

```text
API_CONTRACT_ERROR
```

Frontend harus:

```text
fail safely
show generic recovery UX
record diagnostic information
```

tanpa mencoba menebak response.

---

# 24. Unknown Stable Error Code

Misalnya backend versi baru mengirim:

```text
NEW_DOMAIN_CONFLICT
```

yang frontend belum kenal.

Jangan crash.

Fallback:

```text
use HTTP category
+
safe backend message
```

sementara telemetry mencatat unknown code.

Ini memberi forward compatibility.

---

# 25. Central Error Normalization

Saya merekomendasikan nanti hanya API infrastructure yang memahami raw:

```text
Response
status
headers
JSON envelope
network exception
```

Business feature menerima normalized application error.

Conceptual:

```text
Raw HTTP
   ↓
ApiErrorNormalizer
   ↓
{
  category,
  status,
  code,
  message,
  fieldErrors?,
  retryAfter?,
  requestId?
}
```

Exact TypeScript type kita putuskan pada ADR.

---

# 26. Jangan Semua Error Ditangani Global

Centralization tidak berarti:

```text
every error
→ global toast
```

Kita butuh tiga level.

### Global application recovery

Contoh:

```text
session invalid
workspace invalid
```

### Page-level error

Contoh:

```text
student list failed
```

### Local operation error

Contoh:

```text
save student failed
```

Error layer harus dipilih berdasarkan impact.

---

# 27. Toast Tidak Boleh Menjadi Error Architecture

Toast cocok untuk:

```text
Saved successfully
Could not copy value
Action completed
```

Tetapi tidak cocok sebagai satu-satunya presentation untuk:

```text
session expired
page failed to load
validation errors
stale workspace
```

Rule:

> **Toast adalah notification primitive, bukan recovery mechanism.**

---

# 28. Loading State Taxonomy

Kita juga jangan memakai satu boolean:

```typescript
loading = true;
```

untuk semua.

Minimal ada:

```text
APPLICATION_BOOTSTRAP
PAGE_INITIAL_LOAD
BACKGROUND_REFETCH
MUTATION_PENDING
TENANT_SWITCH
WORKSPACE_SWITCH
CAPABILITY_REFRESH
```

Masing-masing membutuhkan UX berbeda.

---

# 29. Application Bootstrap Loading

Saat:

```text
credential recovery
/auth/me
workspace discovery
capability initialization
```

belum selesai:

```text
full application bootstrap state
```

Tampilkan:

```text
EduCore
Restoring your session...
```

atau branded shell skeleton.

Jangan render business page dengan partial old context.

---

# 30. Initial Page Load

Untuk normal resource page:

```text
first load
```

gunakan:

```text
skeleton
or
page-level loading
```

terutama jika shape content relatif diketahui.

Contoh:

```text
Students
────────────────────

██████████████████
██████████████
████████████████
```

Skeleton lebih baik daripada application-wide spinner untuk page content.

---

# 31. Background Refetch

Jika data lama yang valid masih tersedia dan server melakukan refetch:

```text
DO NOT erase whole page
```

Gunakan stale-while-revalidate UX:

```text
current content remains
+
subtle refresh indicator
```

Ini mengurangi flicker.

Tetapi hanya jika context belum berubah.

Setelah Tenant/Workspace switch:

```text
old context data
```

tidak boleh dipakai sebagai stale placeholder.

---

# 32. Mutation Pending

Contoh:

```text
Save Student
```

setelah submit:

```text
Save button disabled
Saving...
```

Hindari:

```text
entire application blocked
```

kecuali mutation memang global context transition.

---

# 33. Tenant Switch Loading

Tenant switch adalah application-level transition.

UX:

```text
Switching institution...
```

Selama proses:

```text
context-sensitive mutations disabled
second switch disabled
old page non-interactive
```

Karena Tenant boundary berubah.

---

# 34. Workspace Switch Loading

Workspace switch lebih ringan.

UX:

```text
Changing workspace...
```

Shell dapat tetap tersedia, tetapi:

```text
workspace-sensitive actions unavailable
capabilities reloading
```

Current content dapat diganti loading state bila data scoped ke workspace.

---

# 35. Capability Refresh Loading

Saat capability refresh setelah `403` atau workspace switch:

```text
protected actions
→ temporarily unresolved
```

Jangan mempertahankan stale capabilities sebagai interactive permission state.

Tetapi application shell tidak perlu hilang penuh.

---

# 36. Empty State ≠ Error State

Contoh:

```text
GET /students
200
data = []
```

Itu:

```text
EMPTY
```

bukan:

```text
ERROR
```

UX:

```text
No students yet.

[ Add student ]
```

jika user mempunyai create capability.

Kalau tidak:

```text
No students are available in this workspace.
```

---

# 37. Zero Capability ≠ Capability Load Failure

FE-6 sudah menetapkan ini.

```text
200 permissions=[]
```

→ legitimate empty-access state.

```text
500 capability endpoint
```

→ capability recovery error.

Jangan tampilkan:

```text
"You have no permission"
```

ketika sebenarnya backend sedang unavailable.

---

# 38. Retry Policy — Prinsip Utama

Ini salah satu keputusan terpenting FE-7:

> **Read dan mutation tidak boleh mempunyai retry policy yang sama.**

Secara umum:

```text
GET / read
→ may be auto-retryable

POST/PUT/PATCH/DELETE
→ default NO automatic retry
```

karena mutation bisa sudah berhasil di server walau response hilang.

---

# 39. Kenapa Mutation Tidak Boleh Auto-Retry

Scenario:

```text
POST payment
      ↓
server commits
      ↓
connection drops before response
```

Frontend berpikir gagal.

Jika otomatis retry:

```text
POST payment again
```

potensi duplicate operation.

Hal yang sama dapat berlaku pada:

```text
check-in
role assignment
notification dispatch
create record
```

Maka Foundation policy:

```text
Mutation automatic retry
❌ default
```

kecuali endpoint mempunyai explicit idempotency contract.

---

# 40. Retry GET

Read request yang gagal akibat transient network/server error boleh retry terbatas.

Contoh policy concept:

```text
GET
network / selected 5xx
→ small bounded retry

403
→ no network retry

404
→ no retry

422
→ no retry

429
→ wait Retry-After

auth-context invalid
→ no retry with same credential
```

Exact count/backoff kita putuskan pada ADR.

---

# 41. POST yang Idempotent Secara Business Tetap Tidak Diasumsikan

Jangan developer berkata:

```text
"sepertinya POST ini aman diulang."
```

Endpoint harus mempunyai explicit contract seperti:

```text
idempotency key
```

atau operation semantics yang dijamin backend.

Tanpa itu:

```text
no automatic retry
```

---

# 42. Retry Button untuk Mutation

Jika mutation mengalami network ambiguity:

```text
request may or may not have completed
```

UX sebaiknya tidak langsung mengatakan:

```text
"Operation failed."
```

untuk operasi kritikal.

Lebih tepat:

```text
We couldn't confirm whether the operation completed.

Refresh the current state before trying again.
```

Untuk domain critical seperti:

```text
payment
resident check-in
role assignment
```

PRD domain nantinya mungkin membutuhkan specialized reconciliation.

---

# 43. Optimistic Updates

Untuk Foundation v1 saya **tidak menyarankan blanket optimistic mutation strategy**.

Optimistic update cocok untuk beberapa low-risk operations.

Tetapi tidak cocok secara default untuk:

```text
tenant switch
role assignment
financial operation
capacity allocation
resident placement
```

Jadi:

```text
optimistic mutations
→ opt-in per domain
```

bukan platform default.

---

# 44. Unsaved Form Protection

Jika user mempunyai dirty form:

```text
Student Edit
[unsaved changes]
```

kemudian melakukan:

```text
route navigation
workspace switch
tenant switch
browser close/reload
```

frontend perlu protection.

UX:

```text
You have unsaved changes.

Discard changes and continue?
```

---

# 45. Tenant Switch + Unsaved Form

Karena Tenant switch menghapus entire tenant-scoped context:

```text
Tenant switch
+
dirty form
```

harus meminta explicit discard confirmation.

Jika user cancel:

```text
Tenant remains unchanged
form preserved
```

Jika continue:

```text
discard
→ perform Tenant switch
```

---

# 46. Workspace Switch + Unsaved Form

Sama.

Jika form bergantung current workspace:

```text
dirty
→ confirmation
```

Jangan silently membawa form data dari:

```text
SMA / Academic
```

ke:

```text
SMA / Finance
```

---

# 47. Session Expiry + Unsaved Form

Ini lebih sulit karena user tidak punya pilihan mempertahankan invalid authenticated session.

Frontend sebaiknya sebisa mungkin:

```text
stop further mutation
```

dan jika form draft tidak sensitif serta architecture nanti mendukung safe ephemeral preservation, dapat digunakan untuk recovery.

Tetapi **Foundation PRD tidak menjanjikan persistent form restoration setelah authentication expiry**.

Karena menyimpan form arbitrary dapat membawa PII/security risks.

Untuk v1:

```text
session expiry
→ security recovery wins
```

Draft persistence menjadi opt-in domain feature nanti.

---

# 48. Browser Navigation Protection

Frontend harus melindungi:

```text
internal route navigation
```

dan sebisa mungkin browser unload/reload.

Namun browser APIs tidak menjamin custom confirmation wording.

Requirement product sebaiknya:

```text
warn user of unsaved changes where browser permits
```

bukan menjanjikan exact custom dialog pada semua browser.

---

# 49. Error Boundary untuk Frontend Runtime Error

API error berbeda dari React/rendering error.

Kalau component melempar unexpected runtime exception:

```text
render crash
```

kita butuh application error boundary.

Scope:

```text
App-level boundary
+
route/module boundary where useful
```

agar satu module crash tidak selalu menghancurkan seluruh authenticated application.

---

# 50. Runtime Error Tidak Menampilkan Stack Trace

Production UX:

```text
This page encountered an unexpected problem.

[ Try again ]
[ Go to dashboard ]
```

Telemetry menerima diagnostic details sesuai privacy policy.

User normal tidak melihat:

```text
TypeError at StudentPage.tsx:178
```

---

# 51. Module Error Isolation

Kalau future Dormitory chunk crash:

```text
Dormitory page failure
```

idealnya:

```text
Sidebar
Topbar
Tenant
Workspace
```

tetap bekerja.

User bisa:

```text
go Dashboard
switch module
logout
```

Ini argument kuat untuk route/module-level error boundaries.

---

# 52. Lazy-Chunk Load Failure

Karena kita akan menggunakan lazy-loaded business modules, ada failure class:

```text
JS chunk cannot load
```

misalnya deployment baru atau flaky network.

Frontend perlu safe recovery seperti:

```text
This part of EduCore could not be loaded.

[ Retry ]
```

Jika stale deployment asset version menjadi penyebab, future implementation dapat menawarkan controlled reload.

Jangan infinite reload loop.

---

# 53. Global Error Page Minimum

Foundation harus memiliki reusable states:

```text
Unauthorized / Session Expired
Forbidden
Not Found
Service Unavailable
Unexpected Error
Network Unavailable
Context Recovery
```

Tetapi tidak semuanya harus menjadi route page terpisah; beberapa dapat berupa state component.

---

# 54. Error Messaging Principles

Pesan harus:

```text
safe
human-readable
actionable when possible
```

Hindari:

```text
Error 403.
```

jika kita tahu:

```text
You don't have access to this feature.
```

Tetapi jangan over-explain security internals:

```text
Your OrganizationUnit role failed because assignment X
does not descend from organization Y...
```

Itu information leakage dan buruk untuk UX.

---

# 55. Detail Teknis Tetap Bisa Dicari Operator

Kita nanti membutuhkan correlation/request identifier jika backend menambahkannya.

UX bisa menampilkan:

```text
Reference: ABC123
```

pada server failure tanpa membocorkan stack.

Saat ini saya **tidak menganggap request/correlation ID sudah menjadi frozen backend contract**, jadi ini Future/ADR/NFR candidate, bukan requirement existing API.

---

# 56. Logging Frontend

Error telemetry boleh menyimpan:

```text
error category
stable code
HTTP status
route
module
operation
context type
timing
application version
```

Tetapi tidak:

```text
bearer token
password
Authorization header
raw sensitive form data
student medical information
full response payload by default
```

Detail observability akan kita lock pada FE-8.

---

# 57. P0 Mapping Matrix

Saya merekomendasikan foundation mapping berikut:

| Condition                              | Default UX                        | Recovery                      |
| -------------------------------------- | --------------------------------- | ----------------------------- |
| `AUTHENTICATION_FAILED`                | Inline login error                | User corrects credentials     |
| Auth context invalid                   | Session-expired/application state | Clear session → login         |
| `AUTHORIZATION_DENIED`                 | Forbidden/action denied           | Refresh capabilities          |
| `ORGANIZATIONAL_CONTEXT_REQUIRED`      | Workspace required                | Select workspace              |
| `ORGANIZATIONAL_CONTEXT_DENIED`        | Stale-context recovery            | Rediscover → Tenant fallback  |
| `INVALID_ORGANIZATIONAL_ASSIGNMENT_ID` | Context recovery                  | Clear corrupted workspace     |
| `VALIDATION_FAILED`                    | Inline field errors               | Correct submission            |
| `RESOURCE_NOT_FOUND`                   | Page/local not found              | Refresh/navigate              |
| `MEMBERSHIP_SWITCH_DENIED`             | Stay current Tenant               | Refresh memberships           |
| `LOGOUT_UNAVAILABLE`                   | Client logout anyway              | Report revocation uncertainty |
| `INTERNAL_SERVER_ERROR`                | Page/local server error           | Controlled retry              |
| HTTP `409`                             | Conflict state                    | Refresh/reconcile             |
| HTTP `429`                             | Rate-limit state                  | Respect retry delay           |
| Network failure                        | Connection state                  | Manual/bounded safe retry     |
| Malformed API response                 | Generic technical failure         | Telemetry + safe failure      |

---

# 58. Loading UX Matrix

| Operation          | UX                                  |
| ------------------ | ----------------------------------- |
| App bootstrap      | Full application loading            |
| Initial page query | Page skeleton                       |
| Background refetch | Preserve content + subtle indicator |
| Mutation           | Local pending state                 |
| Tenant switch      | Application context transition      |
| Workspace switch   | Context transition                  |
| Capability refresh | Protected affordances unresolved    |
| Logout             | Short application-level transition  |

---

# 59. Retry Matrix

| Operation                      | Automatic Retry                           |
| ------------------------------ | ----------------------------------------- |
| Normal GET + transient network | ✅ bounded                                |
| GET + selected 5xx             | ✅ bounded                                |
| GET + 403                      | ❌                                        |
| GET + 404                      | ❌                                        |
| GET + 422                      | ❌                                        |
| GET + 429                      | Only according to delay policy            |
| POST                           | ❌ default                                |
| PUT                            | ❌ default                                |
| PATCH                          | ❌ default                                |
| DELETE                         | ❌ default                                |
| Tenant switch                  | ❌ automatic                              |
| Workspace discovery read       | ✅ bounded if transient                   |
| Capability query               | ✅ bounded if transient                   |
| Logout                         | ❌ repeated automatic revocation attempts |

---

# 60. Non-Negotiable FE-7 Guardrails

Saya menyarankan kita lock:

```text
ERR-FE-01
Frontend branches on HTTP status + stable error code,
never arbitrary error-message text.

ERR-FE-02
Authentication failure, authorization failure, and
context failure are distinct recovery categories.

ERR-FE-03
HTTP 401 alone does not define session invalidation.

ERR-FE-04
AUTHENTICATION_CONTEXT_DENIED invalidates the current
authenticated application context.

ERR-FE-05
AUTHORIZATION_DENIED never automatically logs the user out.

ERR-FE-06
Stale organizational context triggers rediscovery and
safe Tenant fallback, not repeated stale requests.

ERR-FE-07
VALIDATION_FAILED maps to field-level UX when possible.

ERR-FE-08
Server validation always overrides client validation.

ERR-FE-09
Network failure never automatically means authentication
failure.

ERR-FE-10
Unknown machine codes fail safely using status/category
fallbacks.

ERR-FE-11
Empty data, zero permissions, loading, and errors are
distinct states.

ERR-FE-12
Read and mutation retry policies are different.

ERR-FE-13
Mutations are never automatically retried unless the
endpoint has explicit idempotency guarantees.

ERR-FE-14
429 handling must respect server retry semantics when
available and must not busy-loop.

ERR-FE-15
Tenant and Workspace transitions block context-sensitive
mutations.

ERR-FE-16
Superseded-context responses never update active UI.

ERR-FE-17
Dirty forms are protected before voluntary navigation
or context switching.

ERR-FE-18
Security recovery wins over unsaved-state preservation
when authentication becomes invalid.

ERR-FE-19
API error handling is centralized, but presentation
may be application-, page-, or operation-level.

ERR-FE-20
Toast notifications are not used as the sole recovery UI
for application-level failures.

ERR-FE-21
Frontend runtime errors are isolated through appropriate
application/route boundaries.

ERR-FE-22
Production UI never exposes stack traces, exception
details, bearer tokens, or sensitive request data.

ERR-FE-23
Business modules consume shared error/loading primitives
rather than reinventing their own HTTP semantics.

ERR-FE-24
Optimistic mutation is opt-in per domain, not the
platform default.
```

# 61. Architectural Consequence

FE-7 sekarang membuktikan bahwa kita nantinya membutuhkan setidaknya satu architectural decision untuk:

```text
API Client
+
Canonical Error Normalization
+
Retry Policy
+
Context Recovery Hooks
```

Tetapi API layer tidak boleh menjadi “god object”.

Separation yang sehat:

```text
HTTP Client
    ↓
Error Normalizer
    ↓
Server-State Layer
    ↓
Context Recovery Coordinator
    ↓
Feature
```

Contoh:

```text
403 AUTHORIZATION_DENIED
```

API client cukup mengenali error.

Capability/context subsystem menentukan apakah harus refresh.

Feature menentukan presentation lokal bila masih diperlukan.

Dengan begitu kita tidak menaruh seluruh:

```text
auth
workspace
navigation
toast
routing
TanStack Query
```

ke satu Axios/fetch interceptor raksasa.

# FE-7 Gate Recommendation

Saya merekomendasikan status:

```text
FE-7 — Error, Loading & Recovery UX

Canonical Error Handling
✅ HTTP status + stable code
✅ Canonical error normalization
✅ Unknown-code safe fallback
✅ No arbitrary message branching

Authentication
✅ Auth failure separated from session invalidation
✅ Auth-context denial → session recovery
✅ Network failure does not logout user

Authorization
✅ Authorization denial does not logout
✅ Capability refresh on stale authorization

Workspace
✅ Required context → select workspace
✅ Stale/denied assignment → rediscover
✅ Safe Tenant fallback
✅ No infinite retry

Validation
✅ 422 field-level mapping
✅ Server validation authoritative
✅ Unknown fields safely handled

Failures
✅ 404 contextual handling
✅ 409 conflict semantics supported
✅ 429 throttling semantics supported
✅ 5xx/503 controlled recovery
✅ Network failure distinguished
✅ API contract failures distinguished

Loading
✅ Bootstrap loading
✅ Page skeleton
✅ Background refetch
✅ Mutation pending
✅ Tenant switch
✅ Workspace switch
✅ Capability refresh

Retry
✅ Bounded safe-read retries
❌ No generic mutation auto-retry
✅ Idempotency required before mutation retry

Forms
✅ Dirty-state navigation protection
✅ Tenant/workspace switch protection
✅ Security recovery takes priority on session invalidation

Runtime Resilience
✅ Application/route error boundaries
✅ Module failure isolation
✅ Lazy-chunk recovery
✅ No stack trace leakage
```

**FE-7 menurut saya siap di-lock.**

Ada satu hal yang sengaja **belum kita putuskan**: angka retry, timeout, exponential backoff, cache freshness, performance budget, observability provider, dan SLA. Itu bukan error UX lagi; semuanya tepat masuk langkah berikutnya.

Setelah persetujuan Anda, kita lanjut hanya ke **FE-8 — Frontend Non-Functional Requirements (NFR)**. Di sana kita akan menentukan target untuk **performance pada skala besar, bundle/code splitting, caching, concurrency, security/XSS/CSP, accessibility, browser support, observability, logging/privacy, availability, testability, maintainability, dan deployment/CDN**, termasuk requirement yang relevan untuk target **ratusan ribu user**.
