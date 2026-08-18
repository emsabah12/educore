# ADR-026 — Server-State & Client-State Ownership

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — State Ownership, TanStack Query, Cache Isolation, Mutation & Persistence Policy

---

> ## Decision Summary
>
> EduCore Frontend will use a **hybrid state-ownership architecture**.
>
> State is classified by responsibility before a storage/state-management mechanism is selected:
>
> ```text
> Server State
> → TanStack Query
>
> Authentication Lifecycle State
> → platform session/auth runtime state
>
> Membership/Tenant Selection
> → platform tenancy runtime state
>
> Workspace Selection
> → platform workspace runtime state
>
> Capability Projection
> → TanStack Query server state
>   consumed by platform authorization
>
> Form State
> → form-local state / dedicated form abstraction
>
> Transient UI State
> → local React state
>
> Shareable Navigation State
> → URL/search parameters where appropriate
>
> Non-secret Reload Hints
> → controlled sessionStorage adapter
> ```
>
> EduCore will **not introduce Redux, Zustand, or another general-purpose global state store as a Foundation requirement**.
>
> TanStack Query also MUST NOT become a generic global store.
>
> The frontend will use **one QueryClient per running SPA/tab**, and authenticated server-state cache will **not be persisted to localStorage, sessionStorage, IndexedDB, or Cache Storage in Foundation v1**.
>
> Query identity is context-safe:
>
> ```text
> operation/resource
> +
> authenticated session generation
> +
> Membership/Tenant when applicable
> +
> Workspace when applicable
> +
> normalized request parameters
> ```
>
> Context-sensitive data from different Tenant or Workspace contexts can never share the same logical query identity.
>
> Context switches use:
>
> ```text
> cancellation where possible
> +
> context-aware query identity
> +
> context-generation response fencing
> ```
>
> Cancellation alone is never treated as the correctness boundary.
>
> Server mutations are pessimistic by default:
>
> ```text
> automatic mutation retry
> = OFF
>
> optimistic mutation
> = opt-in only
> ```
>
> Logout, authenticated identity invalidation, or replacement with a different authenticated identity clears protected server-state cache before another protected context becomes interactive.

## Related ADR

- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation
- ADR-023 — Tenant / Membership Context Switching
- ADR-024 — Workspace / Organizational Context Management
- ADR-025 — API Client, OpenAPI & Canonical Error Handling

---

# 1. Context

EduCore Frontend has several categories of state with fundamentally different semantics.

Examples:

```text
/auth/me

/my-memberships

/my-workspaces

capabilities

students

employees

rooms
```

are representations of backend state.

But:

```text
AUTHENTICATING

SWITCHING Tenant

selected Workspace

dialog open

form dirty

sidebar collapsed
```

are frontend runtime state.

Treating all of those identically would create ambiguous ownership.

The repository currently has no React state-management or TanStack Query implementation. Therefore this ADR establishes ownership before those dependencies and abstractions are introduced.

---

# 2. Primary Principle

The canonical rule is:

```text
State mechanism follows state ownership.
```

Not:

```text
We have a store,
therefore everything goes into the store.
```

Frontend must first ask:

```text
Who is authoritative for this value?
```

then:

```text
How should this value be represented?
```

---

# 3. State Categories

EduCore recognizes these primary categories:

```text
1. Server State

2. Authentication Lifecycle State

3. Runtime Context State

4. Capability Projection State

5. Form State

6. Transient UI State

7. URL / Navigation State

8. Restoration Hint State
```

They MUST NOT be collapsed into one undifferentiated application store.

---

# 4. Alternatives Considered

## Option A — One Global Application Store

Example:

```text
globalStore = {
    user,
    auth,
    memberships,
    tenant,
    workspace,
    permissions,
    students,
    employees,
    rooms,
    forms,
    sidebar,
    ...
}
```

### Advantages

- One place to inspect state.
- Familiar global-state mental model.
- Simple access from arbitrary components.

### Problems

It combines:

```text
backend-owned state

runtime context

UI state

forms

security-sensitive lifecycle
```

into one authority.

It also encourages:

```text
manual server-cache implementation

cross-Tenant state reuse

large invalidation logic

hidden coupling

unnecessary global rerenders
```

### Decision

```text
REJECTED
```

---

# 5. Option B — TanStack Query for Everything

Example:

```text
Query Cache
→ server responses
→ selected Workspace
→ sidebar state
→ form state
→ modal state
```

### Problem

TanStack Query is designed around asynchronous/server state.

User selections and transient UI state have different lifecycle semantics.

Using Query cache for them would make:

```text
cache
```

become:

```text
application event store
```

without a meaningful reason.

### Decision

```text
REJECTED
```

---

# 6. Option C — General Global Client Store + Query Cache

Example:

```text
TanStack Query
→ server state

Redux/Zustand
→ everything else
```

This can be valid for applications with demonstrated complex cross-tree client-state requirements.

However EduCore Foundation does not currently have such a demonstrated requirement.

Introducing another state framework now would create an abstraction before its necessity is proven.

### Decision

```text
NOT SELECTED
for Foundation v1
```

A future requirement may justify one through architecture review.

---

# 7. Option D — Responsibility-Based Hybrid

Selected:

```text
TanStack Query
+
small explicit platform runtime contexts
+
local React state
+
URL state
+
controlled restoration adapter
```

### Decision

```text
SELECTED
```

---

# 8. Server State Definition

Server state is information whose canonical authority exists outside the React application.

Examples:

```text
/auth/me

/my-memberships

/my-workspaces

capabilities

workspace capabilities

roles

permissions

students

employees

dormitory rooms

notifications
```

Canonical source:

```text
Laravel /api/v1
```

Frontend copy:

```text
cache / projection
```

not authority.

---

# 9. Server-State Owner

Canonical frontend server-state infrastructure:

```text
TanStack Query
```

Responsibilities:

```text
fetch lifecycle

request deduplication

cache

stale/fresh state

background refetch

bounded read retry policy

mutation lifecycle integration

cache invalidation

request cancellation integration
```

TanStack Query does not replace the canonical API transport defined in ADR-025.

---

# 10. Layering

Canonical direction:

```text
Component / Feature
        ↓
Module query/mutation abstraction
        ↓
TanStack Query
        ↓
platform/api
        ↓
generated OpenAPI contract
        ↓
Browser transport / BFF
        ↓
Laravel API
```

TanStack Query must not implement its own HTTP stack.

---

# 11. One QueryClient Per SPA Runtime

Each running tab/application root owns one:

```text
QueryClient
```

Conceptually:

```text
Tab A
→ QueryClient A

Tab B
→ QueryClient B
```

No browser-global shared QueryClient exists.

This naturally reinforces the multi-tab isolation established by ADR-022–024.

---

# 12. Query Cache Is Not Persisted

Foundation v1 explicitly rejects persistent authenticated query cache.

Protected server data MUST NOT be persisted through:

```text
localStorage

sessionStorage

IndexedDB

Cache Storage

generic query-persist plugins
```

This includes:

```text
/auth/me

memberships

workspaces

capabilities

student records

employee records

dormitory data

future financial data
```

---

# 13. Why Query Persistence Is Rejected

Persistent server-state cache introduces significant risks:

```text
stale authorization

stale Tenant context

cross-login data remnants

sensitive data persistence

complex schema/version migration

logout cleanup failures
```

EduCore has no Foundation requirement for offline access.

Therefore:

```text
memory-only Query cache
```

is the safer default.

---

# 14. Browser Reload Consequence

Reload causes TanStack Query cache to disappear.

This is intentional.

Reload persistence comes from:

```text
HttpOnly Browser Session
+
non-secret Membership hint
+
non-secret Workspace hint
```

followed by authoritative bootstrap:

```text
/auth/me
↓
/my-memberships
↓
/my-workspaces
↓
capabilities
```

not from persistence of previous protected API responses.

---

# 15. Authentication Lifecycle State

Authentication lifecycle is frontend runtime state.

Examples:

```text
UNAUTHENTICATED

AUTHENTICATING

BOOTSTRAPPING

AUTHENTICATED

LOGGING_OUT

EXPIRED
```

Owner:

```text
platform/auth
+
platform/session
```

This state is not ordinary Query data.

---

# 16. `/auth/me` Is Still Server State

Important distinction:

```text
AUTHENTICATED
```

is lifecycle state.

But:

```text
/auth/me response
```

is canonical server state.

Therefore:

```text
session lifecycle
→ runtime state

authenticated identity projection
→ TanStack Query
```

The session layer orchestrates the query but does not duplicate the entire `/auth/me` payload into another global store.

---

# 17. Runtime Tenant Context

The selected:

```text
Membership
+
Tenant
```

is frontend runtime/context state.

Owner:

```text
platform/tenancy
```

It represents:

```text
which authoritative Membership/Tenant
this tab is currently operating under
```

It is not simply the cached Membership catalog.

---

# 18. Membership Catalog vs Selection

```text
GET /my-memberships
```

returns server state.

Therefore:

```text
membership catalog
→ TanStack Query
```

But:

```text
currently selected Membership
→ platform tenancy runtime state
```

This distinction prevents a fetched list from being mistaken for active application context.

---

# 19. Workspace Catalog vs Selection

Likewise:

```text
GET /my-workspaces
```

is:

```text
server state
→ TanStack Query
```

while:

```text
active Workspace
```

is:

```text
runtime context
→ platform/workspace
```

---

# 20. Capability Projection

Capabilities are authoritative server projections.

Therefore:

```text
Tenant capabilities
+
Workspace capabilities
→ TanStack Query
```

The frontend MUST NOT copy permission arrays into an unrelated global client-state store.

---

# 21. Authorization Platform Consumption

`platform/authorization` observes the capability query corresponding to the current authoritative context.

Conceptually:

```text
Current Context
      ↓
Capability Query Identity
      ↓
TanStack Query
      ↓
Authorization UX
```

There must not be:

```text
query permissions
↓
copy permissions to global store
↓
maintain two sources
```

---

# 22. Capability Readiness Remains Explicit

The query state must preserve:

```text
UNRESOLVED
LOADING
READY
STALE
ERROR
```

semantics established by the PRD.

Critically:

```text
permissions = []
```

means:

```text
READY
with zero effective permissions
```

not:

```text
ERROR
```

---

# 23. Form State

Form state belongs to the form.

Examples:

```text
field values

dirty state

client-side validation state

submission interaction state
```

It does not belong in TanStack Query.

It does not belong in application-global state by default.

---

# 24. Server Validation vs Form State

Server response:

```text
VALIDATION_FAILED
```

is an API/server-state result.

Its relevant field errors are then mapped into the form's local interaction state.

Canonical server validation remains authoritative.

---

# 25. Form Library

ADR-026 does not select a form library.

A later TDD may choose:

```text
React Hook Form
```

or another appropriate abstraction.

The ownership rule remains:

```text
form-specific state
= form-local
```

---

# 26. Transient UI State

Examples:

```text
dialog open

dropdown expanded

sidebar mobile drawer open

selected local tab

hover state

temporary disclosure
```

belong to:

```text
component/local React state
```

by default.

Do not globalize state simply because two nested components need access to it.

---

# 27. URL State

State that should survive navigation, be bookmarkable, or describe the current resource view should preferentially use the URL.

Examples:

```text
page number

search term

sort

safe filters

selected report tab
```

when appropriate.

This reduces unnecessary application-state duplication.

---

# 28. Sensitive Data Must Not Enter URL State

URL state MUST NOT contain:

```text
bearer credential

session secret

password

sensitive form payload

other secret material
```

Existing security invariants remain unchanged.

---

# 29. Restoration Hint State

Controlled `sessionStorage` may contain only explicitly approved non-secret restoration hints.

Examples:

```text
Membership ID hint

Workspace assignment hint
```

Owner:

```text
platform persistence/restoration adapter
```

rather than arbitrary feature code.

---

# 30. No Generic Browser Storage Service

Rejected:

```text
storage.set(key, anything)
```

used anywhere in the application.

Browser persistence should be explicit and typed.

Business modules MUST NOT persist arbitrary protected domain responses without a dedicated requirement and security review.

---

# 31. Query Identity Principle

Every Query must have an identity that expresses the scope of the server data.

Canonical principle:

```text
resource
+
security/runtime context
+
parameters
```

A generic:

```text
["students"]
```

is insufficient for context-sensitive data.

---

# 32. Query Scope Categories

Queries conceptually belong to one of:

```text
PUBLIC

SESSION

TENANT

WORKSPACE
```

Additional specialized scopes may be introduced when a demonstrated requirement exists.

---

# 33. Public Query Identity

Example:

```text
["public", resource, parameters]
```

contains no authenticated context.

Only genuinely public APIs may use this scope.

---

# 34. Session Query Identity

Authenticated but non-Tenant-specific platform data may conceptually use:

```text
[
  "session",
  sessionGeneration,
  resource,
  parameters
]
```

`sessionGeneration` is an internal non-secret runtime revision.

It is not:

```text
cookie value

session ID

bearer token
```

---

# 35. Tenant Query Identity

Canonical conceptual shape:

```text
[
  "tenant",
  sessionGeneration,
  membershipId,
  tenantId,
  resource,
  parameters
]
```

This guarantees:

```text
Tenant A data
≠
Tenant B data
```

even when both use the same resource type.

---

# 36. Workspace Query Identity

Canonical conceptual shape:

```text
[
  "workspace",
  sessionGeneration,
  membershipId,
  tenantId,
  workspaceIdentity,
  resource,
  parameters
]
```

where Workspace identity distinguishes:

```text
TENANT

Organization assignment X

OrganizationUnit assignment Y
```

as appropriate.

---

# 37. No Secrets in Query Keys

Forbidden:

```text
bearer token

browser cookie

CSRF secret

password
```

inside query keys.

Query keys may be exposed through debugging/devtools and are not secret storage.

---

# 38. Query Key Factories

Query keys must be constructed through owned factories/helpers.

Example ownership:

```text
modules/academic
→ academic query keys

modules/dormitory
→ dormitory query keys

platform/authorization
→ capability query keys
```

Do not scatter hand-built string arrays across components.

---

# 39. Query-Key Factory Benefit

Centralized factories make context omissions reviewable.

Bad:

```text
useQuery({
    queryKey: ["students"]
})
```

Correct conceptual ownership:

```text
studentKeys.list(context, filters)
```

where the factory encodes the appropriate Tenant/Workspace scope.

---

# 40. Context Scope Must Be Explicit

Not every endpoint is Workspace-scoped.

Therefore:

```text
workspaceIdentity
```

must not be mechanically added to every query.

Examples:

```text
Tenant settings
→ Tenant scope

Workspace student listing
→ Workspace scope
```

Scope follows the API/context contract from ADR-025.

---

# 41. Normalized Parameters

Equivalent logical requests must produce stable equivalent query identities.

Parameters therefore require deterministic representation.

Examples:

```text
pagination

sort

filter

search
```

must not generate random object-order cache fragmentation.

Exact serialization belongs to implementation.

---

# 42. Request Deduplication

Equivalent active reads should share the same Query identity.

TanStack Query may then deduplicate concurrent fetches.

This avoids:

```text
Header requests memberships

Page requests memberships

Switcher requests memberships
```

all generating independent identical network calls.

---

# 43. Context Generation Fencing

ADR-023 and ADR-024 require logical context generations.

Each context-sensitive request captures the generation in which it began.

A response may affect active rendering only if its scope remains relevant.

This is required even when the cache key already includes Tenant/Workspace identity.

---

# 44. Why Query Keys Alone Are Not Enough

Suppose:

```text
Workspace X query
```

is correctly cached under X.

After switching to Y, the response cannot overwrite Y's cache key.

That protects cache identity.

But feature code could still execute stale callbacks or local side effects from the old operation.

Therefore:

```text
query partitioning
+
context-generation fencing
```

remain complementary controls.

---

# 45. Cancellation

On:

```text
route abandonment

Tenant switch

Workspace switch

superseded search
```

the frontend should cancel obsolete reads when supported.

TanStack Query's cancellation integration should use the `AbortSignal` support required by ADR-025.

---

# 46. Cancellation Is Optimization

Cancellation is not the authority for context isolation.

A request may:

```text
already reach the server

complete despite local lifecycle change
```

Correctness therefore remains:

```text
context-safe identity
+
response fencing
```

---

# 47. Tenant Switch — Candidate State

ADR-023 uses:

```text
prepare
→ verify
→ commit
```

Candidate Tenant queries may be loaded into their own context-safe Query identities before commit.

Example:

```text
Membership B / Tenant B
/auth/me

B workspaces

B capabilities
```

do not overwrite Tenant A cache.

---

# 48. Tenant Commit

On successful commit:

```text
active context
A → B
```

Active observers move to B-scoped Query identities.

Tenant A data immediately becomes:

```text
inactive
```

for rendering.

It need not necessarily be physically deleted at the same instant.

---

# 49. Old Tenant Cache Retention

Bounded inactive cache may remain temporarily for performance.

Allowed only if:

```text
strictly partitioned

no active observer treats it as current

no capability from it authorizes current UI

eventually garbage collected

logout clears protected state
```

This is cache retention, not context preservation.

---

# 50. Workspace Switch

Workspace switching follows the same principle.

Example:

```text
Tenant A / Workspace X
```

and:

```text
Tenant A / Workspace Y
```

have distinct Workspace-sensitive query partitions.

Tenant-scoped queries may remain valid because Tenant itself did not change.

---

# 51. Selective Invalidation

Workspace switch SHOULD NOT blindly invalidate every Tenant query.

Instead:

```text
Workspace-sensitive state
→ switch/invalidate as required

Tenant-wide state
→ may remain valid
```

This reduces unnecessary API traffic.

---

# 52. Tenant Switch Invalidation

Tenant switch changes the authentication context.

Therefore all data requiring the previous:

```text
Membership/Tenant
```

must immediately stop being active.

The new Tenant receives independent query identities.

---

# 53. Logout

Successful logout or canonical session invalidation requires protected cache cleanup.

Canonical behavior:

```text
stop protected activity
↓
cancel relevant requests
↓
clear protected Query cache
↓
clear runtime context
↓
clear restoration hints
↓
UNAUTHENTICATED
```

---

# 54. Cross-User Leakage Prevention

If another User authenticates in the same tab after logout:

```text
previous User protected server state
```

MUST NOT remain available to the new User.

A complete QueryClient protected-state clear is an acceptable default and preferred over complicated retention.

---

# 55. Browser-Session-Wide Logout

ADR-022 establishes that logout invalidates the browser session shared by EduCore tabs.

Another tab may still temporarily contain in-memory cached state until it learns the session is invalid.

That state MUST become non-authoritative on the next protected operation/bootstrap failure.

No cached protected state may override canonical session invalidation.

---

# 56. Query Freshness Is Not Authorization Freshness

A TanStack Query marked:

```text
fresh
```

does not mean:

```text
still authorized
```

Backend remains final authority on every protected operation.

Example:

```text
capability cache says allowed
↓
backend returns AUTHORIZATION_DENIED
```

Frontend must obey backend and refresh/re-evaluate.

---

# 57. Capability Query Freshness

Capability state may be cached for UX efficiency.

However it must be invalidated/refetched when relevant events occur:

```text
Tenant switch

Workspace switch

canonical authorization denial indicating stale projection

role/permission administration affecting current context
when explicitly known
```

Exact stale-time values remain TDD concerns.

---

# 58. No Capability Persistence Across Reload

Capabilities are security-sensitive runtime projections.

They MUST NOT be persisted to Web Storage for reload optimization.

After reload they are obtained again from backend.

---

# 59. Read Retry Ownership

ADR-025 locks:

```text
transport automatic retry
= OFF
```

TanStack Query is the only layer permitted to provide automatic read retry.

This prevents duplicate retry stacks.

---

# 60. Retry Profiles

Read retry must be:

```text
bounded
+
error-aware
+
centrally defined
```

Conceptual profiles:

```text
NO_RETRY

SAFE_TRANSIENT_RETRY
```

Business components must not invent arbitrary retry loops.

---

# 61. Non-Retryable Reads

No automatic retry for canonical failures such as:

```text
VALIDATION_FAILED

AUTHENTICATION_REQUIRED

AUTHORIZATION_DENIED

ORGANIZATIONAL_CONTEXT_DENIED

RESOURCE_NOT_FOUND
```

unless a specific recovery flow explicitly initiates another authoritative request.

---

# 62. Retryable Reads

Safe idempotent reads may opt into bounded retry for conditions such as:

```text
transient network failure

selected 5xx failures

temporary service unavailability
```

Exact retry count/backoff remains implementation configuration.

---

# 63. No Infinite Retry

All automatic retry behavior is bounded.

Explicitly forbidden:

```text
retry until success
```

for normal application queries.

---

# 64. Mutation Retry

Canonical default:

```text
mutation retry
= FALSE
```

for:

```text
POST

PUT

PATCH

DELETE
```

unless the endpoint carries a documented idempotency guarantee and architecture explicitly opts in.

---

# 65. Tenant Switch Mutation

Tenant switching must not be placed under generic auto-retrying mutation behavior.

ADR-023 remains authoritative:

```text
automatic switch retry
= FORBIDDEN
```

---

# 66. Workspace Switch

Workspace switch itself is primarily client orchestration plus verification reads.

Those verification operations follow their own query policies.

There is no generic Workspace mutation retry because no server-side Workspace switch mutation exists.

---

# 67. Mutation Success

After successful mutation:

```text
backend response
```

is authoritative.

Relevant query families are then:

```text
updated explicitly
or
invalidated/refetched
```

according to the operation.

---

# 68. Targeted Invalidation

Avoid global:

```text
invalidate everything
```

after every mutation.

Invalidation should target the affected resource/context.

Example:

```text
Dormitory room update
↓
invalidate relevant Dormitory room/list queries
for current context
```

not every Academic/HR query.

---

# 69. Optimistic Mutation Policy

Foundation default:

```text
optimistic mutation
= opt-in
```

The mutation must have:

```text
well-understood rollback

low security/context ambiguity

predictable conflict behavior
```

before optimism is allowed.

---

# 70. High-Risk Optimistic Operations

Default optimism is forbidden for:

```text
Tenant switch

Role assignment

Permission assignment

Payments

Capacity allocation

Resident placement/check-in

other critical administrative operations
```

These operations should wait for canonical backend success.

---

# 71. Mutation Pending State

Mutation progress is owned by the mutation instance/feature.

Do not copy every mutation's:

```text
isPending
```

into a global application store.

Platform-level transitions such as Tenant switch remain explicit platform runtime states because their scope affects the whole application.

---

# 72. Background Refetch

Background refetch must not unnecessarily destroy currently valid UI.

Canonical behavior:

```text
valid cached data
+
background refetch
→ keep valid current presentation
```

unless security/context state has explicitly become stale or invalid.

---

# 73. Polling

Default:

```text
polling = OFF
```

A feature may use polling only when there is:

```text
explicit product requirement

bounded interval

visibility/background consideration

API capacity consideration
```

---

# 74. Realtime Is Separate

WebSocket/SSE architecture is not introduced through TanStack Query configuration.

Realtime remains a separate future requirement/ADR if needed.

---

# 75. Refetch Policy Governance

Options such as:

```text
refetchOnWindowFocus

refetchOnReconnect

staleTime

gcTime
```

must be configured using centralized/query-family policies rather than arbitrary component-level defaults.

Exact values are implementation/TDD decisions.

---

# 76. Cache Garbage Collection

Inactive protected queries must have bounded lifetime.

Foundation MUST NOT create:

```text
infinite protected cache retention
```

across a long-running administrative session.

Exact `gcTime` profiles remain implementation configuration.

---

# 77. Query Errors

TanStack Query consumes normalized failures from ADR-025.

It does not parse raw backend envelopes independently.

Conceptually:

```text
platform/api
→ ApiFailure / NetworkFailure / ContractFailure
↓
TanStack Query
↓
owning feature/platform recovery
```

---

# 78. No Global Error-Side-Effect Interceptor

A global QueryClient error callback MUST NOT implement simplistic behavior such as:

```text
401 → logout

403 → dashboard

422 → toast
```

Recovery belongs to the platform/domain owner with operation context.

Global hooks may provide safe observability only.

---

# 79. Context Recovery Integration

Examples:

```text
ORGANIZATIONAL_CONTEXT_DENIED
→ platform/workspace recovery

AUTHORIZATION_DENIED
→ platform/authorization refresh/re-evaluation

SESSION_INVALID
→ platform/session recovery
```

Query infrastructure delivers the normalized failure.

It does not own all recovery semantics.

---

# 80. React Context Policy

React Context may be used for stable cross-tree platform runtime state such as:

```text
session lifecycle

active Tenant/Membership context

active Workspace context

platform service composition
```

but should remain narrowly scoped.

---

# 81. No Giant `AppContext`

Rejected:

```text
<AppContext
  value={{
    user,
    memberships,
    permissions,
    students,
    rooms,
    forms,
    sidebar,
    ...
  }}
>
```

Platform concerns should expose separate, explicit boundaries.

---

# 82. React Context Performance Rule

Frequently changing large server responses should not be propagated through broad React Context providers.

Those remain Query data observed by the components that need them.

This limits unnecessary rerender fan-out.

---

# 83. State Duplication Rule

Before copying data between state systems, ask whether a second authority is being created.

Rejected:

```text
TanStack Query memberships
↓
copy complete memberships array
into global tenancy store
```

The runtime tenancy state normally needs only the selected authoritative context, while the catalog remains Query-owned.

---

# 84. Derived State

Derived information should normally be calculated from its authoritative owner instead of separately stored.

Example:

```text
canDeleteStudent
```

should derive from:

```text
current capability projection
```

rather than become independently persisted client state.

---

# 85. Module State Ownership

Each business module owns its own server-state query definitions.

Example:

```text
modules/academic
→ Academic queries/mutations

modules/hr
→ HR queries/mutations

modules/dormitory
→ Dormitory queries/mutations
```

The platform supplies:

```text
QueryClient

API transport

context information

error contracts
```

but does not become the owner of every business query.

---

# 86. Cross-Module Cache Access

A module must not reach into another module's private query keys/cache internals.

If cross-module integration is genuinely required:

```text
public module contract
```

from ADR-021 must be used.

---

# 87. Query Key Is Part of Module Contract Internally

Private key factories remain module implementation details unless explicitly exposed.

This prevents another module from depending on cache internals such as:

```text
queryClient.getQueryData(hrInternalKeys...)
```

without an architecture contract.

---

# 88. DevTools

Development tools may expose in-memory state for debugging.

Therefore developers must assume Query keys and cached values can be inspected locally.

Another reason credentials/secrets must never appear there.

Production DevTools exposure is controlled by build configuration.

---

# 89. State Ownership Matrix

| State                        | Canonical Frontend Owner              |
| ---------------------------- | ------------------------------------- |
| `/auth/me` response          | TanStack Query                        |
| Authentication lifecycle     | `platform/auth` / `platform/session`  |
| `/my-memberships`            | TanStack Query                        |
| Active Membership/Tenant     | `platform/tenancy`                    |
| Membership reload hint       | controlled `sessionStorage` adapter   |
| `/my-workspaces`             | TanStack Query                        |
| Active Workspace             | `platform/workspace`                  |
| Workspace reload hint        | controlled `sessionStorage` adapter   |
| Tenant capabilities          | TanStack Query                        |
| Workspace capabilities       | TanStack Query                        |
| Authorization UX derivation  | `platform/authorization`              |
| Business resource data       | owning module + TanStack Query        |
| Form fields/dirty state      | form-local                            |
| Modal/dropdown state         | local React state                     |
| Shareable filters/pagination | URL where appropriate                 |
| Bearer credential            | server-side BFF only                  |
| Browser session cookie       | browser/server; inaccessible to React |

---

# 90. Context Transition Matrix

## Tenant switch

```text
Runtime Tenant state
→ changes

Workspace runtime state
→ reset to TENANT

Tenant/Workspace queries
→ active partition changes

old Tenant cache
→ inactive / bounded retention allowed

capabilities
→ fresh target projection required
```

## Workspace switch

```text
Runtime Tenant state
→ unchanged

Workspace runtime state
→ changes

Tenant-only queries
→ may remain valid

Workspace queries
→ active partition changes

capabilities
→ fresh target projection required
```

## Logout

```text
runtime auth/context
→ cleared

protected queries
→ cleared

restoration hints
→ cleared
```

---

# 91. Critical Data-Isolation Requirement

The architecture must prove:

```text
Students
Tenant A
Workspace X
```

cannot appear as current data for:

```text
Students
Tenant B
Workspace Y
```

even if:

```text
same component
same route
same filter
same resource endpoint
```

is reused.

Context identity is part of state identity.

---

# 92. Query Cache Is UX Infrastructure

TanStack Query cache is allowed to improve:

```text
latency

deduplication

background freshness

navigation experience
```

It is not allowed to become:

```text
authorization authority

business source of truth

security boundary
```

Backend remains canonical.

---

# 93. Session Generation

The frontend runtime should maintain a non-secret:

```text
sessionGeneration
```

or equivalent identity revision.

It changes when the authenticated browser identity lifecycle is replaced or invalidated.

Purpose:

```text
fence stale async work
```

not authentication.

Exact representation remains TDD detail.

---

# 94. Tenant and Workspace Generations

Likewise ADR-023/024 generations remain valid.

Conceptually:

```text
sessionGeneration

tenantGeneration

workspaceGeneration
```

may participate in asynchronous fencing.

Implementation may combine them into a single context revision if correctness remains explicit.

---

# 95. No Random Global Event Bus for State

Rejected architecture:

```text
eventBus.emit("TENANT_CHANGED")

eventBus.emit("USER_UPDATED")

eventBus.emit("CACHE_RESET")
```

used as the primary state-consistency mechanism.

Explicit state owners, Query invalidation, and typed composition contracts are preferred.

---

# 96. Testing Requirements

Implementation must prove:

```text
1. one QueryClient exists per SPA runtime/tab.

2. protected Query cache is memory-only.

3. authenticated Query data is not restored from Web Storage.

4. /auth/me is Query-owned server state.

5. session lifecycle is not stored as ordinary Query data.

6. Membership catalog is Query-owned.

7. active Membership/Tenant is runtime-state owned.

8. Workspace catalog is Query-owned.

9. active Workspace is runtime-state owned.

10. capability projection remains Query-owned.

11. zero capabilities differs from query error.

12. Tenant A and Tenant B use distinct cache identities.

13. Workspace X and Workspace Y use distinct
    Workspace-sensitive cache identities.

14. Tenant-only query can remain valid across Workspace switch.

15. secrets never appear in query keys.

16. old-context response cannot update active new context.

17. cancellation occurs where supported.

18. correctness does not depend on cancellation alone.

19. equivalent reads are deduplicated.

20. logout clears protected cache.

21. different User login cannot inherit previous User cache.

22. automatic mutation retry is disabled.

23. safe read retry is bounded and error-aware.

24. context/authorization errors are not automatically retried.

25. polling is off unless explicitly enabled.

26. high-risk administrative mutations are not optimistic.

27. targeted invalidation affects the correct context.

28. dirty form state is not lost through query invalidation.

29. business modules cannot access another module's
    private query internals.

30. server-state cache never overrides canonical backend denial.
```

---

# 97. Critical Race Tests

### Race A — Tenant

```text
Students query Tenant A starts
↓
Tenant switch B commits
↓
Tenant A query completes
```

Expected:

```text
Tenant A result
cannot update Tenant B UI
```

---

### Race B — Workspace

```text
Workspace X query starts
↓
Workspace Y commits
↓
X query completes
```

Expected:

```text
X result remains isolated
```

---

### Race C — Logout

```text
protected query starts
↓
logout/session invalidation
↓
query completes
```

Expected:

```text
response cannot repopulate
authenticated UI/cache authority
```

---

### Race D — Candidate Tenant

```text
Tenant B bootstrap prefetched
↓
switch B later fails before commit
```

Expected:

```text
B cache may exist temporarily
but B never becomes active context
```

---

# 98. Architecture Enforcement

Lint/tests should make the following detectable where practical:

```text
raw fetch outside platform API

query persistence plugin

localStorage/sessionStorage business cache

cross-module private query-key imports

generic bearer/query storage

global client store becoming server-state authority
```

---

# 99. Architectural Invariants

If ADR-026 is accepted:

```text
Server state
= TanStack Query

QueryClient
= one per SPA/tab

Protected query persistence
= FORBIDDEN Foundation v1

localStorage server cache
= FORBIDDEN

sessionStorage server cache
= FORBIDDEN

Membership catalog
= server state

active Membership/Tenant
= runtime context state

Workspace catalog
= server state

active Workspace
= runtime context state

capabilities
= server state

capability copy in global store
= FORBIDDEN

form state
= form-local

transient UI
= local React state

shareable navigation state
= URL where appropriate

restoration hints
= controlled non-secret sessionStorage only

general global state library
= NOT Foundation requirement

giant global store
= REJECTED

TanStack Query as generic client store
= REJECTED

query identity
= context-aware

secrets in query keys
= FORBIDDEN

context response fencing
= REQUIRED

cancellation-only correctness
= REJECTED

transport retry
= OFF

safe read retry
= Query-layer, bounded, opt-in/policy-driven

mutation retry
= OFF by default

optimistic mutations
= opt-in only

polling
= OFF by default

logout/session invalidation
= clear protected server state

backend
= final source of truth
```

---

# 100. Consequences

## Positive

- Server state has one clear owner.
- No Redux-style duplication of API responses is required.
- Tenant/Workspace cache contamination becomes structurally difficult.
- Multi-tab isolation remains natural.
- Reload does not resurrect sensitive stale data.
- Query deduplication reduces unnecessary API traffic.
- Context transitions can preserve useful inactive cache safely.
- Business modules own their own resource queries.
- Platform state remains small and understandable.
- Authentication, context, forms, and UI state do not become one global system.
- Cache invalidation can be targeted rather than application-wide.
- Architecture remains compatible with future scaling.

## Costs

- Developers must understand state classification.
- Query keys require disciplined factories.
- Context transitions need explicit fencing.
- Some state exists in different mechanisms by design.
- Query persistence cannot be used as a shortcut for reload UX.
- Architecture tests/lint rules are needed.
- Module authors must understand Tenant vs Workspace query scope.

These costs are accepted because state ownership ambiguity in a multi-tenant application is a correctness and security risk.

---

# 101. Risks

## Risk — Runtime contexts become another giant store

Mitigation:

```text
separate session
tenancy
workspace
authorization concerns

store only runtime selection/lifecycle

leave catalogs/data in Query cache
```

---

## Risk — Query keys omit context

Mitigation:

```text
typed query-key factories

OpenAPI context metadata

architecture tests

race-condition tests
```

---

## Risk — Excessive refetching

Mitigation:

```text
targeted invalidation

bounded cache lifetime

no default polling

central freshness profiles
```

---

## Risk — Stale cache mistaken for authorization

Mitigation:

```text
backend final authority

authorization denial refresh

capability projection revalidation

no persistent capability cache
```

---

# 102. Explicit Non-Decisions

ADR-026 does not decide:

```text
exact TanStack Query package minor version

exact QueryClient configuration numbers

exact staleTime values

exact gcTime values

exact safe-read retry count

exact retry backoff formula

exact query-key TypeScript implementation

exact React Context/provider implementation

form library

whether future demonstrated client complexity
requires Redux/Zustand/etc.

exact URL-state utility

TanStack Query DevTools policy

realtime/WebSocket integration
```

Those remain TDD/implementation or future architecture decisions.

---

# 103. Follow-Up Dependency

With state ownership established, the next architectural concern is:

```text
How should capability projection
control navigation, routes,
and individual UI actions
without becoming frontend security authority?
```

Therefore the next ADR is:

```text
ADR-027
Capability-Aware Navigation
& Authorization UX
```

ADR-027 will consume:

```text
ADR-024
Workspace context

ADR-025
canonical API/errors

ADR-026
capability server-state ownership
```

and establish the frontend policy model for navigation, route guards, actions, and backend-denial reconciliation.

---

# ADR-026 Proposed State

```text
ADR-026 — Server-State & Client-State Ownership

Status:
🔒 ACCEPTED / LOCKED

Server state:
TanStack Query

QueryClient:
one per SPA/tab

Protected query persistence:
❌ FORBIDDEN

Giant global store:
❌ REJECTED

TanStack Query as generic UI store:
❌ REJECTED

Redux/Zustand Foundation requirement:
❌ NOT SELECTED

/auth/me:
server state

Authentication lifecycle:
runtime platform state

Membership catalog:
server state

Active Membership/Tenant:
runtime platform state

Workspace catalog:
server state

Active Workspace:
runtime platform state

Capabilities:
server state

Form state:
form-local

Transient UI:
local React state

Shareable view state:
URL where appropriate

Reload hints:
controlled non-secret sessionStorage

Query keys:
context-aware

Tenant/Workspace cache contamination:
❌ FORBIDDEN

Cancellation:
✅ supported

Context-generation fencing:
✅ REQUIRED

Automatic mutation retry:
❌ OFF

Optimistic mutation:
⚠️ OPT-IN ONLY

Polling:
❌ OFF BY DEFAULT

Logout:
clear protected cache

Backend:
✅ FINAL SOURCE OF TRUTH
```
