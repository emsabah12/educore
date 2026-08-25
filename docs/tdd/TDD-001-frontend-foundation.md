# TDD-001 — EduCore Frontend Foundation

**Status:** Accepted
**Scope:** Frontend Platform Foundation
**Backend Baseline:** `66edb00`
**Backend Contract:** 🔒 FROZEN
**Implementation State:** NOT STARTED

---

# 1. Purpose

Dokumen ini menjadi canonical Test-Driven Development matrix untuk
implementasi Frontend Foundation EduCore.

Implementation mengikuti urutan:

```text
RED
↓
minimal GREEN implementation
↓
REFACTOR
↓
architecture / contract gate
↓
LOCK milestone
```

Milestone tidak boleh dianggap selesai hanya karena UI dapat dirender.

Setiap milestone harus membuktikan architectural invariant yang relevan.

---

# 2. Locked Technology Baseline

Frontend Foundation menggunakan:

```text
React 19
TypeScript strict
Vite 8
Tailwind CSS 4
React Router 8 Data Mode
TanStack Query
OpenAPI-generated TypeScript contract
Vitest
React Testing Library
Mock Service Worker
Playwright
```

Canonical deployment model:

```text
static React SPA
+
same-origin Laravel API/BFF
+
CDN/static artifact capable
```

Laravel tidak menjadi React page-rendering runtime untuk authenticated
application flow.

---

# 3. Repository Boundary

Canonical frontend application source:

```text
frontend/
├── index.html
└── src/
    ├── app/
    ├── platform/
    ├── shared/
    └── modules/
```

Existing scaffold:

```text
resources/js/app.js
resources/css/app.css
resources/views/welcome.blade.php
```

must not evolve into a second production frontend architecture.

There must be exactly one frontend application source of truth.

---

# 4. Backend Contract Baseline

Browser control-plane endpoints:

```text
GET  /api/v1/browser/session/csrf
POST /api/v1/browser/auth/login
POST /api/v1/browser/auth/logout
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

Canonical protected resource endpoints:

```text
GET /api/v1/auth/me
GET /api/v1/user/my-memberships
GET /api/v1/user/my-workspaces
GET /api/v1/core/authorization/capabilities
GET /api/v1/core/authorization/workspace-capabilities
```

The retired transitional endpoint:

```text
/api/v1/browser/auth/me
```

MUST NOT be reintroduced.

---

# 5. Security Invariants

The browser MUST NOT:

```text
store canonical bearer credentials
in localStorage
sessionStorage
IndexedDB
Cache Storage
React state
general-purpose global state
```

Authentication authority remains backend-owned.

BrowserSession cookie:

```text
HttpOnly
Secure in production
host-only
SameSite=Strict
```

is handled by the browser and Laravel BFF boundary.

Frontend MUST NOT manually construct canonical bearer Authorization headers.

Browser protected requests may use:

```text
X-EduCore-Membership-Id
```

and workspace-scoped operations may additionally use:

```text
X-EduCore-Organizational-Assignment-Id
```

Both are untrusted locators.

They are NOT authentication or authorization authority.

---

# 6. API Contract Invariants

Canonical contract source:

```text
docs/api/openapi.yaml
```

Generated TypeScript artifacts MUST be derived from this file.

Business modules MUST NOT:

```text
duplicate OpenAPI DTOs
parse canonical API errors independently
use scattered raw fetch calls
manually inject context headers
```

Generated artifacts are consumed behind:

```text
platform/api
```

The generated layer itself is not the application API abstraction.

---

# 7. State Ownership

Canonical ownership:

```text
Server state
→ TanStack Query

Authentication lifecycle
→ platform auth/session runtime

Membership/Tenant selection
→ platform tenancy runtime

Workspace selection
→ platform workspace runtime

Capability projection
→ TanStack Query + platform authorization

Form state
→ local/dedicated form abstraction

Transient UI
→ local React state

Shareable navigation state
→ URL/search params where appropriate
```

Foundation MUST NOT introduce Redux, Zustand, or another general-purpose
global store unless a later ADR explicitly requires it.

One QueryClient exists per running SPA/tab.

Authenticated Query cache MUST NOT be persisted to browser storage.

---

# 8. Context Isolation Invariant

Context-sensitive query identity MUST include the relevant context generation.

Conceptually:

```text
resource
+
session generation
+
membership/tenant when applicable
+
workspace when applicable
+
normalized operation parameters
```

A response from a superseded Tenant or Workspace context MUST NOT mutate
the current interactive UI.

Cancellation alone is not a correctness boundary.

Response fencing/generation validation remains required.

---

# 9. FEI-1 — Frontend Source Boundary & Toolchain

## Objective

Establish one independent, strict TypeScript React application boundary.

## RED Evidence

Before implementation prove:

```text
frontend/ does not exist
React is not installed
TypeScript config does not exist
frontend typecheck script does not exist
frontend production build does not exist
```

## GREEN Requirements

Implementation must establish:

```text
frontend/index.html
frontend/src/main.tsx
frontend/src/app/
frontend/src/platform/
frontend/src/shared/
frontend/src/modules/
```

and tooling for:

```text
TypeScript strict
React JSX
Vite
Tailwind CSS 4
import aliases
typecheck
production build
```

No business feature implementation is permitted in this milestone.

## Tests / Gates

Must prove:

```text
TypeScript strict mode enabled
npm lockfile exists
clean install from lockfile succeeds
typecheck succeeds
production build succeeds
single frontend entry exists
no second active resources/js application exists
```

---

# 10. FEI-2 — OpenAPI Generated Contract

## Objective

Make OpenAPI the compile-time frontend API authority.

## RED Evidence

No generated TypeScript contract currently exists.

## GREEN Requirements

Establish:

```text
OpenAPI generation command
generated contract directory
platform/api generated boundary
contract drift check
```

Generated files MUST be reproducible.

## Tests / Gates

Must prove:

```text
generation succeeds
second generation produces no diff
OpenAPI local refs remain valid
generated contract compiles
manual DTO duplication is absent
```

---

# 11. FEI-3 — Application Bootstrap & Providers

## Objective

Create the minimum application runtime composition root.

## GREEN Requirements

Composition must include only required Foundation providers such as:

```text
React root
QueryClient
router
platform runtime providers
top-level error boundary
```

Provider ownership belongs to:

```text
frontend/src/app
```

Business modules MUST NOT own global platform providers.

## Tests / Gates

Must prove:

```text
application mounts
provider composition is deterministic
QueryClient is created once per SPA runtime
bootstrap failure renders controlled recovery UI
```

---

# 12. FEI-4 — Canonical API & Browser Transport

## Objective

Create one HTTP boundary for EduCore frontend traffic.

## GREEN Requirements

`platform/api` owns:

```text
base URL handling
credentials policy
CSRF bootstrap
Membership locator injection
Workspace locator injection
canonical error normalization
AbortSignal propagation
```

Transport performs no automatic mutation retry.

Business modules MUST NOT call EduCore endpoints using scattered raw fetch.

## Tests / Gates

MSW integration tests must prove:

```text
credentials are included where required
no bearer token is exposed or constructed
Membership locator is injected only when required
Workspace locator is injected only when required
unknown canonical error code remains safe
network failure differs from API failure
cancelled request differs from failure
```

---

# 13. FEI-5 — Authentication & BrowserSession Lifecycle

## Objective

Implement BrowserSession-based authentication without exposing canonical
bearer credentials.

Canonical flow:

```text
CSRF bootstrap
↓
browser login
↓
canonical /auth/me bootstrap
↓
authenticated runtime
```

Logout:

```text
browser logout
↓
clear protected Query cache
↓
reset platform contexts
↓
anonymous runtime
```

## Tests / Gates

Must prove:

```text
successful login never exposes bearer
invalid credentials remain recoverable
/auth/me determines authenticated context
browser reload can restore valid BrowserSession
network failure does not imply logout
logout clears protected frontend state
```

Critical cookie/session behavior must eventually be proven with Playwright
against the real Laravel backend.

---

# 14. FEI-6 — Membership / Tenant Context

## Objective

Implement tab-local Membership selection.

Discovery comes from:

```text
GET /api/v1/user/my-memberships
```

Switch uses:

```text
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

## Tests / Gates

Must prove:

```text
only discovered Memberships can be selected
successful switch produces authoritative context
failed switch preserves previous valid context
Tenant switch invalidates/fences Tenant-sensitive queries
two tabs can hold different Tenant selections
superseded Tenant responses cannot contaminate new context
```

---

# 15. FEI-7 — Workspace / Organizational Context

## Objective

Implement optional organizational Workspace context.

Discovery:

```text
GET /api/v1/user/my-workspaces
```

Tenant context must remain valid without organizational assignment.

## Tests / Gates

Must prove:

```text
workspace selection does not change authentication credential
workspace belongs to current Membership/Tenant context
stale workspace recovers to Tenant-level context
workspace switch fences workspace-sensitive queries
superseded workspace responses cannot mutate current UI
```

---

# 16. FEI-8 — Capability / Authorization Runtime

## Objective

Use backend capability projection for authorization UX.

Canonical source:

```text
GET /api/v1/core/authorization/capabilities

GET /api/v1/core/authorization/workspace-capabilities
```

Authorization decisions MUST use permission capability names.

Role names MUST NOT become runtime frontend authorization authority.

## Tests / Gates

Must prove:

```text
capabilities unresolved
→ protected UI fails closed

permission absent
→ restricted control hidden by default

permission present + business restriction
→ control may be disabled with explanation

backend 403
→ cannot be overridden by frontend state
```

---

# 17. FEI-9 — Router, Shell & Navigation

## Objective

Establish React Router 8 Data Mode and stable authenticated shell.

Canonical router:

```text
createBrowserRouter
```

Route tree is static for a frontend build.

Route existence MUST NOT depend on current permissions.

Business modules contribute lightweight route/navigation contracts.

Heavy module implementation is lazy-loaded.

## Tests / Gates

Must prove:

```text
deep links work
protected routes fail closed while auth unresolved
direct URL authorization is enforced
module routes lazy-load
route IDs are unique
navigation visibility does not mutate router structure
unknown route renders controlled not-found state
```

---

# 18. FEI-10 — Error, Recovery & Observability

## Objective

Provide canonical failure handling and vendor-neutral frontend observability.

Platform must distinguish:

```text
API failure
network failure
contract failure
cancelled request
route failure
lazy chunk failure
```

Human-readable backend messages MUST NOT be used for branching logic.

Observability adapter MUST NOT expose credentials or sensitive context.

## Tests / Gates

Must prove:

```text
401 handling respects operation semantics
403 produces authorization recovery UX
validation errors map to fields safely
unknown errors fail safely
runtime module errors are isolated
sensitive auth data does not enter telemetry
```

---

# 19. FEI-11 — Security & Build Gates

## Objective

Lock frontend supply-chain, browser, and artifact security.

Required gates:

```text
lockfile install
format/lint
TypeScript typecheck
unit/component/integration tests
production build
OpenAPI drift check
dependency audit
bundle regression check
CSP compatibility check
```

Production browser artifact MUST NOT contain secrets.

`VITE_*` configuration is public by definition.

Production source maps MUST NOT be publicly deployed.

---

# 20. FEI-12 — Browser E2E & Final Foundation Gate

## Objective

Prove critical architecture in a real browser against Laravel.

Playwright critical scenarios:

```text
login
reload authenticated session
logout
CSRF rejection
Membership switching
two-tab Tenant isolation
Workspace switching
stale Workspace recovery
capability denial
direct protected route
session invalidation
context race fencing
deep-link refresh
```

MSW tests do not replace these security-sensitive real-backend tests.

---

# 21. Architecture Dependency Rules

Allowed dependency direction:

```text
app
↓
platform / shared / module public contracts

modules
↓
platform
shared
their own internals

platform
↓
shared where domain-neutral

shared
↓
no platform or business-module internals
```

Forbidden examples:

```text
platform → modules/dormitory/internal

shared → platform/auth

modules/dormitory → modules/academic/internal
```

Cross-module dependencies require explicit public contracts.

---

# 22. TDD Completion Rule

A milestone is complete only when all are true:

```text
required RED evidence exists
implementation reaches GREEN
tests are automated where applicable
typecheck passes
production build passes
architecture invariants remain valid
working tree scope is reviewed
milestone commit is isolated
```

Passing manual browser interaction alone is insufficient.

---

# 23. Foundation Final Acceptance

Frontend Foundation may be locked only after proving:

```text
React SPA source boundary is singular
TypeScript strict is enforced
OpenAPI client generation is deterministic
Browser bearer credential never reaches frontend
BrowserSession auth works against real Laravel
Tenant and Workspace cache isolation are proven
context races are fenced
capability authorization fails closed
module route code splitting works
canonical errors are normalized centrally
security/build/contract gates pass
critical Playwright scenarios pass
business modules consume shared platform infrastructure
```

---

# 24. Implementation Order

Canonical implementation order:

```text
FEI-1  Source Boundary & Toolchain
↓
FEI-2  OpenAPI Contract
↓
FEI-3  Bootstrap & Providers
↓
FEI-4  API Transport
↓
FEI-5  Authentication
↓
FEI-6  Membership / Tenant
↓
FEI-7  Workspace
↓
FEI-8  Authorization
↓
FEI-9  Router / Shell
↓
FEI-10 Error / Recovery / Observability
↓
FEI-11 Security / Build Gates
↓
FEI-12 E2E / Final Gate
```

Implementation MUST proceed one milestone at a time.

---

# 25. Decision

```text
TDD-001 — Frontend Foundation
🔒 ACCEPTED

FEI-1
→ implementation may begin