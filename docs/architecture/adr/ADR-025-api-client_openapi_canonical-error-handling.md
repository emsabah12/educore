# ADR-025 — API Client, OpenAPI & Canonical Error Handling

**Version** : 1.1
**Status** : Accepted
**Date** : 2026-08-18
**Implementation Resolution** : 2026-08-29
**Scope** : Frontend Foundation — HTTP Contract, Browser Transport, Generated Client, Context Propagation & Error Normalization

---

> ## Decision Summary
>
> EduCore will use:
>
> ```text
> docs/api/openapi.yaml
> ```
>
> as the **single canonical HTTP contract source** for frontend/backend integration.
>
> TypeScript API contracts and operation bindings will be generated from OpenAPI and placed behind a handwritten:
>
> ```text
> platform/api
> ```
>
> boundary.
>
> Business modules MUST NOT:
>
> ```text
> manually construct Authorization headers
> manually inject Membership context
> manually inject Workspace headers
> parse canonical errors independently
> duplicate OpenAPI DTOs
> call EduCore API using scattered raw fetch()
> ```
>
> Browser requests use the BFF/session-broker architecture established by ADR-022.
>
> The canonical bearer token remains server-side.
>
> Browser protected requests identify their tab-local Membership using:
>
> ```text
> X-EduCore-Membership-Id
> ```
>
> This header is:
>
> ```text
> locator only
> ≠ authentication
> ≠ authorization
> ```
>
> Organizational requests additionally use the existing:
>
> ```text
> X-EduCore-Organizational-Assignment-Id
> ```
>
> The canonical resource API remains:
>
> ```text
> /api/v1
> ```
>
> rather than creating a second mirrored `/bff/api/...` business API.
>
> Browser session authentication is added as another transport mechanism at the Laravel boundary while canonical controllers/services and response contracts remain shared.
>
> Browser-specific authentication control-plane operations—login, logout, and Membership credential exchange—MUST use dedicated safe browser contracts that never expose `access_token`.
>
> Canonical frontend error decisions use:
>
> ```text
> HTTP status
> +
> stable machine-readable error code
> +
> operation semantics
> ```
>
> and never human-readable message matching.

## Related ADR

- ADR-015 — Authentication Token & Request Context
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation
- ADR-023 — Tenant / Membership Context Switching
- ADR-024 — Workspace / Organizational Context Management

---

# Implementation Resolution — 2026-08-29

The OpenAPI/platform-adapter/error-normalization decisions remain accepted and are now implemented.

Prospective sections in this ADR that say BrowserSessionAuth, browser-safe authentication operations, or exact Laravel route names "must be added", are "required before implementation", or are "finalized by backend TDD" record the authoring-time state.

The finalized browser authentication control plane in the canonical OpenAPI contract is:

```text
GET  /api/v1/browser/session/csrf
POST /api/v1/browser/auth/login
POST /api/v1/browser/auth/logout
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

The canonical protected bootstrap is:

```text
GET /api/v1/auth/me
```

and `/api/v1/browser/auth/me` is retired and must not be treated as a parallel bootstrap contract.

Protected canonical `/api/v1` resource operations support the applicable authentication transports without creating a mirrored browser business API:

```text
BearerAuth
→ supported non-browser/API clients

BrowserSessionAuth
→ first-party SPA
```

Both converge on shared canonical controller/application/authorization semantics.

For browser protected requests:

```text
X-EduCore-Membership-Id
→ untrusted tab-local Membership locator

X-EduCore-Organizational-Assignment-Id
→ untrusted Workspace/organizational locator
```

Neither header is authentication or authorization authority.

Generated TypeScript contracts remain machine-owned under the frontend platform API boundary and must not be manually edited.

Frontend Foundation implementation completed through FEI-12 at:

```text
1094dad05ec4589a9e83a40fae249eef01591b94
```

This implementation resolution records completion of ADR-025; it does not replace the original design rationale or alternatives.

# 1. Context

EduCore already has:

```text
docs/api/openapi.yaml
```

using:

```text
OpenAPI 3.1.0
```

as the canonical Foundation API specification.

The current specification documents stable:

```text
Core
Auth
User
```

foundation operations.

Current documented Foundation operations include:

```text
authentication

authenticated bootstrap

Tenant capabilities

Workspace capabilities

role catalog

platform health

notifications

Tenant management

Membership discovery

Membership switching

Workspace discovery

Membership role assignment
```

The repository currently has **15 explicitly hardened Foundation operations** represented in OpenAPI contract tests.

Academic and HR routes are currently recorded under:

```text
x-educore-deferred-routes
```

because their canonical domain API/error contracts are not yet hardened.

Therefore:

```text
Laravel route exists
≠
canonical frontend contract exists
```

---

# 2. Current Canonical Error Contract

The backend already defines:

```text
ApiError {
    status
    code
    message
}
```

with:

```text
status = "error"
```

and stable uppercase machine codes.

Validation additionally provides:

```text
errors: {
    field: [
        message,
        ...
    ]
}
```

Current canonical examples include:

```text
VALIDATION_FAILED

AUTHENTICATION_FAILED

AUTHENTICATION_REQUIRED

AUTHENTICATION_CONTEXT_DENIED

AUTHORIZATION_DENIED

LOGOUT_UNAVAILABLE

INVALID_ORGANIZATIONAL_ASSIGNMENT_ID

ORGANIZATIONAL_CONTEXT_REQUIRED

ORGANIZATIONAL_CONTEXT_DENIED

ORGANIZATIONAL_CONTEXT_RESOLUTION_FAILED

RESOURCE_NOT_FOUND

MEMBERSHIP_SWITCH_DENIED

MEMBERSHIP_ROLE_ASSIGNMENT_REJECTED

NOTIFICATION_DISPATCH_FAILED

INTERNAL_SERVER_ERROR
```

This contract is already covered by backend/OpenAPI tests and becomes the frontend's canonical error vocabulary.

---

# 3. Problem Introduced by ADR-022

Current OpenAPI security scheme is:

```text
BearerAuth
```

and currently documents:

```text
Authorization: Bearer <token>
```

for protected operations.

ADR-022 explicitly forbids the React application from possessing that token.

Therefore the current OpenAPI document cannot be consumed unchanged as the browser authentication contract.

We need to add:

```text
BrowserSessionAuth
```

without replacing:

```text
BearerAuth
```

because bearer authentication remains valid for:

```text
mobile clients

API consumers

integration tooling

other trusted clients
```

---

# 4. Decision Drivers

ADR-025 must guarantee:

```text
1. One canonical API contract source.

2. No manually duplicated DTO architecture.

3. Bearer credentials never reach React.

4. Membership context remains tab-local.

5. Workspace locator injection is centralized.

6. Business modules cannot accidentally bypass context rules.

7. Canonical machine codes drive recovery.

8. Network errors remain distinct from API errors.

9. Contract drift is detectable in CI.

10. Generated code cannot become application architecture.

11. Deferred APIs cannot silently become canonical.

12. Backend authorization remains final authority.

13. Browser and non-browser clients can coexist.

14. Context-sensitive operations are explicit.

15. Request cancellation remains supported.
```

---

# 5. Alternatives Considered

## Option A — Handwritten API Services and DTOs

Example:

```text
studentService.ts

membershipService.ts

workspaceService.ts

authService.ts
```

with manually recreated interfaces.

### Problems

This duplicates:

```text
request DTOs

response DTOs

error definitions

endpoint paths

HTTP methods
```

already represented in OpenAPI.

Over time:

```text
backend contract
≠
frontend interfaces
```

becomes likely.

### Decision

```text
REJECTED
```

---

# 6. Option B — Generated Client Used Directly Everywhere

Example:

```text
modules/dormitory
   ↓
generatedApi.someOperation()
```

### Advantages

- Maximum code generation.
- Minimal handwritten API wrappers.

### Problems

Generated clients understand:

```text
HTTP contract
```

but should not own EduCore runtime semantics such as:

```text
browser-session transport

Membership selection

Workspace selection

context fencing

error recovery

observability

security rules
```

It would also allow business modules to bypass architectural policy.

### Decision

```text
REJECTED
```

Generated code is infrastructure, not the application's public API architecture.

---

# 7. Option C — Generated Contract Behind Platform Adapter

Selected:

```text
Business Module
      ↓
module API adapter/query
      ↓
platform/api
      ↓
generated OpenAPI client
      ↓
browser transport
      ↓
Laravel
```

### Decision

```text
SELECTED
```

---

# 8. Single Canonical OpenAPI Source

Canonical source:

```text
docs/api/openapi.yaml
```

Frontend MUST NOT maintain a second independently authored:

```text
frontend-openapi.yaml
```

or:

```text
browser-api.yaml
```

that duplicates request/response schemas.

Any browser-specific contract belongs in the same canonical specification.

---

# 9. OpenAPI Evolution Required

Before browser authentication implementation, the specification must be extended to document:

```text
BrowserSessionAuth

Browser Membership locator

browser-safe login operation

browser-safe logout operation

browser-safe Membership switch operation
```

The current bearer-based operations remain documented.

This is an additive API contract change.

---

# 10. Dual Authentication Transport

Normal protected resource operations may be reachable through either:

```text
BearerAuth
```

for supported API clients, or:

```text
BrowserSessionAuth
```

for the first-party SPA.

Conceptually:

```text
                ┌── BearerAuth ────────── API Client
/api/v1/*  ←────┤
                └── BrowserSessionAuth ── React SPA
```

Both converge on the same canonical:

```text
controller
application service
authorization
response schema
```

where practical.

---

# 11. No Mirrored BFF Business API

Rejected:

```text
/api/v1/students

/bff/api/v1/students
```

containing duplicate business route trees.

That would create:

```text
two HTTP contracts
two documentation surfaces
two drift surfaces
```

for the same resource.

Instead:

```text
/api/v1
```

remains the canonical resource surface.

Browser authentication mediation occurs before canonical request processing.

---

# 12. Browser Authentication Control Plane Is Different

Credential-returning operations are an exception.

Current:

```text
POST /api/v1/auth/login-token
```

returns:

```text
access_token
```

and current Membership switch also returns a bearer.

The SPA MUST NOT call those contracts directly.

Therefore dedicated browser-safe operations are required for:

```text
browser login

browser logout

browser Membership switch
```

Their responses must exclude:

```text
access_token

raw bearer token

session secret
```

Exact Laravel route naming is finalized by the required backend Browser Authentication TDD, but the separation itself is architectural and locked here.

---

# 13. Browser Session Security Scheme

OpenAPI must introduce a cookie security scheme representing:

```text
BrowserSessionAuth
```

Conceptually:

```yaml
type: apiKey
in: cookie
name: <browser-session-cookie>
```

The actual cookie name is deferred to ADR-030/backend TDD.

Frontend generated/browser application code MUST NOT attempt to read this cookie.

The browser sends it according to cookie policy.

---

# 14. Canonical Browser Membership Locator

Browser-protected requests use:

```text
X-EduCore-Membership-Id
```

to identify the tab's intended Membership.

This is necessary because:

```text
HttpOnly browser session cookie
```

is shared between tabs while active Membership is tab-local.

---

# 15. Membership Header Semantics

```text
X-EduCore-Membership-Id
```

is:

```text
UUID locator

untrusted client input

tab-local context selector
```

It is NOT:

```text
authentication credential

proof of Membership ownership

Tenant authority

authorization claim
```

The BFF/session broker resolves it only against authenticated server-side browser-session credentials.

---

# 16. Membership Header Cannot Create Context Implicitly

If:

```text
X-EduCore-Membership-Id = B
```

does not correspond to a legitimate established browser-session Membership credential:

```text
ordinary API request
→ fail closed
```

The BFF must not automatically perform a Membership switch.

Context mutation remains an explicit operation under ADR-023.

---

# 17. Existing Organizational Locator

The existing canonical:

```text
X-EduCore-Organizational-Assignment-Id
```

remains unchanged.

Its OpenAPI description already correctly establishes:

```text
locator only
≠ authority
```

Frontend architecture preserves that semantics.

---

# 18. Header Matrix

## Public operation

```text
BrowserSession cookie
not required

Membership locator
not required

Organizational locator
not required
```

## Authenticated Tenant-scoped browser operation

```text
BrowserSession cookie
required

X-EduCore-Membership-Id
required

X-EduCore-Organizational-Assignment-Id
absent
```

## Authenticated Workspace-scoped browser operation

```text
BrowserSession cookie
required

X-EduCore-Membership-Id
required

X-EduCore-Organizational-Assignment-Id
required
```

---

# 19. Context Metadata in OpenAPI

Operations should expose explicit EduCore context metadata.

Recommended canonical vendor extension:

```text
x-educore-context
```

with values conceptually equivalent to:

```text
public

authenticated

tenant

workspace
```

Example:

```yaml
x-educore-context: workspace
```

This allows frontend tooling and architecture tests to know that the operation requires organizational context without inferring it from URL names.

---

# 20. Context Metadata Is Not Authorization

```text
x-educore-context: workspace
```

means:

```text
request requires organizational context
```

not:

```text
request is authorized
```

Authorization still occurs in the backend.

---

# 21. Generated Code Ownership

Generated artifacts belong under:

```text
frontend/src/platform/api/generated/
```

or an equivalent internal platform location.

Generated code is:

```text
machine-owned
```

and MUST NOT be manually edited.

---

# 22. Generated Artifacts Are Committed

Generated TypeScript artifacts will be committed to the repository.

Reason:

```text
contract change
↓
generated diff
↓
code review
```

becomes visible.

This also allows CI to prove codegen drift.

---

# 23. CI Contract Drift Gate

CI must conceptually execute:

```text
validate OpenAPI
↓
generate TypeScript client/contracts
↓
compare generated result
with repository
```

If regeneration changes committed files:

```text
CI FAIL
```

Developer must commit the corresponding generated contract change.

---

# 24. No Manual DTO Duplication

Forbidden unless explicitly justified:

```ts
interface Membership {
    ...
}
```

if the same wire representation already exists as a canonical generated OpenAPI schema.

Business/domain view models may still exist when they deliberately transform API wire data.

Therefore:

```text
API DTO
≠ automatically
UI model
```

but duplication without transformation is rejected.

---

# 25. Generated Contract Boundary

Business code should not become coupled to generator-specific internals.

Rejected:

```text
feature component
→ generator runtime implementation
```

Preferred:

```text
feature
↓
module API/query abstraction
↓
platform API contract
↓
generated implementation
```

This allows the generator to change without rewriting the application.

---

# 26. Generator Selection

ADR-025 does not lock a particular generator package.

Acceptable tools must provide:

```text
OpenAPI 3.1 compatibility

strict TypeScript output

stable operation typing

request/response typing

deterministic generation

AbortSignal/fetch compatibility

no requirement to expose bearer credentials
```

The exact generator is a TDD/implementation selection.

Changing generators later does not require a new ADR as long as the architectural contract remains unchanged.

---

# 27. Canonical API Transport

All EduCore first-party API traffic is centralized through:

```text
platform/api
```

Business modules must not scatter:

```ts
fetch("/api/v1/...");
```

through feature code.

This central boundary owns cross-cutting transport concerns.

---

# 28. Browser Transport Responsibilities

The handwritten browser transport owns:

```text
base URL

credentials mode

Membership locator injection

Workspace locator injection

CSRF integration

request correlation integration

response parsing

error normalization

request cancellation integration

safe observability
```

It does NOT own:

```text
business authorization decisions

Tenant ownership decisions

Workspace authorization

role evaluation
```

---

# 29. Authorization Header Rule

React browser transport:

```text
MUST NOT
```

set:

```text
Authorization: Bearer ...
```

because the React application never possesses the bearer.

Only the BFF/server-side layer can attach the canonical bearer internally.

---

# 30. Direct API Clients Remain Supported

ADR-025 does not remove:

```text
BearerAuth
```

from OpenAPI.

Non-browser clients may continue using:

```text
Authorization: Bearer
```

according to canonical backend contracts.

Browser and non-browser authentication transports coexist intentionally.

---

# 31. Context Injection Is Centralized

Business module code should conceptually request:

```text
execute workspace-scoped operation
```

rather than manually knowing all headers.

The API boundary obtains authoritative active context from:

```text
platform/tenancy

platform/workspace
```

and constructs the request metadata.

---

# 32. No Hidden Context Guessing

API transport MUST NOT determine Workspace scope by examining:

```text
URL substring

module name

HTTP method

route pathname
```

Context requirements should be explicit through:

```text
generated operation metadata

OpenAPI extension

or typed operation adapter
```

---

# 33. Context Snapshot

When a context-sensitive request starts, the API layer captures the relevant context identity.

Conceptually:

```text
membershipId

tenantId

workspaceIdentity

contextGeneration
```

This snapshot participates in the response-fencing architecture established by ADR-023/024.

---

# 34. Cancellation Support

API operations must support browser cancellation via:

```text
AbortSignal
```

or equivalent fetch-compatible semantics.

This allows:

```text
route abandonment

context switch

superseded reads
```

to cancel unnecessary network work where possible.

Cancellation remains an optimization.

Context generation remains the correctness boundary.

---

# 35. No Automatic Retry in Transport

The base HTTP transport performs:

```text
NO automatic retry
```

for either reads or mutations.

Why?

Retry policy depends on:

```text
operation semantics

idempotency

server-state layer policy
```

Bounded read retry belongs to ADR-026/TanStack Query configuration.

Mutation retry remains disabled by default.

---

# 36. Canonical Success Representations

Generated client types preserve the actual HTTP contract.

Many EduCore endpoints use:

```text
{
    status: "success",
    data: ...
}
```

but the health endpoint intentionally uses:

```text
HealthStatus
```

directly.

Therefore the API transport MUST NOT apply a global magical:

```text
response.data.data
```

unwrapper to every operation.

Operation/module adapters may explicitly transform success representations.

---

# 37. Canonical Error Envelope

For backend application errors, frontend expects:

```text
status = "error"

code = stable machine code

message = human-readable detail
```

and optionally:

```text
errors
```

for field validation.

The machine code is the decision input.

The human message is presentation/fallback information.

---

# 38. Frontend Normalized Failure Model

The API boundary normalizes failures into conceptual categories such as:

```text
ApiFailure

NetworkFailure

ContractFailure

CancelledRequest
```

A canonical API failure retains at minimum:

```text
httpStatus

code

message

fieldErrors?
```

and safe correlation metadata when available.

---

# 39. API Failure

Example:

```text
HTTP 403

{
  "status": "error",
  "code": "AUTHORIZATION_DENIED",
  "message": "..."
}
```

becomes a typed API failure.

It does not become a generic JavaScript:

```text
Error("403")
```

with lost machine semantics.

---

# 40. Network Failure

A network failure means:

```text
no authoritative HTTP application response
```

Examples:

```text
offline

DNS failure

connection failure

request transport interruption
```

Network failure:

```text
≠ authentication failure
```

and cannot cause automatic logout.

---

# 41. Contract Failure

Examples:

```text
malformed JSON

expected success representation missing

invalid canonical error envelope

critical bootstrap response incompatible with contract

impossible context mismatch
```

are treated as:

```text
ContractFailure
```

They:

```text
fail safely
+
produce observability
```

rather than silently guessing.

---

# 42. Cancelled Request

An intentionally aborted request:

```text
route changed

context changed

request superseded
```

is not a user-facing API error.

Cancellation should generally remain silent unless the operation requires explicit UI handling.

---

# 43. Error Classification

Canonical frontend recovery categories remain:

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

Classification derives from:

```text
HTTP status
+
machine code
+
operation
```

not message text.

---

# 44. Authentication Classification

Examples:

```text
AUTHENTICATION_REQUIRED

AUTHENTICATION_CONTEXT_DENIED
```

may invalidate or re-bootstrap session context according to canonical authentication policy.

However:

```text
HTTP 401 alone
```

must not be hardcoded as:

```text
logout immediately
```

without canonical error semantics.

---

# 45. Login Authentication Failure

```text
AUTHENTICATION_FAILED
```

during login means:

```text
login rejected
```

not:

```text
invalidate an already authenticated application session
```

Operation semantics matter.

---

# 46. Authorization Classification

```text
AUTHORIZATION_DENIED
```

means:

```text
operation denied
```

Frontend:

```text
does not logout
↓
refresh capability projection when appropriate
↓
re-evaluate route/action
```

Backend denial remains final.

---

# 47. Organizational Context Classification

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

on an active Workspace invokes ADR-024 stale-context recovery.

```text
ORGANIZATIONAL_CONTEXT_REQUIRED
```

indicates that the operation needs organizational context but current context does not provide one.

These are not equivalent.

---

# 48. Invalid Organizational Locator

```text
INVALID_ORGANIZATIONAL_ASSIGNMENT_ID
```

from application-generated context normally indicates:

```text
stale/corrupt client context

contract bug

or tampering
```

Frontend discards the invalid Workspace state and recovers safely.

---

# 49. Validation Classification

```text
VALIDATION_FAILED
```

provides:

```text
errors: Record<string, string[]>
```

Known form fields:

```text
→ inline errors
```

Unknown fields:

```text
→ form-level validation summary
```

Unknown backend validation keys must never crash the form.

---

# 50. Human Message Is Never a Branching API

Forbidden:

```ts
if (error.message.includes('membership')) {
    ...
}
```

or:

```ts
if (message === 'Unauthenticated.') {
    ...
}
```

Backend wording may change without changing semantics.

Only stable machine codes may drive canonical recovery.

---

# 51. Unknown Machine Code

If an endpoint returns a machine code not represented by the current generated/known contract:

```text
retain HTTP status

retain raw safe code

classify as CONTRACT/UNKNOWN

fail closed where security-sensitive

report contract drift
```

Frontend must not invent meaning from the message.

---

# 52. Status Code Alone Still Has Value

HTTP status remains part of the contract.

Examples:

```text
404
422
429
500
503
```

provide protocol-level semantics.

But status does not replace stable domain/application codes where those codes exist.

---

# 53. Critical Runtime Validation

TypeScript protects compile-time code, not network runtime.

Critical platform boundaries SHOULD receive runtime contract validation.

High-priority examples:

```text
browser session bootstrap

/auth/me

/my-memberships

/my-workspaces

Tenant capabilities

Workspace capabilities
```

because malformed responses there could corrupt global security context.

---

# 54. Universal Runtime Validation Is Not Required

EduCore does not require runtime schema validation for every response by default.

Reason:

```text
bundle impact

runtime cost

complexity
```

should be proportional to risk.

The selected policy is:

```text
selective validation
at critical trust boundaries
```

plus normal canonical error/contract checks elsewhere.

Exact validation library is deferred to implementation.

---

# 55. OpenAPI Deferred Routes

Current OpenAPI explicitly records Academic/HR routes under:

```text
x-educore-deferred-routes
```

These routes are:

```text
known public Laravel routes
```

but are not yet:

```text
canonical hardened frontend contracts
```

Frontend MUST NOT treat deferred route metadata as equivalent to a generated operation contract.

---

# 56. Business Module Gate for Deferred APIs

Before a deferred business route becomes the basis of production frontend implementation:

```text
domain API hardening
↓
canonical validation/error contract
↓
OpenAPI operation
↓
contract tests
↓
generated client
↓
frontend module integration
```

This prevents frontend development from freezing accidental backend behavior.

---

# 57. OpenAPI Is Version-Controlled Architecture

Breaking API changes require:

```text
requirement/change
↓
backend contract update
↓
OpenAPI update
↓
contract tests
↓
client regeneration
↓
frontend adaptation
```

Frontend and backend deployments must not assume perfectly simultaneous release.

---

# 58. No Silent Breaking Change

Examples requiring explicit migration:

```text
rename response field

change field type

remove error code

change required context

change status code semantics

change authentication transport

remove operation
```

They must not be introduced only in PHP code while OpenAPI remains unchanged.

---

# 59. Generated Artifact Review

When OpenAPI changes:

```text
openapi.yaml diff
+
generated TypeScript diff
```

should appear in the same review where practical.

This allows reviewers to see the frontend contract consequence immediately.

---

# 60. Module API Ownership

Generated transport lives in:

```text
platform/api/generated
```

but business operation ownership remains module-local.

Example:

```text
modules/dormitory/api/
```

may compose generated Dormitory operations into domain-facing query functions.

It may not create another HTTP stack.

---

# 61. Platform API Public Boundary

Conceptually:

```text
platform/api/
├── generated/
├── transport/
├── errors/
├── context/
├── validation/
└── public.ts
```

Exact filenames remain TDD concerns.

Generated internals are not imported freely throughout the application.

---

# 62. Error Recovery Ownership

The base API client:

```text
normalizes
```

errors.

It does not globally execute every recovery.

Example:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

is normalized by API infrastructure, then Workspace platform orchestration performs ADR-024 recovery.

Likewise:

```text
AUTHORIZATION_DENIED
```

does not cause a generic API interceptor to navigate blindly.

---

# 63. No Giant Axios-Interceptor Equivalent

Rejected architecture:

```text
one interceptor
↓
401 → logout
403 → dashboard
422 → toast
500 → reload
```

This loses operation/context semantics.

Centralization belongs at:

```text
normalization
context injection
security
observability
```

while recovery is delegated to the owning platform/domain layer.

---

# 64. Server-State Layer Boundary

ADR-025 handles:

```text
HTTP transport
contract
normalization
```

ADR-026 will handle:

```text
TanStack Query

cache ownership

query keys

stale/fresh behavior

deduplication

read retries

mutation lifecycle
```

This keeps transport separate from server-state orchestration.

---

# 65. Observability

The API layer may capture safe metadata:

```text
operationId

HTTP status

machine code

duration

request/correlation identifier

frontend release

context generation
```

It MUST NOT capture:

```text
Authorization header

browser session cookie

password

raw sensitive request payload

raw sensitive response payload
```

---

# 66. Correlation Headers

If backend exposes safe:

```text
request_id
trace_id
correlation_id
```

headers or response metadata, API infrastructure should preserve them in normalized diagnostic context.

The exact canonical backend correlation header may be standardized later without changing this architecture.

---

# 67. CSRF Integration

Because BrowserSessionAuth is cookie-based:

```text
platform/api
```

is the single frontend integration point for the CSRF mechanism selected by ADR-030.

Business modules must not implement independent anti-CSRF logic.

Exact header/token mechanism remains deferred.

---

# 68. CORS / Credentials

Browser transport must use the credential policy required by the selected deployment topology.

If frontend and API use different origins:

```text
credentials
+
strict CORS allowlist
```

must be configured appropriately.

Wildcard credentialed CORS is forbidden.

Exact deployment headers are ADR-030/infrastructure concerns.

---

# 69. API Base URL

API endpoint location is environment configuration.

Example conceptual environments:

```text
development

CI

staging

production
```

Business modules must not hardcode production API hosts.

---

# 70. API Client Security Invariants

```text
Bearer in React
= FORBIDDEN

Authorization header from React
= FORBIDDEN

manual Membership header in feature code
= FORBIDDEN

manual Workspace header in feature code
= FORBIDDEN

manual CSRF handling in feature code
= FORBIDDEN

human message branching
= FORBIDDEN

duplicate handwritten wire DTO
= FORBIDDEN by default

raw fetch to EduCore APIs from business features
= FORBIDDEN by default
```

---

# 71. Required OpenAPI Additions

Backend Browser Authentication workstream must extend OpenAPI with:

```text
1. BrowserSessionAuth security scheme.

2. X-EduCore-Membership-Id parameter.

3. Browser-safe login operation.

4. Browser-safe logout operation.

5. Browser-safe Membership switch operation.

6. Safe browser response schemas with no access_token.

7. Browser authentication error contracts.

8. Context metadata for browser-relevant operations.

9. Existing BearerAuth retained.

10. Existing organizational locator retained.
```

---

# 72. Safe Browser Login Response

A browser login response may return safe contextual information such as:

```text
membership_id

tenant_id

tenant_name

session expiry metadata if intentionally public
```

but MUST NOT return:

```text
access_token

server session ID

cookie secret

internal bearer metadata
```

The authoritative application identity still comes from:

```text
/auth/me
```

---

# 73. Safe Browser Membership Switch Response

Browser switch may return:

```text
target membership/tenant context
```

for transition feedback.

However ADR-023 remains authoritative:

```text
switch response
≠ final frontend commit
```

Frontend still verifies target context using:

```text
/auth/me
```

before committing.

---

# 74. Browser Logout Contract

Browser logout is semantically different from:

```text
revoke only current bearer
```

It destroys the Browser Session Broker session and its held credentials.

Therefore its browser-specific contract must not silently overload the existing bearer logout semantics.

---

# 75. Test Requirements

Implementation must prove:

```text
1. OpenAPI remains canonical source.

2. generated client regeneration is deterministic.

3. CI detects generated-client drift.

4. generated code is not manually edited.

5. React never sends Authorization bearer.

6. BrowserSessionAuth uses hardened cookie transport.

7. X-EduCore-Membership-Id is injected centrally.

8. forged Membership locator cannot authenticate.

9. Workspace locator is injected only for Workspace-scoped operations.

10. Tenant-scoped operations omit organizational header.

11. business modules do not manually inject context headers.

12. business modules do not manually parse canonical errors.

13. VALIDATION_FAILED preserves field errors.

14. unknown validation fields do not crash forms.

15. AUTHORIZATION_DENIED does not trigger logout.

16. ORGANIZATIONAL_CONTEXT_DENIED reaches Workspace recovery.

17. network failure does not become authentication failure.

18. cancelled requests do not surface as user errors.

19. malformed critical bootstrap response becomes ContractFailure.

20. human-readable message changes do not alter recovery behavior.

21. browser login response never contains access_token.

22. browser Membership switch response never contains access_token.

23. direct BearerAuth contract remains supported.

24. deferred OpenAPI routes are not generated as canonical operations.

25. critical platform operations support runtime validation.
```

---

# 76. Contract Tests

Backend/OpenAPI tests should additionally lock:

```text
BrowserSessionAuth existence

Membership locator component

security alternatives

browser-safe response schemas

absence of access_token from browser schemas

x-educore-context metadata

stable machine error codes
```

Route/OpenAPI inventory should fail if browser/public contract drifts.

---

# 77. Frontend Architecture Tests

Frontend architecture tests/lint rules should reject:

```text
Authorization header construction

local bearer token types/storage

raw EduCore fetch calls outside platform API

cross-module generated-client access where prohibited

manual organizational header injection

manual Membership header injection
```

where practical.

---

# 78. Architectural Invariants

If ADR-025 is accepted:

```text
Canonical HTTP contract
= docs/api/openapi.yaml

OpenAPI version
= 3.1 baseline

Frontend wire types
= generated

Generated code
= committed + machine-owned

Generated code direct business usage
= FORBIDDEN

Frontend HTTP boundary
= platform/api

Browser bearer token
= NEVER

React Authorization header
= NEVER

Browser authenticated transport
= BrowserSessionAuth

Browser Membership locator
= X-EduCore-Membership-Id

Membership locator authority
= NONE

Workspace locator
= X-EduCore-Organizational-Assignment-Id

Workspace locator authority
= NONE

Canonical resource surface
= /api/v1

Mirrored /bff business API
= REJECTED

Browser login/switch/logout
= dedicated safe contracts

Direct BearerAuth
= PRESERVED

Canonical error branching
= status + code + operation semantics

message substring branching
= FORBIDDEN

Network failure
≠ authentication failure

Contract failure
= fail safe + observable

base transport automatic retry
= OFF

request cancellation
= supported

critical context runtime validation
= REQUIRED direction

deferred OpenAPI routes
≠ canonical frontend contracts
```

---

# 79. Consequences

## Positive

- Backend and frontend share one contract source.
- Browser credential architecture remains compatible with canonical APIs.
- No bearer token enters frontend generated code.
- Membership/Workspace propagation becomes centralized.
- Business modules cannot easily bypass tenancy rules.
- Error recovery remains deterministic.
- Type drift becomes visible in CI.
- Backend direct API clients remain supported.
- Deferred business APIs remain visibly non-canonical.
- Generator vendor lock-in is minimized.

## Costs

- OpenAPI requires an additive browser-authentication update.
- Generated artifacts add repository diffs.
- A handwritten transport adapter is still required.
- Browser and bearer authentication variants require contract testing.
- Critical runtime validation adds some implementation complexity.
- Business modules need small adapters instead of invoking generated code everywhere.

These costs are accepted because the API boundary is a security and maintainability boundary, not merely an HTTP convenience layer.

---

# 80. Explicit Non-Decisions

ADR-025 does not decide:

```text
exact OpenAPI generator package

exact runtime validation package

exact BrowserSession cookie name

exact browser-auth route URLs

exact CSRF mechanism/header

exact CORS production hostnames

TanStack Query configuration

query keys

cache staleTime/gcTime

retry count for safe reads

mutation orchestration

observability vendor
```

Those are resolved by later ADR/backend TDD/implementation where appropriate.

---

# 81. Follow-Up Dependency

The next architecture question is now:

```text
Who owns server state,
how is it cached,
and how are Tenant/Workspace boundaries
encoded in cache identity?
```

Therefore the next ADR is:

```text
ADR-026
Server-State & Client-State Ownership
```

It will consume:

```text
ADR-022 browser session

ADR-023 Membership/Tenant context

ADR-024 Workspace context

ADR-025 canonical API transport
```

and define the TanStack Query/state boundaries without creating a giant global store.

---

# ADR-025 Proposed State

```text
ADR-025 — API Client, OpenAPI
& Canonical Error Handling

Status:
🔒 ACCEPTED / LOCKED

Canonical contract:
docs/api/openapi.yaml

Frontend contracts:
GENERATED

Generated artifacts:
COMMITTED / MACHINE-OWNED

HTTP boundary:
platform/api

Generated client used directly everywhere:
❌ REJECTED

Raw EduCore fetch from business features:
❌ REJECTED

Browser bearer:
❌ FORBIDDEN

Browser auth:
BrowserSessionAuth

Browser Membership locator:
X-EduCore-Membership-Id

Organizational locator:
X-EduCore-Organizational-Assignment-Id

Mirrored BFF business API:
❌ REJECTED

/api/v1 canonical resource surface:
✅ PRESERVED

Browser credential-changing operations:
DEDICATED SAFE CONTRACTS

BearerAuth:
✅ RETAINED for supported non-browser clients

Error branching:
HTTP status
+
machine code
+
operation semantics

Message matching:
❌ FORBIDDEN

Automatic transport retry:
❌ OFF

Critical runtime validation:
✅ REQUIRED DIRECTION

Deferred OpenAPI routes:
❌ NOT YET CANONICAL FRONTEND CONTRACTS
```
