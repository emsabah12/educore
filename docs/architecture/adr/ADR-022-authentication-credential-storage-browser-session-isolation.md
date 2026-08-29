# ADR-022 — Authentication Credential Storage & Browser Session Isolation

**Version** : 1.1
**Status** : Accepted
**Date** : 2026-08-18
**Implementation Resolution** : 2026-08-29
**Scope** : Frontend Foundation — Browser Authentication, Credential Custody & Multi-Tab Isolation

---

> ## Decision Summary
>
> EduCore will **not expose or persist canonical backend bearer credentials inside the React browser runtime**.
>
> The first-party EduCore SPA will use a **Laravel-hosted Browser Authentication BFF / Session Broker**.
>
> Canonical EduCore bearer credentials remain:
>
> ```text
> server-side only
> ```
>
> Browser authentication is represented by a hardened:
>
> ```text
> HttpOnly
> +
> Secure
> +
> host-scoped
> +
> SameSite-protected
> browser session cookie
> ```
>
> The cookie identifies the authenticated **browser session**, not the active Tenant/Membership.
>
> Active Membership/Tenant selection remains **tab-local**. A tab may persist only a non-secret Membership restoration hint; it must never persist the bearer credential itself.
>
> The BFF/session broker holds canonical membership-scoped bearer credentials server-side and selects the appropriate credential only after validating the authenticated browser session and requested Membership context.
>
> Therefore:
>
> ```text
> Tab A → Membership A → Tenant A
>
> Tab B → Membership B → Tenant B
> ```
>
> remains possible even though both tabs share the browser authentication cookie.
>
> The React application never receives the canonical backend bearer token.
>
> This decision requires an **explicit backend browser-authentication workstream before frontend authentication implementation begins**. It extends the browser-facing authentication boundary without changing the canonical Person/User/Membership/Tenant model or backend authorization semantics.

## Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-016 — Database-Backed Tenant RBAC
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture

---

# Implementation Resolution — 2026-08-29

The Decision Summary and alternatives above remain the accepted architectural record. Prospective wording such as:

```text
requires an explicit backend browser-authentication workstream
before frontend authentication implementation begins
```

describes the repository state when ADR-022 was accepted.

That workstream is now implemented and locked.

Current first-party browser credential custody is:

```text
React SPA
    ↓
HttpOnly BrowserSession
    ↓
same-origin Laravel Browser Authentication BFF / Session Broker
    ↓
server-held Membership-scoped canonical bearer
    ↓
canonical /api/v1 protected resources
```

The React runtime does not receive, persist, reconstruct, or manually send the canonical bearer credential.

Current browser authentication control-plane operations are:

```text
GET  /api/v1/browser/session/csrf
POST /api/v1/browser/auth/login
POST /api/v1/browser/auth/logout
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

Canonical authenticated bootstrap remains:

```text
GET /api/v1/auth/me
```

and is available through the supported authentication transports defined by the executable OpenAPI contract.

The browser session still does **not** represent one global active Membership/Tenant. Active Membership selection remains tab-local and server-revalidated, preserving the multi-tab isolation decision in this ADR.

Frontend Foundation implementation completed through FEI-12 at:

```text
1094dad05ec4589a9e83a40fae249eef01591b94
```

This implementation resolution does not supersede ADR-022 and does not change canonical Person/User/Membership/Tenant or backend authorization semantics.

# 1. Context

The frozen Frontend PRD requires all of the following simultaneously:

```text
normal reload
→ preserves a still-valid browser session

Tab A
→ Tenant A

Tab B
→ Tenant B

bearer credential
→ opaque

localStorage bearer credential
→ forbidden

credential
→ must not enter URL/log/analytics/telemetry
```

The backend currently uses a canonical encrypted bearer token containing:

```text
user_id
tenant_id
membership_id
expires_at
```

Current token lifetime in the repository is:

```text
7200 seconds
= 2 hours
```

The token is validated through the canonical Token Manager and revocation store.

Tenant switching currently issues another bearer credential:

```text
Membership A / Tenant A
        ↓
POST membership switch
        ↓
Membership B / Tenant B
        ↓
new bearer credential
```

The old bearer is intentionally not automatically revoked.

These semantics permit different tabs to preserve different Tenant contexts.

The browser architecture must preserve that property without introducing unsafe persistent token custody.

---

# 2. Threat Model

The browser is not considered a trusted secret store.

Relevant threats include:

```text
XSS

malicious third-party JavaScript

supply-chain compromise

credential exfiltration

browser storage inspection

CSRF when cookies are used

cross-tab context contamination

stale credential reuse

accidental token logging

analytics / observability leakage
```

An `HttpOnly` cookie cannot be read through normal JavaScript APIs, although the browser will still attach it to applicable requests.

This improves credential confidentiality against token extraction, but it does **not** make XSS harmless.

Malicious code running inside the legitimate origin may still attempt authenticated operations using the victim's browser.

Therefore:

```text
BFF
≠
replacement for XSS protection
```

Frontend Security ADR must still enforce CSP, dependency security, output encoding, safe rendering, and related defenses.

---

# 3. Alternatives Considered

The following alternatives were evaluated:

```text
A. memory-only bearer

B. sessionStorage bearer

C. direct HttpOnly Tenant bearer cookie

D. token-mediating backend
   + memory-only access token

E. full BFF / Browser Session Broker

F. hybrid strategies
```

---

# 4. Option A — Memory-Only Bearer

Architecture:

```text
Login
 ↓
Bearer token
 ↓
JavaScript memory
 ↓
Authorization header
```

The credential disappears on:

```text
full page reload
tab process loss
application restart
```

### Advantages

```text
no persistent browser token storage

natural per-tab isolation

simple direct API integration
```

### Problem

The locked PRD requires:

```text
normal browser refresh
→ preserve still-valid tab session
```

Pure memory-only storage cannot provide this without an additional rehydration authority.

### Decision

```text
REJECTED
as a complete architecture
```

Memory-only storage could be part of a hybrid architecture, but cannot satisfy the PRD alone.

---

# 5. Option B — `sessionStorage` Bearer

Architecture:

```text
Bearer
 ↓
sessionStorage
```

`sessionStorage` has browser behavior that looks attractive for EduCore.

It is partitioned by origin **and top-level tab**, persists across reloads/restores, and is cleared when the tab/window session ends.

Therefore:

```text
reload persistence
✅

per-tab isolation
✅

backend changes
minimal
```

However the credential remains accessible to JavaScript.

Current OWASP session-management guidance explicitly warns against storing authentication tokens, session identifiers, JWTs, refresh tokens, or credentials in either `localStorage` or `sessionStorage`, because JavaScript executing in the origin can access them.

### Security consequence

```text
XSS
 ↓
sessionStorage access
 ↓
bearer theft
 ↓
token reusable outside browser
until expiry/revocation
```

Encrypting the token with a key also available to JavaScript does not create a meaningful security boundary against malicious JavaScript.

### Decision

```text
REJECTED
```

`sessionStorage` remains permitted for:

```text
non-secret restoration hints

selected membership ID

workspace restoration hint

ordinary tab-local UI state
```

but not authentication credentials.

---

# 6. `sessionStorage` New-Tab Behavior

One browser nuance is important.

A page opened with an `opener` may initially receive a copy of the opener's `sessionStorage`, after which both stores are independent.

Therefore even non-sensitive restoration state must always be treated as:

```text
hint
≠
authority
```

If Tab B initially inherits:

```text
membership_id = A
```

it may initially bootstrap Tenant A.

Afterwards Tab A and Tab B remain independently switchable.

Where appropriate, links intentionally opening a new browsing context should prevent unnecessary opener relationships.

---

# 7. Option C — Direct HttpOnly Bearer Cookie

Architecture:

```text
Tenant bearer
 ↓
HttpOnly cookie
 ↓
automatically sent by browser
```

This substantially improves protection against direct JavaScript credential extraction because `HttpOnly` prevents access through `document.cookie`.

However normal cookies are not scoped by browser tab in the way `sessionStorage` is.

That produces a fundamental EduCore problem:

```text
Tab A → Tenant A
Tab B → Tenant B

Tab B switches Tenant
        ↓
cookie changes
        ↓
Tab A also starts using new cookie
```

That contradicts the locked multi-tab Tenant isolation requirement.

### Decision

```text
REJECTED
as direct storage for active Tenant credential
```

A shared cookie can represent a **browser-level authentication session**, but it cannot itself be the mutable active Tenant credential.

---

# 8. Option D — Token-Mediating Backend

Architecture:

```text
Browser
   │
   ├── HttpOnly browser session cookie
   │
   └── access token in memory
            ↓
       canonical API
```

A backend retains authentication/session authority and can provide an access token to the browser when the application is bootstrapped.

This avoids persistent access-token storage while preserving direct browser-to-resource-API requests.

Current IETF browser-application guidance describes this as a middle-ground architecture: less complex than a full proxying BFF but weaker because the access token is still exposed to browser code.

### Advantages

```text
no persistent access-token storage

direct /api/v1 calls preserved

lower proxy overhead

natural per-tab in-memory token
```

### Security limitation

Malicious JavaScript can still obtain the currently exposed access token and potentially use it outside the victim's browser until it expires or is revoked.

### Decision

```text
VALID ALTERNATIVE

NOT SELECTED
```

It remains a reasonable fallback if future infrastructure constraints make the selected BFF model impractical.

---

# 9. Option E — Browser Authentication BFF / Session Broker

Selected architecture:

```text
React SPA
    │
    │ browser session cookie
    │ + tab-local context selector
    ▼
Browser Authentication BFF
    │
    │ canonical bearer credential
    ▼
EduCore /api/v1
```

Bearer credentials remain exclusively:

```text
server-side
```

The JavaScript application receives:

```text
authenticated context

Membership/Tenant identity

Workspace identity

capabilities

ordinary API responses
```

but never:

```text
raw canonical bearer credential
```

Current IETF browser-application guidance presents a full BFF before token-mediating/browser-only patterns in decreasing order of security because tokens remain on the backend instead of being exposed to browser application code.

Although that document is OAuth-focused and remains an Internet-Draft as of 2026, its malicious-JavaScript/token-custody threat model is directly relevant to EduCore's bearer credentials. It is used here as security guidance, not as a requirement that EduCore adopt OAuth.

### Decision

```text
SELECTED
```

---

# 10. Browser Authentication Cookie

The cookie represents:

```text
authenticated browser session
```

It does **not** represent:

```text
Tenant

Membership

Workspace

Role

Permission
```

Cookie minimum security baseline:

```text
HttpOnly
Secure in production
host-only where possible
Path=/
SameSite protected
bounded lifetime
```

`Secure` limits transmission to HTTPS, `HttpOnly` prevents normal JavaScript access, and `SameSite` restricts cross-site transmission.

Exact cookie name and deployment-domain policy are deferred to ADR-030.

---

# 11. No Remember-Me Cookie

Foundation v1 still does not introduce:

```text
Remember Me
```

Therefore browser authentication must not silently become an indefinite persistent login.

The browser session has:

```text
bounded server-side lifetime
```

and expires according to explicit authentication policy.

Browser session lifetime must not be inferred solely from whether the browser process happens to remain open.

---

# 12. Server-Side Credential Vault

The Browser Session Broker maintains only the authentication material necessary for active browser sessions.

Conceptually:

```text
BrowserSession
│
├── authenticated user identity
│
└── membership credentials
      ├── Membership A → Bearer A
      ├── Membership B → Bearer B
      └── ...
```

Only Membership contexts actually used during that browser session need to have server-held bearer credentials.

This is **not**:

```text
all memberships
×
all registered users
```

stored permanently.

Credentials remain bounded by:

```text
browser-session lifetime
+
canonical token expiry
```

---

# 13. Why a Membership Credential Map Is Required

A single server-side bearer credential would recreate the same cross-tab problem as a cookie.

Instead:

```text
Browser Session
       │
       ├── Token A → Membership A / Tenant A
       │
       └── Token B → Membership B / Tenant B
```

Each tab maintains its own selected Membership context.

Example:

```text
Tab A

selectedMembership = A
        ↓
BFF selects Token A
        ↓
Tenant A
```

while simultaneously:

```text
Tab B

selectedMembership = B
        ↓
BFF selects Token B
        ↓
Tenant B
```

The browser session can therefore be shared while Tenant context remains tab-local.

---

# 14. Tab-Local Membership State

A browser tab may keep:

```text
membership_id
```

as a restoration hint.

Conceptually:

```text
sessionStorage

educore.membership_hint
```

This value:

```text
is not a credential

is not authorization authority

is not trusted as Tenant authority
```

It is only:

```text
context selector / restoration hint
```

The BFF must validate that a canonical server-held credential exists or can legitimately be established for that Membership.

---

# 15. Browser Request Authentication

Conceptually a normal browser request becomes:

```text
Browser
  │
  │ HttpOnly browser-session cookie
  │
  │ selected Membership context
  ▼
BFF
  │
  │ resolve canonical server-side bearer
  ▼
Canonical API Authentication
```

The Membership selector alone must never authenticate the request.

Required:

```text
browser session
+
validated Membership context
+
canonical server-held bearer
```

Only then may the request enter existing authenticated API processing.

---

# 16. Canonical Backend Authority Remains Unchanged

ADR-022 does **not** replace ADR-015.

Internally the authenticated API continues to rely on:

```text
canonical bearer token
      ↓
Token Manager
      ↓
User
      ↓
Person
      ↓
Membership
      ↓
Tenant
```

Role and permission still do not come from the token.

BFF responsibilities end at:

```text
safe browser credential custody
+
request mediation
```

It does not become a second authorization engine.

---

# 17. `/auth/me` Remains Canonical Bootstrap

Canonical authenticated context still comes from:

```text
GET /api/v1/auth/me
```

The difference is credential transport.

Browser:

```text
React
 ↓
BFF
 ↓
canonical bearer
 ↓
/api/v1/auth/me
```

The browser does not derive authenticated identity from the cookie itself.

The cookie is:

```text
session transport
```

while `/auth/me` remains:

```text
canonical application bootstrap
```

---

# 18. Login Flow

Conceptually:

```text
UNAUTHENTICATED
      ↓
submit credentials
      ↓
Browser Authentication BFF
      ↓
canonical authentication service
      ↓
canonical Membership bearer created
      ↓
bearer stored server-side
      ↓
HttpOnly browser session established
      ↓
safe context returned
      ↓
/auth/me bootstrap
      ↓
AUTHENTICATED
```

The response visible to JavaScript must not expose:

```text
access_token
```

Browser login therefore requires a browser-specific authentication adapter/workstream around the existing canonical token issuance.

The existing token API may remain available for:

```text
mobile
API clients
trusted non-browser clients
```

according to backend requirements.

---

# 19. Tenant / Membership Switch

Browser flow:

```text
Tab A
Membership A
      ↓
request switch to Membership B
      ↓
BFF
      ↓
canonical membership-switch operation
      ↓
new Bearer B
      ↓
store Bearer B server-side
      ↓
return safe context only
      ↓
Tab A membership hint = B
```

The React application does not receive Bearer B.

---

# 20. Old Token Semantics

Existing backend semantics remain:

```text
membership switch
≠
automatic revocation of previous bearer
```

This is particularly important because another browser tab may still use:

```text
Membership A / Token A
```

Therefore switching Tab B to Membership B must not invalidate Tab A merely because both belong to the same browser session.

---

# 21. Multi-Tab Example

Initial state:

```text
Browser Session
├── Token A
└── Token B
```

Tab A:

```text
membership hint = A
workspace = X
```

Tab B:

```text
membership hint = B
workspace = Y
```

Requests resolve independently:

```text
Tab A
 ↓
A
 ↓
Token A
 ↓
Tenant A
```

and:

```text
Tab B
 ↓
B
 ↓
Token B
 ↓
Tenant B
```

No active Tenant value is stored globally inside:

```text
cookie
global store
localStorage
```

---

# 22. Normal Reload

Before reload:

```text
sessionStorage
→ Membership restoration hint

HttpOnly cookie
→ browser session
```

After reload:

```text
React bootstrap
      ↓
read non-secret Membership hint
      ↓
BFF browser session validation
      ↓
resolve canonical bearer
      ↓
/auth/me
      ↓
my-workspaces
      ↓
capabilities
```

This satisfies:

```text
reload persistence
```

without persisting the actual bearer credential in JavaScript-readable storage.

---

# 23. Fresh Tab

A fresh tab may have:

```text
no Membership hint
```

or may initially inherit non-secret session state depending on how the browsing context was created.

Either way, it does not receive a bearer credential from Web Storage.

The application must bootstrap through authoritative backend discovery before protected UI becomes available.

---

# 24. Workspace Context

Workspace architecture is unchanged.

Tenant-level workspace:

```text
no organizational assignment header
```

Organization/Unit workspace:

```text
X-EduCore-Organizational-Assignment-Id
```

The BFF forwards this locator to canonical backend processing.

It must not convert Workspace identity into authorization authority.

---

# 25. CSRF Consequence

Cookie-based BFF authentication introduces a CSRF threat that direct JavaScript-managed bearer headers did not have in the same form.

Therefore:

```text
CSRF protection
= mandatory
```

Current browser-application BFF guidance explicitly requires a proper CSRF defense for cookie-authenticated BFF interactions.

`SameSite` is part of the defense but must not automatically be treated as the sole control in every deployment topology, particularly when multiple applications may exist on related subdomains.

Final strategy will be locked by ADR-030 and is expected to include an appropriate combination of:

```text
SameSite

strict Origin policy

CORS allowlist

custom non-safelisted request header
and/or
anti-forgery token
```

No mutation may bypass the selected CSRF mechanism.

---

# 26. XSS Consequence

BFF improves the outcome of an XSS incident because the canonical bearer is not directly available for extraction and reuse outside the browser.

It does **not** prevent malicious JavaScript from attempting operations through the victim's authenticated browser.

Therefore:

```text
BFF
+
CSP
+
safe rendering
+
dependency governance
+
telemetry redaction
```

are complementary controls.

Not alternatives.

---

# 27. No Bearer in Browser Storage

After this ADR is accepted, the following are forbidden for canonical bearer credentials:

```text
localStorage
❌

sessionStorage
❌

IndexedDB
❌

Cache Storage
❌

window.name
❌

URL
❌

query string
❌

history state
❌

React persisted store
❌
```

Browser JavaScript:

```text
MUST NOT
```

persist the canonical bearer.

---

# 28. No Bearer in React State

Because the selected BFF never returns the bearer to the SPA:

```text
React Context
Redux-like store
Zustand-like store
TanStack Query cache
component state
```

must also never contain it.

This eliminates an entire category of accidental:

```text
DevTools exposure
state serialization
telemetry leakage
debug logging
```

from legitimate application architecture.

---

# 29. Server Session Scalability

A BFF using server-side authentication state introduces an explicit scalability cost.

Current IETF guidance notes that server-side session approaches require session distribution/replication considerations when applications scale horizontally.

EduCore therefore must not implement:

```text
single-process in-memory session state
```

as production architecture.

Production browser-session state must support:

```text
multiple Laravel instances
+
shared/replicated session state
+
TTL cleanup
+
session revocation
```

without requiring sticky sessions as the correctness mechanism.

Exact technology:

```text
Redis
database-backed session
other distributed store
```

is deferred to backend TDD/infrastructure design.

---

# 30. Capacity Model

The relevant capacity is:

```text
concurrent authenticated browser sessions
```

not:

```text
total registered users
```

For example, a platform containing hundreds of thousands of registered users does not require hundreds of thousands of BFF sessions to exist simultaneously unless those users are concurrently active.

Server-session capacity planning must use:

```text
concurrent sessions

active membership credentials/session

request throughput

session TTL

average session record size
```

rather than registered-user count.

---

# 31. Browser API Mediation

Protected browser API traffic must pass through the browser-authentication mediation layer so the canonical bearer can be attached server-side.

Conceptually:

```text
Browser request
      ↓
Browser Auth Middleware / BFF
      ↓
inject canonical bearer internally
      ↓
existing authentication middleware
      ↓
existing API operation
```

Exact route topology is deferred to:

```text
ADR-025
API Client, OpenAPI
& Canonical Error Handling
```

The goal is to reuse canonical API contracts and domain behavior rather than create a duplicate business API.

---

# 32. Canonical API Clients Remain Supported

ADR-022 does not eliminate direct bearer authentication.

Existing bearer authentication remains appropriate for:

```text
mobile applications

machine/API consumers

trusted API tooling

integration clients
```

when those client types are explicitly supported.

Browser SPA authentication is given an additional hardened boundary because browser JavaScript has a distinct threat model.

---

# 33. Canonical Error Contract

The BFF must preserve canonical backend error semantics.

It must not turn:

```text
AUTHENTICATION_REQUIRED

AUTHENTICATION_CONTEXT_DENIED

MEMBERSHIP_SWITCH_DENIED

ORGANIZATIONAL_CONTEXT_DENIED

VALIDATION_FAILED
```

into arbitrary frontend-specific text matching.

Existing:

```text
HTTP status
+
machine-readable code
```

remains authoritative.

---

# 34. BFF Must Not Hide Backend Authorization

Example:

```text
Browser
 ↓
BFF
 ↓
canonical API
 ↓
403 authorization denial
```

The BFF must return the canonical denial semantics.

It must never convert:

```text
backend denied
```

into:

```text
frontend allowed
```

Backend authorization remains final.

---

# 35. Logout

Browser logout must:

```text
revoke browser-session-held bearer credentials
      ↓
destroy Browser Session Broker state
      ↓
expire authentication cookie
      ↓
clear frontend protected state
```

Because the browser authentication cookie represents one browser session shared by tabs, browser logout terminates authentication for:

```text
all EduCore tabs
within that browser session
```

Those tabs must recover to unauthenticated state when their next request/bootstrap detects session invalidation.

This is **not**:

```text
Logout All Devices
```

Other browser/device authentication sessions are outside this operation.

---

# 36. Tenant Switch Is Not Logout

Tenant switching must not destroy the browser session.

```text
Browser Session
      remains

Membership A
      ↓
Membership B

Workspace
      reset

Capabilities
      reload
```

Other tabs remain authenticated and keep their own active Membership selection.

---

# 37. Session Failure

If BFF session validation fails:

```text
SESSION_INVALID
      ↓
clear protected frontend state
      ↓
clear restoration hints as appropriate
      ↓
login
```

A network failure is not equivalent to session failure.

A backend 403 authorization denial is also not equivalent to browser-session invalidation.

Existing FE-7 semantics remain unchanged.

---

# 38. SessionStorage Allowed Data

`sessionStorage` remains useful, but its scope is deliberately restricted.

Allowed examples:

```text
membership restoration hint

workspace assignment restoration hint

non-sensitive tab-local UI preferences

navigation recovery information
```

Forbidden:

```text
bearer token

browser session secret

password

CSRF secret if architecture requires it inaccessible to JS

sensitive business payload cache
```

All restored context identifiers must be revalidated by the backend.

---

# 39. No Client-Side Token Encryption Scheme

EduCore will not attempt patterns such as:

```text
encrypt bearer
↓
store encrypted bearer in sessionStorage
↓
keep decryption key in JavaScript
```

as a substitute for secure credential custody.

If malicious JavaScript can use both the encrypted value and the decryption mechanism, the architecture has not created a meaningful credential boundary.

Server-side custody is the selected boundary.

---

# 40. Backend Workstream Required

ADR-022 creates an intentional new requirement:

```text
Browser Authentication Adapter
/
BFF Session Broker
```

This must be implemented through an explicit backend workstream.

Required responsibilities include:

```text
browser login session creation

secure cookie issuance

server-side bearer custody

Membership credential selection

browser-request authentication mediation

Membership switch mediation

browser logout

session expiry

distributed session compatibility

CSRF protection integration

audit logging without credential leakage
```

This is **not** an implicit rewrite of backend foundation.

Governance:

```text
new frontend security requirement
      ↓
backend workstream
      ↓
backend tests
      ↓
OpenAPI update if public contract changes
```

---

# 41. Existing Backend Foundations Remain Frozen

The backend workstream must not reopen:

```text
Person identity

User semantics

Membership ownership

Tenant boundary

organizational topology

RBAC model

permission model

canonical bearer claims

Token Manager authority
```

The Browser Session Broker wraps the existing authentication contract.

It does not redefine it.

---

# 42. Browser Session Is Not Domain State

Browser session persistence must not become:

```text
active_tenant column on User

active_membership column on User

global server-side Tenant preference
```

Browser context is runtime/session state.

Canonical business identity remains:

```text
Person
 ↓
Membership × Tenant
```

---

# 43. Session Broker Is Platform Infrastructure

Frontend ownership:

```text
platform/auth
+
platform/session
```

Backend ownership should likewise be authentication/infrastructure boundary ownership.

Business modules such as:

```text
Academic
HR
Dormitory
Finance
```

must never implement their own browser session or credential storage system.

---

# 44. Observability

Permitted authentication telemetry:

```text
session created

session expired

session invalidated

membership switch succeeded/failed

authentication error code

request/correlation ID
```

Forbidden:

```text
raw bearer

raw cookie

password

session secret

full Authorization header
```

Logging and monitoring must redact credential-bearing headers.

---

# 45. Comparison Matrix

| Criterion                               | Memory Only | sessionStorage | Direct HttpOnly Tenant Cookie |           Token Mediator | BFF / Session Broker |
| --------------------------------------- | ----------: | -------------: | ----------------------------: | -----------------------: | -------------------: |
| Reload persistence                      |          ❌ |             ✅ |                            ✅ |                       ✅ |                   ✅ |
| Per-tab Tenant isolation                |          ✅ |             ✅ |                            ❌ |                       ✅ |                   ✅ |
| Token absent from persistent JS storage |          ✅ |             ❌ |                            ✅ |                       ✅ |                   ✅ |
| Token absent from JS runtime            |          ❌ |             ❌ |                            ✅ |                       ❌ |                   ✅ |
| Existing direct API simplicity          |          ✅ |             ✅ |                            ✅ |                       ✅ |                   ⚠️ |
| Backend work required                   |          ❌ |             ❌ |                            ⚠️ |                       ✅ |                   ✅ |
| CSRF surface from auth cookie           |        None |           None |                          High | Limited broker endpoints |         BFF requests |
| XSS token exfiltration resistance       |     Partial |           Weak |                        Strong |                  Partial |               Strong |
| Architecture complexity                 |         Low |            Low |                        Medium |                   Medium |               Higher |
| Meets all locked requirements           |          ❌ |             ⚠️ |                            ❌ |                       ✅ |                   ✅ |

Result:

```text
BFF / Browser Session Broker
= SELECTED
```

Security improvement justifies the additional backend/browser-session infrastructure for EduCore's sensitive multi-tenant administrative application.

---

# 46. Why Full BFF Over Token Mediator

Token mediation would be architecturally simpler.

However:

```text
access token
still enters JavaScript
```

Current browser-application security guidance explicitly identifies token theft as a remaining threat when access tokens are exposed to application code.

EduCore manages sensitive institutional information across domains including:

```text
students

employees

residents

administrative permissions

future financial information
```

Therefore the architecture chooses:

```text
reduce credential exposure
>
minimize initial implementation complexity
```

provided the added BFF infrastructure remains bounded and observable.

---

# 47. Why Not Rewrite Everything to Cookie Authentication

The decision is **not**:

```text
replace canonical EduCore bearer architecture
with Laravel session authentication
```

Canonical backend bearer authentication remains valuable for:

```text
API clients
mobile
integration
internal boundaries
```

The decision is:

```text
browser
should not possess that bearer
```

The BFF acts as the security adapter between the first-party browser environment and existing bearer-authenticated APIs.

---

# 48. Compatibility with ADR-020

ADR-020 remains valid.

Frontend assets can still be:

```text
Vite build
 ↓
static files
 ↓
CDN / Edge
```

while:

```text
API/BFF traffic
 ↓
Laravel
```

Browser-application guidance also treats static hosting and BFF deployment as separable responsibilities.

No Node SSR runtime is introduced.

---

# 49. Architectural Invariants

If ADR-022 is accepted:

```text
canonical bearer in localStorage
= FORBIDDEN

canonical bearer in sessionStorage
= FORBIDDEN

canonical bearer in IndexedDB
= FORBIDDEN

canonical bearer in React state
= FORBIDDEN

canonical bearer exposed to SPA
= FORBIDDEN

browser authentication
= HttpOnly-cookie-backed BFF session

active Tenant selection
= tab-local

browser cookie active Tenant
= FORBIDDEN

Membership restoration hint
= allowed, non-authoritative

server-held membership bearer
= canonical credential

BFF
≠ authorization engine

/auth/me
= canonical authenticated bootstrap

Membership switch
= canonical authentication-context exchange

Workspace switch
= no credential exchange

CSRF defense
= mandatory

XSS defense
= still mandatory

direct bearer API
= retained for supported non-browser clients

browser BFF
= Laravel-hosted

Node BFF
= not required

browser session store
= horizontally scalable

single-process session state
= not production architecture
```

---

# 50. Consequences

## Positive

- Bearer credentials are not exposed to React.
- Bearer credentials cannot be stolen simply by reading Web Storage.
- Reload persistence remains possible.
- Different tabs may retain different Membership/Tenant contexts.
- Existing canonical bearer architecture remains available internally.
- Mobile/API clients do not need to adopt browser cookie semantics.
- Authentication remains separate from authorization.
- Tenant switch semantics remain intact.
- CDN/static React deployment remains intact.
- Credential leakage through client-state persistence becomes substantially harder.

## Costs

- Backend browser-authentication infrastructure is required.
- Cookie/CSRF security must be implemented correctly.
- Server-side session/credential state requires distributed production storage.
- Browser requests require authentication mediation.
- OpenAPI/browser transport requires additional design in ADR-025.
- Logout becomes browser-session-wide across tabs.
- End-to-end authentication testing becomes more important.

These costs are accepted in exchange for avoiding persistent or directly extractable browser bearer credentials.

---

# 51. Risks

## Risk — BFF becomes duplicate authorization layer

Mitigation:

```text
BFF only resolves credential

canonical backend
still performs authorization
```

---

## Risk — BFF server-state bottleneck

Mitigation:

```text
distributed TTL session store

small bounded records

capacity based on concurrent sessions

observability

no sticky-session correctness dependency
```

---

## Risk — CSRF introduced by cookie authentication

Mitigation:

```text
Secure
HttpOnly
SameSite
Origin/CORS policy
anti-forgery/custom-header strategy
```

Exact controls will be locked by ADR-030.

---

## Risk — XSS still performs actions through BFF

Mitigation:

```text
strict CSP

no unsafe rendering

dependency governance

minimal third-party scripts

error/telemetry hygiene
```

BFF prevents direct bearer extraction; it does not make malicious JavaScript trustworthy.

---

# 52. Explicit Non-Decisions

ADR-022 does not yet decide:

```text
exact BFF route names

exact browser cookie name

Redis vs database session storage

exact session TTL

idle timeout

exact CSRF implementation

CORS deployment topology

OpenAPI browser-client adaptation

query client integration

CSP directives

MFA

Remember Me

Logout All Devices

refresh-token architecture
```

These belong to backend workstream, ADR-025, ADR-030, or future requirements as appropriate.

---

# 53. Required Tests

Before browser authentication can be considered complete, tests must prove:

```text
1. raw bearer never reaches browser response

2. raw bearer never enters Web Storage

3. raw bearer never enters frontend logs

4. login establishes HttpOnly browser session

5. browser reload restores valid auth context

6. Tab A Tenant A and Tab B Tenant B coexist

7. Tab B switch does not change Tab A Tenant

8. forged Membership selector cannot bypass validation

9. expired server-held token fails closed

10. revoked token fails closed

11. /auth/me remains canonical bootstrap

12. browser logout destroys browser session

13. browser logout revokes broker-held credentials

14. other devices are not implicitly logged out

15. CSRF attack is rejected

16. disallowed Origin is rejected

17. network failure does not trigger logout

18. authorization 403 does not trigger logout

19. workspace header remains locator only

20. credentials are redacted from observability
```

---

# 54. Backend Implementation Gate

Frontend authentication implementation must **not** begin by temporarily putting the bearer into:

```text
sessionStorage
```

with the intention of replacing it later.

That would create temporary architecture drift precisely at the security boundary.

Required sequence:

```text
ADR-022 Accepted
      ↓
Browser Authentication backend workstream
      ↓
OpenAPI/browser contract alignment
      ↓
backend tests
      ↓
frontend authentication TDD
      ↓
frontend implementation
```

---

# 55. References

Project sources:

- EduCore Frontend Foundation PRD — FE-0 through FE-9
- ADR-013
- ADR-014
- ADR-015
- ADR-016
- ADR-018
- ADR-020
- ADR-021
- canonical OpenAPI Foundation
- Auth Token Manager implementation
- Membership switch implementation

External security references:

- MDN — `sessionStorage` browser/tab lifecycle.
- MDN — secure cookie behavior and attributes.
- OWASP — Session Management / Web Storage guidance.
- IETF OAuth Working Group — Browser-Based Applications draft, used for browser bearer-token threat-model/BFF architecture guidance.

---

# ADR-022 Proposed State

```text
ADR-022
Authentication Credential Storage
& Browser Session Isolation

Status:
🔒 ACCEPTED / LOCKED

Selected:
Laravel-hosted Browser Authentication BFF
+
HttpOnly browser session
+
server-side canonical bearer custody
+
tab-local Membership selection

Bearer in localStorage:
❌ FORBIDDEN

Bearer in sessionStorage:
❌ FORBIDDEN

Bearer in React state:
❌ FORBIDDEN

Direct active-Tenant cookie:
❌ REJECTED

Token-mediating backend:
⚪ VALID ALTERNATIVE
   BUT NOT SELECTED

Full BFF / Session Broker:
✅ SELECTED

Multi-tab Tenant isolation:
✅ PRESERVED

Normal reload:
✅ PRESERVED

Canonical backend bearer architecture:
✅ PRESERVED

Backend authorization authority:
✅ PRESERVED

Backend browser-auth workstream:
⚠️ REQUIRED BEFORE IMPLEMENTATION
```
