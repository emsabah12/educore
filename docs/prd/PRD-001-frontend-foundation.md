# EduCore Frontend Foundation PRD

**Document Stage:** FE-9 — Consolidation & Final Review
**Status:** Accepted
**Product:** EduCore
**Scope:** Frontend Platform Foundation
**Backend Baseline:** `48f21a1`
**Backend Contract:** 🔒 FROZEN
**Frontend Implementation:** NOT STARTED

---

# 1. Purpose

Dokumen ini menjadi **canonical Product Requirement Document untuk Frontend Foundation EduCore**.

Dokumen mengonsolidasikan keputusan:

```text
FE-0 — Contract Baseline & Scope Verification
FE-1 — Product Scope & Personas
FE-2 — Application Shell & Navigation UX
FE-3 — Authentication & Session
FE-4 — Tenant / Membership Context
FE-5 — Workspace / Organizational Context
FE-6 — Capability / Authorization UX
FE-7 — Error, Loading & Recovery UX
FE-8 — Non-Functional Requirements
```

Setelah dokumen ini dikunci:

```text
PRD
↓
ADR
↓
Frontend TDD
↓
Implementation
```

Perubahan architectural implementation setelah PRD Lock harus dilakukan melalui ADR, tanpa mengubah product semantics yang sudah dikunci kecuali terdapat requirement baru.

---

# 2. Product Context

EduCore merupakan platform multi-tenant untuk institusi dengan berbagai business module seperti:

```text
Academic
HR
Dormitory
PPDB
Finance
Library
Attendance
...
```

Frontend Foundation menyediakan platform bersama yang digunakan seluruh business module.

Frontend Foundation bertanggung jawab terhadap:

```text
Authentication
Session lifecycle

Tenant / Membership context

Workspace / Organizational context

Capability projection

Application shell

Navigation

Routing

API infrastructure

Error handling

Loading / recovery

Security

Accessibility

Observability

Frontend performance

Frontend deployment foundation
```

Business functionality spesifik module tidak termasuk dalam scope PRD ini.

---

# 3. Backend Architectural Baseline

Frontend wajib mengikuti canonical identity model:

```text
Person
  │
  ├── optional User
  │
  └── Membership × Tenant
```

Meaning:

```text
Person
= global human identity

User
= optional authentication / digital identity

Membership
= relationship between Person and Tenant

Tenant
= institution, security, and data isolation boundary
```

Frontend tidak boleh mengasumsikan:

```text
User owns Tenant

users.tenant_id

memberships.user_id

memberships.role as authorization authority
```

Backend architecture ini merupakan frozen dependency.

---

# 4. Product Personas

Primary personas:

```text
Multi-Tenant Authenticated User

Tenant Administrator

Organization / Unit Administrator

Operational Staff

Restricted / Read-Only User
```

Secondary persona:

```text
Platform Administrator
```

Business personas seperti:

```text
Teacher
Student
Parent
Applicant
Dormitory Resident
```

tidak menjadi platform authorization primitive.

Persona digunakan untuk:

```text
product understanding
UX design
workflow analysis
```

Persona tidak digunakan sebagai frontend security mechanism.

---

# 5. Product Architecture Direction

Frontend Foundation diarahkan kepada:

```text
React 19
+
TypeScript strict
+
Vite
+
Tailwind CSS
+
React Router
+
TanStack Query
+
OpenAPI-generated API contract/client
```

Application model:

```text
SPA
```

Delivery:

```text
Browser
    ↓
CDN / Edge
    ↓
Static Frontend Assets
```

API:

```text
Browser
    ↓
Laravel API
```

Backend tidak bertanggung jawab melakukan server-side React rendering untuk normal application flow.

---

# 6. Repository Direction

Initial frontend tetap berada dalam:

```text
single repository
```

bersama EduCore application.

Tujuan:

```text
shared CI
atomic contract changes
simpler development lifecycle
consistent versioning
simpler deployment coordination
```

Repository split tidak menjadi requirement saat ini.

Jika di masa depan dibutuhkan, keputusan tersebut harus melalui architecture review.

---

# 7. Application Shell

Authenticated application menggunakan:

```text
Sidebar
+
Topbar
+
Route Content
```

## Topbar responsibilities

Topbar harus menyediakan contextual awareness terhadap:

```text
Application identity

Tenant / Membership

Workspace

Authenticated person/user

Profile / session actions
```

Tenant dan Workspace harus selalu diperlakukan sebagai konsep berbeda.

## Sidebar responsibilities

Sidebar menyediakan:

```text
module navigation
feature navigation
```

Maximum visible navigation hierarchy:

```text
2 levels
```

Navigation yang lebih kompleks harus diselesaikan pada page/module UX, bukan nested sidebar tanpa batas.

---

# 8. Responsive Strategy

Frontend:

```text
Desktop-first
Responsive
```

Minimum layout classes:

```text
Desktop
Tablet
Mobile
```

Responsive design tidak mengharuskan setiap data-heavy administration UI tampil identik di mobile.

Frontend dapat:

```text
reflow
collapse
prioritize
hide secondary presentation information
```

selama functional requirements yang didukung tetap accessible.

---

# 9. Accessibility

Foundation menargetkan:

```text
WCAG 2.2 AA
```

Shared UI harus mendukung:

```text
keyboard navigation
visible focus
semantic HTML
screen readers
accessible forms
accessible errors
accessible dialogs
accessible menus
sufficient contrast
reduced motion preference
```

Accessibility merupakan foundation requirement, bukan post-implementation enhancement.

---

# 10. Authentication State Model

Canonical authentication state machine:

```text
UNAUTHENTICATED
        ↓
AUTHENTICATING
        ↓
BOOTSTRAPPING
        ↓
AUTHENTICATED
```

Additional states:

```text
CONTEXT_SWITCHING
LOGGING_OUT
EXPIRED
```

Authentication bootstrap canonical endpoint:

```text
GET /api/v1/auth/me
```

Frontend tidak boleh menganggap token claims sebagai canonical application state.

---

# 11. Bearer Credential Semantics

Bearer credential diperlakukan sebagai:

```text
opaque credential
```

Canonical token claims backend:

```text
user_id
tenant_id
membership_id
expires_at
```

Frontend tidak menggunakan bearer credential sebagai authority untuk:

```text
role
permission
organization
organization unit
workspace
authorization
```

Canonical state harus diperoleh melalui API.

---

# 12. Credential Security Invariants

Bearer credential:

```text
MUST NOT
```

masuk ke:

```text
URL
query parameters
browser history
application logs
analytics
telemetry
localStorage
```

Credential juga tidak boleh disinkronisasi otomatis lintas browser tab.

Password:

```text
MUST NEVER
```

masuk ke:

```text
global state
persistent cache
storage
analytics
logs
telemetry
URL
```

Exact credential-storage mechanism belum ditentukan oleh PRD.

Keputusan implementation harus dibuat dalam ADR.

---

# 13. Browser Session Requirement

Normal browser reload harus dapat mempertahankan tab session yang masih valid.

Pada saat yang sama aplikasi harus mendukung:

```text
Tab A
Tenant A
Credential A

Tab B
Tenant B
Credential B
```

secara bersamaan.

Dengan demikian:

```text
browser session persistence
+
per-tab isolation
+
credential security
```

merupakan requirement yang harus dipenuhi secara bersama.

---

# 14. Deferred Authentication Features

Foundation v1 tidak menjanjikan:

```text
Remember Me
Silent Refresh
Refresh Token
Logout All Devices
MFA
```

Fitur tersebut dapat menjadi future requirements.

---

# 15. Membership Discovery

Canonical endpoint:

```text
GET /api/v1/user/my-memberships
```

Frontend hanya boleh menawarkan Membership yang ditemukan dari backend.

Frontend tidak menentukan sendiri Tenant yang dapat diakses user.

---

# 16. Tenant Switching

Canonical endpoint:

```text
POST /api/v1/user/memberships/{membership_id}/switch
```

Tenant switching secara domain adalah:

```text
Membership A / Tenant A
       ↓
authentication context exchange
       ↓
Membership B / Tenant B
       ↓
new authentication credential
```

Previous token tidak boleh diasumsikan direvoke otomatis.

---

# 17. Tenant Switch Transaction

Frontend flow:

```text
Current Tenant
      ↓
CONTEXT_SWITCHING
      ↓
POST membership switch
      ↓
receive new credential
      ↓
atomic credential replacement
      ↓
clear Workspace
      ↓
GET /auth/me
      ↓
discover workspaces
      ↓
load capabilities
      ↓
navigate /dashboard
```

During transition:

```text
one Tenant switch per tab

business mutations blocked

old context becomes non-interactive
```

Switch failure:

```text
MUST preserve current valid context
```

apabila switch belum berhasil menghasilkan authoritative new context.

---

# 18. Context Contamination Protection

Response dari Tenant lama tidak boleh memperbarui active Tenant UI.

Example:

```text
Request Students Tenant A starts
        ↓
User switches Tenant B
        ↓
Students Tenant A response arrives
        ↓
response ignored for active Tenant B
```

Ini merupakan mandatory correctness requirement.

---

# 19. Workspace Definition

Workspace bukan Core persistence entity.

Tidak ada requirement:

```text
Workspace model
workspace database table
Workspace CRUD
```

Workspace merupakan frontend/read/runtime projection dari organizational context.

Available workspace types:

```text
TENANT

ORGANIZATION

ORGANIZATION_UNIT
```

---

# 20. Workspace Discovery

Canonical endpoint:

```text
GET /api/v1/user/my-workspaces
```

Frontend hanya boleh menggunakan workspace yang ditemukan melalui authoritative backend discovery.

---

# 21. Organizational Locator

Organization / OrganizationUnit context menggunakan:

```text
X-EduCore-Organizational-Assignment-Id
```

Assignment ID adalah:

```text
locator
```

dan bukan:

```text
authorization authority
```

Backend tetap bertanggung jawab memvalidasi:

```text
Membership
Tenant
Assignment
Organization
OrganizationUnit
active status
authorization
```

---

# 22. Tenant-Level Workspace

Tenant context sendiri merupakan valid workspace context.

Tenant-level request:

```text
does not send
X-EduCore-Organizational-Assignment-Id
```

Frontend tidak boleh membuat pseudo assignment untuk Tenant context.

---

# 23. Workspace Switching

Workspace switch berbeda dari Tenant switch.

Workspace switch:

```text
same authentication credential
        ↓
change runtime organizational context
        ↓
refresh context-sensitive capabilities
        ↓
refresh context-sensitive data
```

Workspace switch tidak melakukan:

```text
authentication exchange
server-side browser session mutation
credential rotation
```

---

# 24. Workspace Restoration

After:

```text
fresh login
```

default safe context:

```text
Tenant
```

After:

```text
fresh Tenant switch
```

default safe context:

```text
Tenant
```

Normal browser reload dapat mencoba restore workspace terakhir.

Namun saved workspace hanya merupakan:

```text
restoration hint
```

Restore hanya dapat dilakukan setelah:

```text
/auth/me
+
/my-workspaces
```

memastikan assignment masih valid.

---

# 25. Stale Workspace Recovery

Jika backend mengembalikan canonical:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

frontend melakukan:

```text
stop stale request
↓
clear Workspace
↓
discard stale saved assignment
↓
rediscover /my-workspaces
↓
fallback Tenant context
↓
reload capabilities
↓
safe route/dashboard
```

Frontend tidak boleh melakukan infinite retry menggunakan assignment yang sama.

---

# 26. Authorization Model

Canonical relationship:

```text
Role
   ↓
permission assignment / administration

Permission
   ↓
canonical authorization vocabulary

Capability Projection
   ↓
effective permission read model

Frontend Policy
   ↓
navigation / routes / actions

Backend
   ↓
final authorization authority
```

Runtime frontend authorization menggunakan:

```text
permission names
```

bukan role names.

---

# 27. Capability Endpoints

Tenant context:

```text
GET /api/v1/core/authorization/capabilities
```

Organization / OrganizationUnit context:

```text
GET /api/v1/core/authorization/workspace-capabilities
```

Capability projection digunakan untuk:

```text
navigation UX
route UX
button visibility
feature affordance
```

Capability projection bukan security authority.

---

# 28. Forbidden Authorization Patterns

Frontend tidak boleh menggunakan pattern:

```text
if (role === "admin")

if (role === "teacher")
```

untuk runtime authorization.

Frontend juga tidak boleh:

```text
calculate scoped-role inheritance

derive permissions from role names

replace backend authorization decisions
```

---

# 29. Capability State

Capability state harus dapat membedakan:

```text
UNRESOLVED
LOADING
READY
STALE
ERROR
```

```text
permissions = []
```

berarti:

```text
READY
with zero permissions
```

dan bukan berarti capability request gagal.

Protected UI:

```text
fails closed
```

selama capabilities belum authoritative.

---

# 30. Hidden vs Disabled UX

Authorization restriction:

```text
no permission
→ HIDDEN
```

Business state restriction:

```text
permission exists
+
operation unavailable because business condition
→ DISABLED + explanation
```

Example:

```text
No academic.students.delete
→ Delete action absent
```

versus:

```text
Has academic.students.delete
+
Academic period locked
→ Delete disabled with reason
```

---

# 31. Navigation Ownership

Frontend memiliki static navigation catalog.

Example:

```text
Students
route:
  /academic/students

requires:
  academic.students.view
```

Setiap business module memiliki navigation metadata sendiri.

Tidak boleh ada satu giant core navigation registry yang mengetahui seluruh internal feature implementation.

Backend tidak perlu menyediakan React-specific menu tree.

---

# 32. Route Guards

Frontend route guard dapat mengevaluasi:

```text
authentication
context readiness
capability readiness
required permissions
```

untuk UX.

Tetapi:

```text
backend authorization
= canonical authority
```

Jika backend menolak operation walaupun cached capability sebelumnya mengizinkan:

```text
operation fails
↓
refresh capability projection
↓
re-evaluate route/action
```

Frontend tidak boleh override backend denial.

---

# 33. Canonical Error Handling

Frontend branching didasarkan pada:

```text
HTTP status
+
stable machine-readable error code
+
operation semantics
```

Frontend tidak boleh menggunakan:

```text
message substring matching
```

untuk menentukan recovery behavior.

---

# 34. Error Categories

Canonical frontend recovery classes:

```text
AUTHENTICATION
AUTHORIZATION
CONTEXT
VALIDATION
NOT_FOUND
CONFLICT
RATE_LIMIT
SERVER
NETWORK
CONTRACT
```

---

# 35. Authentication Error Handling

Critical rule:

```text
HTTP 401
≠ automatically logout
```

Authentication session hanya diinvalidasi apabila canonical backend error menunjukkan authentication context memang invalid.

Flow:

```text
canonical session-invalid response
↓
invalidate session
↓
clear protected state
↓
return login
```

---

# 36. Authorization Error Handling

Authorization denial:

```text
MUST NOT logout
```

Recovery:

```text
operation denied
↓
refresh capability
↓
re-evaluate UX
```

---

# 37. Validation Errors

Canonical validation:

```text
VALIDATION_FAILED
```

Known fields:

```text
inline field errors
```

Unknown backend fields:

```text
form-level validation summary
```

Frontend tidak boleh crash karena backend mengembalikan field validation yang belum dikenali UI.

Backend validation tetap authoritative.

Client validation:

```text
UX optimization only
```

---

# 38. State Semantics

Frontend wajib membedakan:

```text
EMPTY

LOADING

ERROR

ZERO_PERMISSION

NETWORK_UNAVAILABLE

SESSION_INVALID

CONTEXT_INVALID
```

Example:

```text
GET /students
200 []
```

adalah:

```text
EMPTY
```

bukan ERROR.

---

# 39. Loading Categories

Canonical loading categories:

```text
APPLICATION_BOOTSTRAP

PAGE_INITIAL_LOAD

BACKGROUND_REFETCH

MUTATION_PENDING

TENANT_SWITCH

WORKSPACE_SWITCH

CAPABILITY_REFRESH
```

Recommended UX:

```text
Application bootstrap
→ full application loading state

Initial page
→ page skeleton

Background refetch
→ keep valid current data

Mutation
→ local operation pending

Tenant switch
→ application-level transition

Workspace switch
→ lighter context transition
```

---

# 40. Retry Policy

Read operations dapat menggunakan:

```text
bounded retry
```

untuk transient network/server errors.

Default mutation policy:

```text
NO AUTOMATIC RETRY
```

untuk:

```text
POST
PUT
PATCH
DELETE
Tenant switch
```

kecuali endpoint memiliki explicit idempotency guarantees.

---

# 41. Optimistic Updates

Default foundation:

```text
optimistic mutations
= opt-in
```

Bukan global application behavior.

Harus sangat dibatasi terutama untuk:

```text
Tenant switching
Role assignment
Permission assignment
Payments
Resident placement
Capacity allocation
critical administrative operations
```

---

# 42. Dirty Form Protection

Dirty form harus dilindungi sebelum:

```text
route navigation
Tenant switch
Workspace switch
browser reload/close where supported
```

Example UX:

```text
You have unsaved changes.

Discard changes and continue?
```

Jika authentication menjadi invalid:

```text
security recovery
>
draft preservation
```

Foundation tidak menjanjikan restoration arbitrary sensitive forms.

---

# 43. Runtime Error Isolation

Frontend menyediakan:

```text
Application Error Boundary
+
Route / Module Error Boundaries
```

Example:

```text
Dormitory screen runtime failure
```

sebisa mungkin tidak menghilangkan:

```text
Topbar
Sidebar
Tenant controls
Workspace controls
Logout
```

selama platform state tersebut masih valid.

Production tidak menampilkan raw stack trace.

---

# 44. State Ownership

State dibagi berdasarkan responsibility.

```text
Server state
→ TanStack Query

Authentication/session state
→ dedicated session layer

Tenant/Workspace context
→ context layer

Form state
→ form-local / form system

Transient UI state
→ component/local UI state
```

Explicitly rejected:

```text
one giant global store
```

untuk seluruh aplikasi.

---

# 45. Context-Aware Server State

Canonical query identity:

```text
resource
+
Tenant/Membership
+
Workspace if applicable
+
request parameters
```

Conceptual example:

```text
[
  academic,
  students,
  membershipId,
  workspaceId,
  filters
]
```

Not:

```text
["students"]
```

Context-safe caching merupakan mandatory requirement.

---

# 46. Concurrent Requests

Frontend harus mencegah request amplification.

Expected behavior:

```text
duplicate reads
→ deduplicate where possible

superseded reads
→ cancel or ignore

abandoned routes
→ obsolete responses do not mutate active UI

context change
→ old-context response ignored
```

Default:

```text
polling = OFF
```

Realtime behavior harus memiliki explicit product requirement.

---

# 47. Frontend Modular Architecture

Current target conceptual structure:

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
│
├── shared/
│   ├── ui/
│   ├── forms/
│   ├── errors/
│   └── utilities/
│
└── modules/
    ├── academic/
    ├── hr/
    ├── dormitory/
    └── ...
```

Final folder structure adalah ADR/TDD concern.

PRD mengunci modular ownership principle, bukan exact directory names.

---

# 48. Module Isolation

Business modules menggunakan shared platform infrastructure.

Example:

```text
Dormitory
   ↓
Platform Auth
Platform API
Platform Context
Platform Authorization
Platform Errors
Platform Observability
```

Business module tidak boleh membangun ulang:

```text
authentication system
tenant system
workspace system
authorization engine
API error architecture
```

---

# 49. Dependency Boundaries

Cross-module dependency harus melalui explicit public/shared contracts.

Example yang harus dicegah:

```text
Dormitory
→ Academic internal implementation
```

Shared infrastructure juga tidak boleh tergantung ke internal business module.

Dependency direction harus enforceable melalui lint/build tooling apabila memungkinkan.

---

# 50. API Contract

Canonical API version:

```text
/api/v1
```

OpenAPI menjadi canonical frontend contract input.

Frontend sebaiknya menggunakan:

```text
generated TypeScript types/client
```

daripada menduplikasi DTO secara manual.

Generated files:

```text
MUST NOT be manually edited
```

---

# 51. Type Safety

TypeScript:

```text
strict = true
```

`any` tidak boleh menjadi default escape hatch.

External unknown data:

```text
unknown
```

hingga divalidasi atau tersedia canonical contract.

Compile-time typing tidak menggantikan runtime API error handling.

---

# 52. Contract Failure

Frontend harus memiliki safe handling terhadap:

```text
unexpected API response
unknown error code
missing required response state
malformed contract
```

Contract failures harus:

```text
fail safely
+
observable
```

bukan silently corrupt state.

---

# 53. Performance Objectives

Primary production targets:

| Metric |            Target |
| ------ | ----------------: |
| LCP    | ≤ 2.5 seconds p75 |
| INP    |      ≤ 200 ms p75 |
| CLS    |         ≤ 0.1 p75 |

Performance harus diukur menggunakan production/real-user telemetry ketika observability tersedia.

Development benchmark bukan satu-satunya evidence.

---

# 54. Bundle Strategy

Initial application tidak boleh memuat seluruh business modules.

Engineering budget:

```text
Initial critical JS
target ≤ 300 KB gzip

Normal incremental route chunk
target ≤ 150 KB gzip
```

Large specialized module:

```text
lazy-loaded
```

Budget merupakan engineering guardrail dan harus dipantau melalui CI.

---

# 55. Code Splitting

Minimum:

```text
Platform
├── Authentication
├── Dashboard
└── Business Modules
     ├── Academic
     ├── HR
     ├── Dormitory
     └── ...
```

Module besar dapat melakukan nested lazy loading.

---

# 56. Static Asset Caching

Build artifacts menggunakan content hashing.

Example:

```text
app.a82c21.js
```

Hashed asset dapat menggunakan:

```text
Cache-Control:
public, max-age=31536000, immutable
```

HTML bootstrap/application entry tidak boleh memiliki immutable long-lived cache yang menyebabkan deployment lama tidak dapat menerima version baru.

---

# 57. Service Workers

Foundation v1:

```text
Service Worker / Offline PWA
= NOT REQUIRED
```

Alasan utama:

```text
authenticated application
+
multi-tenant context
+
context-sensitive caching
+
deployment versioning
```

membuat service worker cache architecture berisiko jika belum memiliki explicit product requirement.

---

# 58. Frontend Security

Baseline:

```text
no unsafe HTML rendering by default

no application secrets in browser build

no eval-based application architecture

no raw sensitive telemetry

dependency security checks

CSP-compatible production deployment
```

Usage seperti:

```text
dangerouslySetInnerHTML
```

harus dilarang secara default.

Jika rich HTML diperlukan:

```text
untrusted content
→ approved sanitizer
→ constrained renderer
```

---

# 59. Content Security Policy

Production harus mendukung CSP.

Baseline direction:

```text
default-src 'self'

object-src 'none'

base-uri 'self'

frame-ancestors 'none'
```

Other directives harus dibatasi menurut kebutuhan aplikasi.

Wildcard luas tidak boleh digunakan hanya untuk convenience.

Exact CSP akan diselesaikan melalui ADR.

---

# 60. CSRF

CSRF architecture bergantung kepada final credential storage.

Bearer header architecture dan cookie architecture mempunyai threat model berbeda.

Jika HttpOnly cookie dipilih:

```text
SameSite strategy
+
CSRF protection
+
appropriate origin validation
```

harus menjadi bagian solution.

Exact mechanism deferred ke ADR authentication/security.

---

# 61. Browser Secrets

Frontend runtime dianggap:

```text
public environment
```

Tidak boleh terdapat browser-delivered:

```text
database credentials
signing keys
private API secrets
service credentials
```

Frontend `.env` naming tidak membuat value menjadi secret.

---

# 62. Dependency Governance

Dependencies harus:

```text
lockfile controlled
version reviewed
security audited
bundle impact reviewed
maintenance reviewed
license reviewed
```

Dependency besar tidak boleh dimasukkan untuk trivial requirement tanpa justification.

---

# 63. Observability

Frontend memiliki central observability abstraction.

Conceptually:

```text
Business Module
     ↓
Observability Port
     ↓
Adapter
     ↓
Monitoring Provider
```

Business module tidak boleh terikat langsung dengan vendor.

Minimum observability:

```text
runtime errors
API failures
performance
frontend version
route/module context
safe operational metadata
```

---

# 64. Privacy & Telemetry

Forbidden telemetry:

```text
Authorization header
bearer credential
password
raw sensitive forms
full sensitive request payload
full sensitive response payload
```

Analytics harus mengukur interaction, bukan menyalin domain data.

Example:

```text
GOOD:
student_filter_applied
```

bukan:

```text
student_filter_applied {
    students: [...]
}
```

---

# 65. Release Correlation

Frontend production build memiliki identifier seperti:

```text
release version
git SHA
build identifier
```

Error/performance telemetry harus dapat dikaitkan ke release.

Backend correlation identifiers seperti:

```text
request_id
trace_id
correlation_id
```

dapat dipertahankan jika tersedia dan aman.

---

# 66. Browser Support

Target:

```text
modern evergreen browsers
```

termasuk maintained versions dari:

```text
Chrome family
Edge
Firefox
Safari
```

Legacy browser tidak menjadi default requirement.

Support matrix harus dikendalikan pada build/tooling policy.

---

# 67. Testing Layers

Frontend harus mendukung:

```text
Unit
Component
Integration
End-to-End
```

Critical platform scenarios minimal mencakup:

```text
Login

/auth/me bootstrap

Logout

Membership discovery

Tenant switch success

Tenant switch failure

Workspace discovery

Workspace switching

Stale workspace recovery

Capability loading

Capability denied

Capability refresh

Session invalid recovery

403 authorization recovery

Validation errors

Network failures

Context race conditions
```

---

# 68. Isolation Tests

Testing harus membuktikan:

```text
Tenant A data
≠
Tenant B data
```

dan:

```text
Workspace X state
≠
Workspace Y state
```

Required race test:

```text
Request A starts
↓
Context changes
↓
Request A finishes
↓
old response cannot modify current context UI
```

---

# 69. CI Quality Gates

Minimum pipeline:

```text
install from lockfile
↓
format/lint
↓
TypeScript typecheck
↓
unit/component tests
↓
production build
↓
bundle regression check
↓
OpenAPI contract/client drift check
↓
dependency/security audit
↓
integration tests
↓
critical E2E smoke tests
```

Mandatory failed gate blocks merge.

---

# 70. Deployment Model

Production artifact:

```text
immutable static build
```

Delivery:

```text
CI build
↓
immutable artifact
↓
CDN/object storage
↓
release activation
```

No manual production file modification.

Rollback:

```text
reactivate previous tested artifact
```

---

# 71. Environment Model

Minimum:

```text
Development

Test / CI

Staging

Production
```

Environment dapat berbeda pada:

```text
API base URL
observability configuration
debug metadata
telemetry configuration
```

Environment tidak boleh berbeda dalam:

```text
authorization semantics
tenant isolation
credential protection requirements
```

---

# 72. API Deployment Compatibility

Frontend dan backend tidak boleh membutuhkan perfectly simultaneous deployment.

API contract tetap versioned:

```text
/api/v1
```

Breaking API changes harus mengikuti:

```text
requirement
↓
backend contract change
↓
OpenAPI update
↓
client regeneration
↓
frontend adaptation
```

Tidak boleh ada silent breaking contract.

---

# 73. Scalability Definition

Target:

```text
hundreds of thousands
of registered users
```

tidak sama dengan:

```text
hundreds of thousands
of concurrent sessions
```

Capacity planning harus membedakan:

```text
Registered Users

Active Users

Concurrent Sessions

Concurrent Requests

API RPS

Database Load

Background Workload

Realtime Connections
```

Frontend scalability terutama dicapai melalui:

```text
static CDN delivery
small initial bundle
lazy modules
context-safe caching
request deduplication
bounded concurrency
no unnecessary polling
observability
```

---

# 74. Explicit Anti-Patterns

Frontend Foundation melarang menjadikan berikut sebagai default architecture:

```text
one giant JavaScript bundle

one giant global store

all modules loaded at startup

unbounded retries

automatic mutation retries

unbounded polling

duplicated requests

cross-tenant generic cache keys

cross-tab credential synchronization

localStorage bearer credentials

role-name authorization

frontend-derived authorization

raw telemetry payload dumping
```

---

# 75. Business Module Contract

Setiap business module yang dibuat kemudian wajib:

```text
use shared authentication

use shared Tenant/Membership context

use shared Workspace context

use shared capability projection

use shared API/error infrastructure

use shared observability

respect shared accessibility baseline

respect context-safe server state
```

Module tidak boleh membuka ulang platform architecture secara lokal.

---

# 76. Out of Scope

Frontend Foundation PRD ini tidak mendefinisikan business workflows:

```text
student lifecycle
employee management
dormitory placement
admission workflows
finance transactions
library circulation
attendance processes
```

Semua itu memiliki PRD/TDD module masing-masing.

---

# 77. Deferred Architectural Decisions

Requirement sudah cukup jelas tetapi implementation mechanism berikut sengaja belum dikunci:

```text
Frontend framework final architecture details

Exact modular folder boundaries

Authentication credential storage mechanism

Memory-only vs sessionStorage
vs HttpOnly cookie
vs BFF
vs hybrid

Tenant switching implementation detail

Workspace context implementation

OpenAPI client generation architecture

TanStack Query configuration

Client-state technology

Route architecture

Code-splitting boundaries

Testing framework/tool selection

Exact CSP

CSRF mechanism

Observability provider

Performance monitoring provider
```

Keputusan tersebut adalah **ADR concerns**, bukan PRD gaps.

---

# 78. Deferred Product Capabilities

Belum menjadi Foundation v1 requirement:

```text
Remember Me

Refresh Token

Silent Refresh

MFA

Logout All Devices

Offline / PWA

Realtime WebSocket / SSE

enterprise feature flag platform
```

Jika dibutuhkan kemudian, harus melalui new requirement review.

---

# 79. Required ADR Workstream

Current candidate sequence:

```text
ADR-020
Frontend Framework & Rendering Strategy

ADR-021
Frontend Modular Application Architecture

ADR-022
Authentication Credential Storage
& Browser Session Isolation

ADR-023
Tenant / Membership Context Switching

ADR-024
Workspace / Organizational Context Management

ADR-025
API Client, OpenAPI
& Canonical Error Handling

ADR-026
Server-State & Client-State Ownership

ADR-027
Capability-Aware Navigation
& Authorization UX

ADR-028
Routing & Code-Splitting Strategy

ADR-029
Frontend Testing Strategy

ADR-030
Frontend Security Baseline

ADR-031
Frontend Observability
& Performance Strategy
```

ADR numbering wajib diverifikasi terhadap repository sebelum ADR pertama dikunci.

---

# 80. Mandatory ADR

Satu ADR dianggap mandatory sebelum implementation authentication:

```text
Authentication Credential Storage
& Browser Session Isolation
```

ADR tersebut harus membandingkan:

```text
Memory-only bearer credential

sessionStorage bearer credential

HttpOnly cookie

BFF / session broker

Hybrid strategy
```

terhadap requirement:

```text
XSS exposure
CSRF exposure
reload persistence
per-tab isolation
multi-Tenant simultaneous tabs
credential lifecycle
logout semantics
deployment complexity
backend compatibility
```

`localStorage` sudah ditolak dan tidak perlu menjadi viable candidate.

---

# 81. Cross-Requirement Consistency Review

FE-0 sampai FE-8 telah dibandingkan.

## Identity

```text
PASS
```

Tidak ada frontend requirement yang mengembalikan:

```text
User → Tenant ownership
```

## Authentication

```text
PASS
```

`/auth/me` tetap canonical bootstrap.

## Multi-Tab Isolation

```text
PASS
```

Tidak ada requirement yang memaksa globally shared Tenant credential.

## Membership Switching

```text
PASS
```

Tenant switch tetap authentication-context exchange.

## Workspace

```text
PASS
```

Workspace tetap runtime/read projection dan bukan Core entity.

## Authorization

```text
PASS
```

Permission/capability tetap runtime UX input dan backend tetap final authority.

## Errors

```text
PASS
```

Machine-readable error code tetap menjadi canonical branching mechanism.

## Caching

```text
PASS
```

Cache architecture konsisten dengan Tenant/Workspace isolation.

## Security

```text
PASS
```

Tidak ditemukan requirement yang membutuhkan localStorage bearer credential.

## Scalability

```text
PASS
```

NFR konsisten dengan SPA + CDN + modular lazy loading.

---

# 82. Identified Conflict Review

Tidak ditemukan konflik product requirement yang mengharuskan FE-0 sampai FE-8 dibuka ulang.

Khusus credential persistence terdapat trade-off antara:

```text
reload persistence
```

dan:

```text
credential attack surface
```

tetapi ini bukan contradiction.

Ini adalah architectural trade-off yang memang sengaja didelegasikan kepada ADR-022.

---

# 83. Identified Gap Review

Tidak ditemukan requirement gap yang menghalangi ADR phase.

Area berikut belum memiliki implementation answer:

```text
credential storage
exact state composition
query infrastructure details
routing structure
testing tool selection
security header configuration
monitoring vendor
```

namun semuanya mempunyai sufficient product constraints untuk diputuskan melalui ADR.

Status:

```text
NO PRD BLOCKER
```

---

# 84. Product Acceptance Criteria

Frontend Foundation dianggap memenuhi PRD jika implementation nantinya membuktikan:

```text
1. Authenticated user dapat bootstrap melalui /auth/me.

2. Browser reload dapat mempertahankan valid tab session
   sesuai architecture yang dipilih.

3. Dua tab dapat menggunakan Tenant berbeda secara independen.

4. User hanya dapat memilih Membership yang ditemukan backend.

5. Tenant switch menghasilkan authoritative new context.

6. Failed Tenant switch tidak merusak existing valid context.

7. Workspace discovery berasal dari backend.

8. Tenant context dapat berjalan tanpa organizational assignment.

9. Workspace switching tidak mengganti authentication credential.

10. Stale workspace dapat recovery ke Tenant context.

11. Runtime authorization menggunakan permission capability,
    bukan role names.

12. Backend 403 tidak dapat dioverride frontend.

13. Protected UI fails closed saat capabilities unresolved.

14. Authorization restriction hidden by default.

15. Business restriction disabled dengan explanation.

16. 401 tidak selalu menyebabkan logout.

17. Network errors tidak menyebabkan logout.

18. Backend validation tetap canonical authority.

19. Tenant/Workspace cache isolation terjaga.

20. Superseded context response tidak mencemari active UI.

21. Automatic mutation retry disabled by default.

22. Business modules lazy-load.

23. Initial bundle tidak memuat seluruh application modules.

24. Bearer credential tidak disimpan localStorage.

25. Sensitive authentication data tidak masuk telemetry.

26. CSP-compatible deployment tersedia.

27. Shared UI memenuhi accessibility baseline.

28. Runtime module errors terisolasi.

29. TypeScript strict diterapkan.

30. OpenAPI menjadi canonical frontend API contract.

31. Critical platform behavior memiliki automated tests.

32. CI mempunyai mandatory quality gates.

33. Static deployment dapat disajikan melalui CDN.

34. Deployment artifact immutable dan rollback-capable.

35. Production releases dapat diobservasi berdasarkan version.

36. Business modules memakai shared platform foundation
    dan tidak membangun parallel platform systems.
```

---

# 85. PRD Completion Matrix

| Phase | Scope                                  | Status          |
| ----- | -------------------------------------- | --------------- |
| FE-0  | Contract Baseline & Scope Verification | 🔒 LOCKED       |
| FE-1  | Product Scope & Personas               | 🔒 LOCKED       |
| FE-2  | Application Shell & Navigation UX      | 🔒 LOCKED       |
| FE-3  | Authentication & Session               | 🔒 LOCKED       |
| FE-4  | Tenant / Membership Context            | 🔒 LOCKED       |
| FE-5  | Workspace / Organizational Context     | 🔒 LOCKED       |
| FE-6  | Capability / Authorization UX          | 🔒 LOCKED       |
| FE-7  | Error, Loading & Recovery UX           | 🔒 LOCKED       |
| FE-8  | Frontend Non-Functional Requirements   | 🔒 LOCKED       |
| FE-9  | Consolidation & Final Review           | 🔒 LOCKED       |

---

# 86. Final Review Result

Review result:

```text
Product scope
✅ COMPLETE

Authentication requirements
✅ COMPLETE

Tenant requirements
✅ COMPLETE

Workspace requirements
✅ COMPLETE

Authorization UX requirements
✅ COMPLETE

Error/recovery requirements
✅ COMPLETE

Performance requirements
✅ COMPLETE

Security requirements
✅ COMPLETE

Accessibility requirements
✅ COMPLETE

Observability requirements
✅ COMPLETE

Scalability direction
✅ COMPLETE

Testability requirements
✅ COMPLETE

Deployment requirements
✅ COMPLETE

Architectural implementation choices
⏭️ ADR PHASE
```

No product-level blocker has been identified.

---

# 87. Proposed PRD Decision

```text
EduCore Frontend Foundation PRD

FE-0 → FE-8
🔒 COMPLETE

FE-9 Consolidation
🟡 PROPOSED

Recommendation:
LOCK PRD
```

Once locked:

```text
Frontend Foundation PRD
🔒 FROZEN
        ↓
ADR Identification / Verification
        ↓
ADR-020...
        ↓
Frontend TDD
        ↓
Implementation
```

---

# 88. Change Governance After PRD Lock

Setelah PRD dikunci:

Implementation detail:

```text
→ ADR / TDD
```

Architectural trade-off:

```text
→ ADR
```

Bug:

```text
→ implementation/test correction
```

New product requirement:

```text
→ explicit PRD amendment
```

Backend contract requirement:

```text
→ backend workstream
→ OpenAPI update
→ ADR jika architectural
```

Frontend tidak boleh melakukan implicit backend contract modification untuk convenience.

---

# FE-9 Locked State

```text
FE-9 — PRD Consolidation & Final Review

🔒 LOCKED / COMPLETE

Review:
NO CONTRADICTION
NO PRODUCT GAP
NO BACKEND FOUNDATION REOPENING
NO PRD BLOCKER

Next gate:
Frontend Foundation PRD Lock
```
