# ADR-030 — Frontend Security Baseline

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Browser Security, Session Cookie, CSRF, XSS/CSP, Origin Policy, Secrets & Supply Chain

---

> ## Decision Summary
>
> EduCore Frontend adopts a **defense-in-depth browser security baseline**.
>
> The production first-party application uses:
>
> ```text
> HTTPS only
> +
> same-origin SPA + Browser BFF boundary
> +
> host-only Secure HttpOnly session cookie
> +
> SameSite=Strict
> +
> Laravel CSRF / request-forgery protection
> +
> deny-by-default CSP
> +
> no browser bearer credential
> +
> strict secret handling
> +
> dependency / lockfile governance
> ```
>
> The preferred production topology is:
>
> ```text
> https://educore.example
> │
> ├── SPA / static assets
> │
> └── /api/* → Laravel / BFF
> ```
>
> Static assets may physically be served from CDN/edge infrastructure, but the browser-facing first-party application and Browser Authentication BFF SHOULD remain on the **same origin**.
>
> Cross-origin credentialed SPA/BFF deployment is **not a Foundation v1 default** and requires explicit architecture/security review.
>
> The canonical browser session credential:
>
> ```text
> MUST be HttpOnly
> MUST be Secure in production
> MUST be host-only
> MUST use Path=/
> SHOULD use a __Host- cookie prefix
> MUST use SameSite=Strict for Foundation v1
> ```
>
> Canonical bearer credentials remain server-side under ADR-022.
>
> Browser state-changing requests MUST remain protected by Laravel request-forgery/CSRF middleware.
>
> Production CSP MUST be delivered as an HTTP response header and must forbid executable inline/eval-style JavaScript by default.
>
> `dangerouslySetInnerHTML`, `innerHTML`, dynamic code execution, arbitrary runtime scripts, and browser-stored secrets are forbidden by default.
>
> Security exceptions require explicit justification, tests, and review rather than ad-hoc local bypasses.

## Related ADR

- ADR-015 — Authentication Token & Request Context
- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation
- ADR-023 — Tenant / Membership Context Switching
- ADR-024 — Workspace / Organizational Context Management
- ADR-025 — API Client, OpenAPI & Canonical Error Handling
- ADR-026 — Server-State & Client-State Ownership
- ADR-027 — Capability-Aware Navigation & Authorization UX
- ADR-028 — Routing & Code-Splitting Strategy
- ADR-029 — Frontend Testing Strategy

---

# 1. Context

EduCore is an authenticated multi-tenant administrative platform.

Its browser security requirements are stricter than those of a public informational SPA because successful compromise could expose or mutate data across areas such as:

```text
students
employees
dormitory residents
administrative permissions
future financial capabilities
```

ADR-022 already established:

```text
bearer credential
→ server-side only

browser
→ HttpOnly session
```

That substantially reduces direct bearer theft, but malicious JavaScript executing in the application origin can still act with the privileges available to legitimate application code. Current IETF browser-application guidance explicitly treats malicious JavaScript as a central browser threat and notes that it can tamper with application execution and invoke functionality available to legitimate JavaScript.

Therefore:

```text
BFF
≠ XSS protection
```

and:

```text
HttpOnly
≠ complete browser security
```

---

# 2. Current Repository Security State

Repository inspection currently shows:

```text
SESSION_DRIVER
= database

SESSION_HTTP_ONLY
= true

SESSION_SAME_SITE
= lax

SESSION_SECURE_COOKIE
= environment-dependent
```

There is currently no project-specific production baseline for:

```text
CSP

browser-BFF CSRF integration

same-origin deployment

security response headers

browser CORS allowlist

frontend dependency scanning
```

The repository also currently has:

```text
package.json
```

but no committed JavaScript lockfile.

ADR-030 turns these concerns into explicit Foundation requirements.

---

# 3. Threat Model

Frontend security must assume threats including:

```text
XSS / DOM XSS

malicious dependency code

compromised third-party runtime script

CSRF

session fixation / hijacking

subdomain compromise

cross-origin abuse

credential leakage

sensitive telemetry leakage

unsafe URL navigation

clickjacking

supply-chain dependency compromise

stale client authorization/context
```

We do not assume browser JavaScript itself forms a trusted security boundary.

---

# 4. Selected Deployment Security Boundary

Foundation v1 selects:

```text
same-origin browser application
+
same-origin BFF/API entry
```

Example:

```text
https://app.educore.example/
https://app.educore.example/api/v1/...
```

The actual static asset origin behind CDN/reverse-proxy infrastructure may differ internally.

The browser should still see one first-party application origin.

---

# 5. Why Same-Origin Is Preferred

The current IETF Browser-Based Applications draft notes that colocating the browser application and BFF on the same origin avoids cross-origin preflight overhead for legitimate interactions. It also requires BFF deployments to implement proper CSRF protection when cookies are used.

For EduCore, same-origin additionally reduces:

```text
credentialed CORS configuration

SameSite=None requirements

cross-origin cookie complexity

origin allowlist surface

configuration drift
```

without preventing CDN delivery.

---

# 6. Cross-Origin SPA/BFF Deployment

The following is not the Foundation baseline:

```text
https://app.example
        ↓
credentials
        ↓
https://api.example
```

It may be supported later, but requires explicit review covering at minimum:

```text
CORS allowlist

cookie SameSite policy

credentials mode

CSRF strategy

subdomain threat model

CSP connect-src

preflight behavior
```

Do not silently switch deployment topology through infrastructure configuration alone.

---

# 7. HTTPS Is Mandatory

Production authentication and protected application traffic must use:

```text
HTTPS
```

only.

Plain HTTP may exist only to redirect safely to HTTPS at the infrastructure boundary.

Browser session credentials must never be intentionally transmitted over plaintext HTTP.

---

# 8. HSTS Direction

Production should enable:

```text
Strict-Transport-Security
```

after HTTPS/domain rollout has been validated.

HSTS instructs supporting user agents to treat a host as HTTPS-only for its configured `max-age`. `includeSubDomains` has wider scope and therefore must only be enabled when all relevant subdomains are ready.

Exact:

```text
max-age
includeSubDomains
preload
```

values remain infrastructure/TDD decisions.

---

# 9. Browser Session Cookie

The browser session cookie established by ADR-022 must be:

```text
Secure

HttpOnly

Path=/

host-only

SameSite=Strict
```

in Foundation v1 production.

---

# 10. `__Host-` Cookie Prefix

The session cookie SHOULD use a name beginning with:

```text
__Host-
```

The cookie specification requires `__Host-` cookies to be Secure, have `Path=/`, and omit `Domain`, which makes them host-only.

Conceptual example:

```text
__Host-educore-session
```

Exact final name is implementation detail.

---

# 11. No Cookie Domain Attribute

Production browser authentication cookie:

```text
Domain
= absent
```

not:

```text
Domain=.educore.example
```

This prevents intentionally sharing the session credential across arbitrary sibling subdomains.

---

# 12. SameSite Policy

Foundation v1 chooses:

```text
SameSite=Strict
```

for the browser authentication cookie.

Current IETF BFF guidance recommends Strict as the stronger default for new deployments, while also noting that same-site sibling applications can still matter to CSRF threat analysis.

A future requirement such as external identity-provider navigation may require re-evaluation.

That change must be explicit.

---

# 13. HttpOnly Is Mandatory

Session cookie:

```text
HttpOnly = true
```

React must never need to inspect the browser authentication credential.

Any frontend architecture requiring:

```ts
document.cookie;
```

to retrieve the authentication session identifier contradicts ADR-022.

---

# 14. Session Lifetime

Browser authentication remains:

```text
bounded
```

and server-controlled.

Foundation v1 still has:

```text
Remember Me
= NOT IMPLEMENTED
```

The application must not create an indefinite authentication cookie for convenience.

Exact idle/absolute timeout belongs to the Browser Authentication backend TDD.

---

# 15. Session Regeneration

Successful authentication must establish/regenerate session state rather than reusing an unauthenticated pre-login session identity.

Logout must invalidate the server session and expire the corresponding browser cookie.

Exact Laravel mechanics belong to backend implementation.

---

# 16. Canonical Bearer Remains Server-Side

No frontend security change in ADR-030 weakens ADR-022.

Forbidden browser locations remain:

```text
localStorage

sessionStorage

IndexedDB

Cache Storage

React state

TanStack Query

URL

history state
```

for canonical bearer credentials.

---

# 17. CSRF Is Mandatory

Because BrowserSession authentication is cookie-based:

```text
CSRF defense
= mandatory
```

The current IETF BFF draft explicitly requires proper CSRF defense for cookie-authenticated browser/BFF interactions.

---

# 18. Laravel CSRF Baseline

EduCore Browser BFF should use Laravel 13's built-in request-forgery protection rather than inventing a parallel frontend CSRF framework.

Laravel 13's `PreventRequestForgery` middleware first evaluates browser request-origin information such as `Sec-Fetch-Site` and falls back to session-bound CSRF-token validation where origin verification does not establish same-origin trust.

### Decision

```text
Laravel PreventRequestForgery
= canonical browser CSRF foundation
```

---

# 19. State-Changing Browser Requests

At minimum:

```text
POST
PUT
PATCH
DELETE
```

browser-session operations must pass request-forgery protection.

Laravel documents these state-changing methods as requiring request-forgery verification.

---

# 20. GET Must Remain Safe

Browser API `GET` operations MUST NOT be introduced with hidden mutation semantics.

Rejected:

```text
GET /logout

GET /delete-user

GET /switch-membership
```

when those requests change server state.

This helps preserve both HTTP semantics and CSRF defenses.

---

# 21. CSRF Token Transport

The SPA may use Laravel's session-bound XSRF mechanism centrally through:

```text
platform/api
```

Laravel exposes an encrypted:

```text
XSRF-TOKEN
```

cookie that can be reflected by JavaScript into:

```text
X-XSRF-TOKEN
```

for request verification.

The CSRF token is not an authentication bearer credential.

---

# 22. CSRF Token Is Not an Auth Secret

Frontend code may need access to an anti-forgery value.

That does not violate ADR-022.

Semantics:

```text
CSRF token
→ proves browser request participation

session cookie
→ authentication credential
```

They are distinct concerns.

---

# 23. CSRF Integration Is Centralized

Business modules MUST NOT independently implement:

```text
read XSRF cookie

set CSRF headers

refresh CSRF tokens
```

This belongs to:

```text
platform/api
+
platform/session
```

---

# 24. Browser Login Is Also Protected

Browser login itself is a security-sensitive state transition.

The browser authentication workstream must ensure login requests participate in the selected origin/CSRF defense rather than creating an unprotected session-establishment exception.

---

# 25. Membership Switch Is CSRF-Protected

ADR-023 Membership switching changes authentication context.

Therefore its browser-safe mutation:

```text
MUST
```

pass CSRF/request-forgery protection.

---

# 26. Logout Is CSRF-Protected

Logout changes authenticated session state.

Therefore:

```text
POST browser logout
```

is required direction.

A cross-origin site must not be able to force logout/session mutation through an unprotected request.

---

# 27. CSRF Exclusions

Browser BFF routes must not be casually placed into CSRF exclusion configuration.

Exceptions are reserved for genuinely external server-to-server callbacks such as future webhook endpoints with their own authentication model.

Such routes are outside normal SPA authentication.

---

# 28. Same-Site Alone Is Not the Entire CSRF Architecture

`SameSite=Strict` is defense-in-depth.

It is not used as an excuse to disable Laravel request-forgery protection.

Current BFF guidance explicitly notes that SameSite defenses can have limitations when multiple applications share a site boundary.

Canonical strategy is:

```text
same-origin
+
SameSite
+
Laravel request-forgery protection
```

---

# 29. CORS Baseline

For the selected same-origin topology:

```text
credentialed cross-origin browser access
= disabled by default
```

No production architecture should emit:

```text
Access-Control-Allow-Origin: *
```

for credentialed authenticated browser APIs.

---

# 30. Future Cross-Origin CORS

If cross-origin BFF access is introduced:

```text
Origin
→ explicit allowlist only
```

and never:

```text
reflect arbitrary Origin
```

The IETF BFF guidance notes that CORS-based protection must account for safelisted requests and recommends a custom header when relying on preflight as a CSRF barrier.

That architecture would require explicit ADR review.

---

# 31. Membership Header Is Not CSRF Protection

The ADR-025 header:

```text
X-EduCore-Membership-Id
```

must not be treated as the sole anti-forgery control.

It is:

```text
context locator
```

not:

```text
anti-CSRF secret
```

---

# 32. Workspace Header Is Not CSRF Protection

Likewise:

```text
X-EduCore-Organizational-Assignment-Id
```

is a context locator.

Its presence cannot prove that a request originated from legitimate EduCore UI.

---

# 33. XSS Defense Strategy

Frontend XSS defense uses multiple layers:

```text
React safe rendering patterns

dangerous sink restrictions

CSP

dependency governance

third-party script minimization

runtime validation where relevant

BFF credential custody
```

No single mechanism is considered sufficient.

---

# 34. Text Rendering by Default

Untrusted strings are rendered as:

```text
text
```

not interpreted as HTML.

Example safe direction:

```tsx
<p>{student.name}</p>
```

instead of manually creating HTML strings.

---

# 35. `dangerouslySetInnerHTML`

Canonical rule:

```text
dangerouslySetInnerHTML
= FORBIDDEN BY DEFAULT
```

No business feature may introduce it simply to display rich backend content.

---

# 36. Rich HTML Exception

If a future requirement genuinely needs untrusted rich HTML:

```text
requirement
↓
approved sanitizer/constrained renderer
↓
security review
↓
tests
```

The sanitizer boundary must be centralized.

Business modules must not each invent their own HTML-cleaning logic.

---

# 37. Dangerous DOM APIs

Direct use of high-risk DOM sinks such as:

```text
innerHTML

outerHTML

insertAdjacentHTML

document.write
```

is forbidden by default.

W3C Trusted Types identifies HTML parsing sinks and related powerful APIs as DOM XSS injection sinks when attacker-controlled data reaches them.

---

# 38. Dynamic Code Execution

Production application code MUST NOT depend on:

```text
eval()

new Function()

string-based dynamic execution
```

CSP Level 3 explicitly includes control of inline script and dynamic code execution among CSP's security goals.

---

# 39. `javascript:` URLs

Application navigation and link helpers must reject:

```text
javascript:
```

URLs.

External URLs must be handled by explicit URL validation rather than arbitrary string concatenation.

---

# 40. CSP Is Mandatory

Production application documents must send:

```text
Content-Security-Policy
```

as an HTTP response header.

CSP Level 3 provides controls over executable/resource origins, inline execution, dynamic code execution, embedding, and navigation-related behavior.

---

# 41. Deny-by-Default CSP

Baseline direction:

```text
default-src 'none'
```

followed by explicit directives.

This forces new external resource requirements to become visible security decisions.

---

# 42. Baseline Production CSP

Conceptual baseline:

```text
default-src 'none';

script-src 'self';

style-src 'self';

img-src 'self';

font-src 'self';

connect-src 'self';

media-src 'self';

worker-src 'self';

manifest-src 'self';

object-src 'none';

base-uri 'none';

frame-ancestors 'none';

frame-src 'none';

form-action 'self';
```

Exact resource additions depend on actual product requirements and ADR-031 observability decisions.

CSP Level 3 defines these directive families, including `script-src`, `connect-src`, `object-src`, `base-uri`, `form-action`, and `frame-ancestors`.

---

# 43. No Unsafe Script Policy

Production:

```text
script-src
```

MUST NOT require:

```text
'unsafe-inline'

'unsafe-eval'
```

as the normal Foundation architecture.

A dependency requiring one of these values must be treated as a security/architecture problem, not silently added to CSP.

---

# 44. Inline Styles

Frontend should prefer:

```text
Tailwind classes
+
static CSS
```

rather than uncontrolled inline styles.

Foundation aims to keep:

```text
style-src 'self'
```

possible.

If a proven third-party component requires inline style attributes, the exception must be narrowly scoped and reviewed.

A style exception must never imply relaxing `script-src`.

---

# 45. CSP Connect Sources

`connect-src` must include only demonstrated runtime network destinations.

Initially:

```text
'self'
```

is sufficient for same-origin BFF/API traffic.

ADR-031 may explicitly add an observability endpoint if required.

---

# 46. External Images / Fonts

External resource origins are not automatically approved.

A requirement for:

```text
remote avatar CDN

external font provider

media CDN
```

must result in an explicit CSP allowlist change.

Prefer self-hosted application fonts/assets where practical.

---

# 47. Framing Protection

Baseline:

```text
frame-ancestors 'none'
```

because EduCore Foundation has no requirement to be embedded in another site.

CSP defines `frame-ancestors` specifically to restrict which ancestors may embed a resource.

A future embedding requirement requires security review.

---

# 48. Plugin Content

Baseline:

```text
object-src 'none'
```

EduCore has no Foundation requirement for browser plugin/object content.

---

# 49. Form Targets

Baseline:

```text
form-action 'self'
```

CSP's `form-action` directive constrains form-submission targets.

External form submission must be explicit rather than accidental.

---

# 50. Base URL Injection Protection

Baseline:

```text
base-uri 'none'
```

EduCore does not require a dynamic HTML `<base>` element.

CSP's `base-uri` directive exists to constrain document base URLs.

---

# 51. CSP Rollout

CSP implementation should use:

```text
staging/report-only evaluation
↓
fix legitimate violations
↓
production enforcement
```

rather than deploying a broad policy with permanent unsafe exceptions.

Violation reporting becomes part of ADR-031 observability.

---

# 52. CSP Reports Are Potentially Sensitive

Security-report payloads may contain:

```text
blocked URL
document URL
source context
```

Therefore CSP telemetry must obey the same privacy/redaction principles as other observability.

Do not automatically store full sensitive URLs indefinitely.

---

# 53. Trusted Types

Trusted Types is a relevant defense-in-depth technology for DOM injection sinks. W3C published a current Working Draft in June 2026 defining typed values and CSP-controlled enforcement for dangerous DOM sinks.

However Foundation v1 does not make:

```text
require-trusted-types-for 'script'
```

a mandatory cross-browser production invariant yet.

### Decision

```text
Trusted Types
= evaluate / report-only direction
= not mandatory Foundation enforcement
```

The architectural sink restrictions remain mandatory regardless.

---

# 54. Security Headers

In addition to CSP, production application responses should include a controlled security-header baseline.

At minimum:

```text
X-Content-Type-Options: nosniff
```

is required direction.

The current Fetch Standard defines `nosniff` as the value for `X-Content-Type-Options` and uses it to require MIME-type checks for relevant resource destinations.

---

# 55. Referrer Policy

Foundation selects:

```text
Referrer-Policy: no-referrer
```

for the authenticated application.

The Referrer Policy specification defines `no-referrer` as omitting referrer information entirely from outgoing requests.

EduCore does not require referrer leakage for application functionality.

---

# 56. Permissions Policy

Production should define a restrictive:

```text
Permissions-Policy
```

for powerful browser features not currently needed.

The Permissions Policy specification provides a mechanism for selectively enabling or disabling browser features/APIs.

Foundation direction:

```text
camera
microphone
geolocation
payment
other powerful APIs

→ denied unless required
```

Exact directive inventory is finalized during implementation because future EduCore features may legitimately require a specific capability.

---

# 57. Opener Isolation

Application-created external links should prevent unnecessary opener coupling.

For external/new-tab navigation:

```text
noopener
```

behavior is preferred where applicable.

A broader `Cross-Origin-Opener-Policy` may be evaluated during implementation, but is not locked until popup/SSO requirements are known.

---

# 58. Third-Party Runtime JavaScript

Foundation default:

```text
third-party runtime script tags
= none
```

Examples that require explicit security approval:

```text
tag managers

remote analytics snippets

chat widgets

remote executable SDKs
```

Observability should preferably integrate through bundled dependencies behind ADR-031's platform adapter.

---

# 59. Self-Hosted Build Artifacts

Production React code and installed JavaScript dependencies are preferably bundled into controlled EduCore build artifacts.

This allows:

```text
lockfile
+
code review
+
build hash
+
CSP self-origin
```

to govern runtime code.

---

# 60. Subresource Integrity

If an unavoidable static resource is loaded from an external origin, Subresource Integrity should be considered together with CSP.

W3C SRI defines a mechanism allowing user agents to verify that a fetched resource has not been unexpectedly modified.

SRI is not required for EduCore's own content-hashed self-hosted Vite chunks.

---

# 61. JavaScript Lockfile Is Mandatory

Frontend implementation MUST commit exactly one canonical package-manager lockfile.

Current repository has none.

This must be corrected before frontend dependencies become production architecture.

---

# 62. Reproducible CI Install

CI must install dependencies from the committed lockfile using the package manager's reproducible/frozen installation mode.

Dependency resolution must not silently select newer versions during each production build.

---

# 63. Dependency Audit

CI must include dependency vulnerability scanning.

A reported vulnerability is evaluated based on:

```text
severity

reachability

runtime/dev dependency

available fix

application exposure
```

rather than blindly ignored or blindly failing forever.

Release-blocking policy is finalized in TDD/CI governance.

---

# 64. Dependency Admission

New frontend dependency review should consider:

```text
is it necessary?

maintenance health

bundle impact

security history

transitive dependencies

license

browser runtime privileges
```

A one-line convenience should not automatically justify a large dependency tree.

---

# 65. Package Lifecycle Scripts

Dependencies with install/build lifecycle scripts deserve additional scrutiny.

Frontend build infrastructure must not assume arbitrary dependency scripts are harmless merely because a package exists in the registry.

Exact package-manager enforcement remains TDD.

---

# 66. No Runtime Package Downloading

Production application must not dynamically download and execute arbitrary JavaScript package code based on runtime user/server input.

Feature/module code comes from the signed/deployed application build.

This is consistent with ADR-028's static route/module graph.

---

# 67. Browser Environment Is Public

All browser-delivered code, configuration, URLs, and `import.meta.env` values must be treated as public.

Vite explicitly exposes `VITE_`-prefixed environment variables into the client bundle and warns that they must not contain sensitive information.

---

# 68. No Secrets in `VITE_*`

Forbidden:

```text
VITE_DB_PASSWORD

VITE_PRIVATE_API_KEY

VITE_SIGNING_KEY

VITE_SERVICE_ACCOUNT_SECRET

VITE_BEARER_TOKEN
```

Anything exposed through frontend build-time configuration must be safe for every user to inspect.

---

# 69. Allowed Browser Configuration

Reasonable public configuration includes:

```text
application version

public API base path

environment label

public feature rollout flag

public observability project identifier
```

provided none functions as a privileged secret.

---

# 70. Frontend Source Maps

Source maps are not credentials, but can reveal application internals.

Production source-map policy must therefore be controlled.

Preferred direction:

```text
hidden/private upload to observability
```

if source maps are needed for diagnostics.

Public source maps are not a Foundation requirement.

Exact policy belongs to ADR-031.

---

# 71. Sensitive Logging

Frontend MUST NOT log:

```text
passwords

Authorization headers

browser session cookies

CSRF secrets

raw sensitive forms

full protected API payloads
```

through:

```text
console

analytics

error telemetry

debug instrumentation
```

---

# 72. Password Lifecycle

Password exists only long enough to submit the login request.

It MUST NOT enter:

```text
global state

Query cache

browser storage

URL

analytics

observability context
```

After submission lifecycle completes, application code must not retain it for convenience.

---

# 73. URL Security

Forbidden in URL/path/query/fragment:

```text
bearer tokens

passwords

browser session identifiers

CSRF secrets

private signing values
```

Route parameters and return URLs remain untrusted input.

---

# 74. Safe Redirects

Post-login return paths and application redirects must validate that the destination is an allowed internal application location.

Rejected:

```text
//attacker.example

https://attacker.example

javascript:...
```

as trusted redirect targets.

---

# 75. `window.open` / External Navigation

External destinations must be deliberately classified as external.

User-controlled URLs must not be passed directly into executable/navigation APIs without validation.

---

# 76. File Upload Security Boundary

Future browser file-upload components may validate:

```text
size

extension

MIME hints
```

for UX.

But browser validation is never authoritative.

Backend must revalidate file content, size, type, ownership, and storage policy.

Detailed upload architecture is domain-specific and not introduced by this ADR.

---

# 77. Client Validation Is Not Security Validation

Likewise:

```text
required fields

numeric ranges

UUID formatting
```

may be checked client-side for UX.

Canonical backend validation remains mandatory.

Frontend controls cannot protect API endpoints from crafted requests.

---

# 78. Authorization Remains Backend-Owned

ADR-027 remains unchanged:

```text
hidden button
≠ security control

route guard
≠ security control

capability cache
≠ security authority
```

Backend authorization is final.

---

# 79. Context Locators Remain Untrusted

The following remain request input:

```text
X-EduCore-Membership-Id

X-EduCore-Organizational-Assignment-Id
```

They must be validated server-side for every relevant request.

Client-generated IDs never become authority.

---

# 80. Prototype Pollution / Unsafe Object Merging

External/unknown JSON should not be merged blindly into security-sensitive configuration or runtime objects.

Critical external responses remain typed/validated according to ADR-025.

Generic:

```text
Object.assign(globalConfig, response)
```

style patterns should be avoided for untrusted payloads.

---

# 81. `postMessage`

Foundation v1 has no requirement for cross-window message-based authentication/context exchange.

If `postMessage` is introduced later:

```text
origin validation
+
message schema validation
```

will be mandatory.

No wildcard:

```text
targetOrigin = "*"
```

for sensitive messages.

---

# 82. Service Worker

ADR/PRD already excludes Service Worker/offline PWA from Foundation v1.

Security reinforces that decision.

A Service Worker can alter request/cache behavior and would introduce additional:

```text
versioning
cache
credential
deployment
stale-content
```

security complexity.

---

# 83. Browser Extensions Are Out of Trust Boundary

EduCore cannot guarantee security against a fully privileged malicious browser extension installed by the user.

The architecture focuses on application-controlled code, origin boundaries, server authority, and reasonable browser threat controls.

---

# 84. Security Error Messages

Authentication/authorization/security errors should provide enough information for the legitimate user to recover without exposing:

```text
bearer contents

stack traces

database details

cryptographic internals

sensitive authorization internals
```

Production raw stack traces remain forbidden.

---

# 85. Development vs Production

Development may require additional tooling such as:

```text
Vite HMR
dev WebSocket
source maps
```

Production CSP/security policy must not be weakened permanently because development tooling needs broader privileges.

Separate environment-specific policies are allowed provided security semantics remain equivalent.

---

# 86. No `unsafe-eval` Because Dev Needs It

If development tooling requires a relaxed setting:

```text
development only
```

must be explicit.

Production:

```text
unsafe-eval
= forbidden
```

remains the invariant.

---

# 87. CSP Compatibility Gate

Every production dependency/UI library must work under the selected production CSP or have an explicitly approved narrow exception.

This is part of dependency admission.

A library that requires arbitrary inline scripts has a significant architecture cost.

---

# 88. Security Testing

ADR-029 tests must prove at minimum:

```text
bearer absent from browser storage

session cookie HttpOnly

session cookie Secure in production

session cookie host-only

SameSite expected value

CSRF-valid mutation succeeds

CSRF-invalid mutation fails

cross-origin forged request rejected

browser login protected

browser logout protected

Membership switch protected

CSP present

unsafe inline script blocked

eval unavailable under production CSP

frame embedding blocked

invalid external redirect rejected

secrets absent from Vite bundle configuration

sensitive logging redacted
```

---

# 89. CSP Tests

Production/staging integration tests should assert required CSP directives.

At minimum:

```text
default-src

script-src

object-src

base-uri

frame-ancestors

form-action

connect-src
```

must not regress silently.

---

# 90. Cookie Tests

Backend/E2E tests must assert session cookie flags directly from response headers where appropriate.

Do not rely on visual browser behavior alone.

---

# 91. CSRF Tests

Because Laravel automatically disables CSRF middleware in ordinary HTTP tests under some testing configurations, browser-auth security tests must explicitly ensure the relevant test path actually exercises request-forgery protection rather than receiving a false green from test-environment defaults. Laravel documents that CSRF middleware is disabled by default during normal HTTP testing.

This is particularly important for the BFF workstream.

---

# 92. Same-Origin Security Test

Deployment E2E must verify the browser-facing SPA and BFF origin match the locked topology.

An infrastructure change that suddenly introduces credentialed cross-origin traffic must not pass unnoticed.

---

# 93. CORS Test

Protected BrowserSession APIs should reject unapproved cross-origin credentialed use.

If CORS is not required:

```text
no broad authenticated CORS policy
```

is the expected outcome.

---

# 94. Supply-Chain Test Gates

Frontend CI must include:

```text
lockfile present

frozen dependency install

dependency audit

production build

architecture lint

test suite
```

A missing lockfile is a merge/release blocker once frontend implementation begins.

---

# 95. Security Architecture Enforcement

Lint/static analysis should detect where practical:

```text
dangerouslySetInnerHTML

eval

new Function

direct innerHTML

javascript: URL literals

bearer storage

Authorization construction

direct document.cookie auth access

unsafe storage helpers
```

Exceptions must be narrowly scoped.

---

# 96. Security Review Boundary

The following changes require explicit security review:

```text
cookie policy relaxation

new authenticated origin

new third-party runtime script

new CSP external source

unsafe-inline / unsafe-eval request

HTML sanitizer introduction

Service Worker introduction

new browser secret

iframe/embed requirement

postMessage auth/context flow

cross-origin BFF deployment
```

---

# 97. Architectural Invariants

If ADR-030 is accepted:

```text
Production transport
= HTTPS

First-party SPA/BFF topology
= SAME ORIGIN

Credentialed cross-origin BFF
= NOT Foundation default

Browser authentication
= HttpOnly session cookie

Session cookie Secure
= REQUIRED production

Session cookie Domain
= ABSENT

Session cookie Path
= /

Session cookie SameSite
= Strict

__Host- cookie prefix
= REQUIRED direction

Canonical bearer in JavaScript
= FORBIDDEN

CSRF protection
= REQUIRED

Laravel request-forgery middleware
= canonical mechanism

CSRF exclusion for BFF routes
= FORBIDDEN by default

GET mutation
= FORBIDDEN

CORS
= deny cross-origin by default

CSP
= REQUIRED

CSP production delivery
= HTTP header

default-src
= 'none' baseline

script unsafe-inline
= FORBIDDEN

script unsafe-eval
= FORBIDDEN

dangerouslySetInnerHTML
= FORBIDDEN by default

untrusted HTML
= sanitize/constrained renderer only

third-party runtime scripts
= FORBIDDEN by default

object-src
= 'none'

base-uri
= 'none'

frame-ancestors
= 'none'

form-action
= 'self'

Referrer-Policy
= no-referrer

X-Content-Type-Options
= nosniff

Permissions Policy
= least privilege

Service Worker
= NOT Foundation v1

frontend secrets
= FORBIDDEN

VITE_* secret values
= FORBIDDEN

JavaScript lockfile
= REQUIRED

dependency vulnerability scan
= REQUIRED

backend validation
= FINAL

backend authorization
= FINAL
```

---

# 98. Consequences

## Positive

- Canonical bearer never enters browser JavaScript.
- Cookie scope is narrowed to the application host.
- CSRF protection is explicit rather than implied by SameSite alone.
- Same-origin deployment reduces CORS/cookie complexity.
- CSP materially limits unintended executable/resource origins.
- Third-party runtime JavaScript is minimized.
- Browser secrets have an explicit zero-tolerance rule.
- Cross-Tenant security remains server-authoritative.
- Dependency changes become reproducible and auditable.
- XSS-sensitive APIs become visible architectural exceptions.
- Security requirements are directly testable under ADR-029.

## Costs

- Same-origin production routing requires CDN/reverse-proxy coordination.
- Strict CSP may reject some convenient frontend libraries.
- External integrations require explicit allowlisting.
- Browser login/BFF implementation must participate in Laravel CSRF/session middleware.
- A JavaScript lockfile and vulnerability-scanning process must be introduced.
- Security headers require staging/production verification.
- Future SSO, iframe, camera, cross-origin API, or rich-HTML requirements may require explicit policy amendments.

These costs are accepted because relaxing browser boundaries after implementation is significantly harder and more error-prone than establishing secure defaults before the frontend exists.

---

# 99. Explicit Non-Decisions

ADR-030 does not yet decide:

```text
exact session cookie name

exact server session TTL

Redis vs database BrowserSession storage

HSTS max-age

HSTS includeSubDomains/preload

exact Permissions-Policy feature list

exact CSP reporting endpoint

exact sanitizer package

exact dependency audit vendor

exact security-scanner vendor

exact source-map upload strategy

exact CSRF bootstrap route name

Trusted Types enforcement timing

future external SSO policy
```

Those belong to Browser Authentication TDD, infrastructure configuration, ADR-031, or later explicit requirements.

---

# 100. Backend Follow-Up

ADR-030 reinforces the backend workstream already created by ADR-022.

Required Browser Authentication implementation must include:

```text
session middleware

host-only secure cookie

request-forgery middleware

browser login contract

browser logout contract

Membership switch mediation

CSRF lifecycle

Origin/request-origin behavior

security headers where Laravel/edge owns delivery

tests
```

This remains additive.

It does not reopen canonical backend:

```text
Person

User

Membership

Tenant

RBAC

organizational topology

bearer token claims
```

---

# 101. Frontend Follow-Up

Frontend Foundation setup must establish:

```text
safe platform/api CSRF integration

no bearer APIs

storage restrictions

CSP-compatible React implementation

safe URL helper

security-oriented lint rules

dependency lockfile

security-focused tests
```

before business modules depend on the frontend platform.

---

# 102. Follow-Up Dependency

After ADR-030, the final planned Frontend Foundation architecture decision is:

```text
ADR-031
Frontend Observability & Performance Strategy
```

ADR-031 must integrate safely with this security baseline.

In particular it must answer:

```text
what telemetry leaves the browser?

how are sensitive fields redacted?

how are CSP violations reported?

how are source maps handled?

how are Web Vitals measured?

how are bundle budgets enforced?

how are frontend release IDs correlated with backend request IDs?
```

without requiring unsafe CSP relaxations or third-party script injection.

---

# ADR-030 Proposed State

```text
ADR-030 — Frontend Security Baseline

Status:
🔒 ACCEPTED / LOCKED

Production:
HTTPS

SPA + BFF:
SAME ORIGIN

Cross-origin credentialed BFF:
❌ NOT FOUNDATION DEFAULT

Browser session:
HttpOnly
Secure
Host-only
Path=/
SameSite=Strict

__Host- prefix:
✅ REQUIRED DIRECTION

Bearer in browser:
❌ FORBIDDEN

Laravel CSRF protection:
✅ REQUIRED

SameSite only as CSRF defense:
❌ REJECTED

CSP:
✅ REQUIRED

unsafe-inline script:
❌ FORBIDDEN

unsafe-eval:
❌ FORBIDDEN

dangerouslySetInnerHTML:
❌ FORBIDDEN BY DEFAULT

Third-party runtime scripts:
❌ FORBIDDEN BY DEFAULT

Referrer:
no-referrer

nosniff:
✅ REQUIRED

Permissions Policy:
LEAST PRIVILEGE

Service Worker:
❌ FOUNDATION v1

Frontend secrets:
❌ FORBIDDEN

VITE_* secrets:
❌ FORBIDDEN

JS lockfile:
✅ REQUIRED

Dependency scan:
✅ REQUIRED

Backend validation/authz:
✅ FINAL AUTHORITY
```
