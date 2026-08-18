# ADR-028 — Routing & Code-Splitting Strategy

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Routing Composition, Guards, Lazy Loading, Deep Links & Bundle Boundaries

---

> ## Decision Summary
>
> EduCore Frontend will use:
>
> ```text
> React Router 8
> +
> Data Mode
> +
> createBrowserRouter
> ```
>
> as the canonical client-side routing architecture.
>
> React Router Framework Mode is **not selected** for Foundation v1 because EduCore has already locked:
>
> ```text
> React SPA
> +
> Vite
> +
> static/CDN deployment
> +
> TanStack Query server-state ownership
> +
> Laravel /api/v1 backend
> ```
>
> Data Mode provides route objects, route-level error boundaries, loaders/actions when needed, and lazy route modules without introducing an additional full frontend-framework rendering layer. React Router's current documentation explicitly positions Data Mode between Declarative and Framework Mode and supports `createBrowserRouter` plus lazy route definitions.
>
> The route tree is **static for a given frontend build**.
>
> It is NOT rebuilt based on:
>
> ```text
> role
> permission
> Tenant
> Workspace
> capability projection
> ```
>
> Authorization controls:
>
> ```text
> route accessibility
> +
> navigation visibility
> ```
>
> but not whether the route exists in the application build.
>
> Each business module owns its route contribution.
>
> The application composition root combines those lightweight route manifests into one router.
>
> Heavy business route implementations are loaded through dynamic imports and route-level lazy loading.
>
> Application/platform bootstrap remains in the critical initial bundle, while:
>
> ```text
> Academic
> HR
> Dormitory
> Finance
> Library
> ...
> ```
>
> are lazy-loaded.
>
> Business server-state remains owned by TanStack Query. React Router loaders MUST NOT create a parallel server-state architecture.
>
> Route loaders may coordinate:
>
> ```text
> navigation prerequisites
> redirects
> context readiness
> QueryClient prefetch/ensure
> ```
>
> but any backend data they obtain must flow through the same canonical Query/API infrastructure defined by ADR-025 and ADR-026.
>
> Production infrastructure must support SPA History API fallback for application routes while excluding:
>
> ```text
> /api/*
> static assets
> existing files
> ```
>
> from that fallback.

## Related ADR

- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation
- ADR-023 — Tenant / Membership Context Switching
- ADR-024 — Workspace / Organizational Context Management
- ADR-025 — API Client, OpenAPI & Canonical Error Handling
- ADR-026 — Server-State & Client-State Ownership
- ADR-027 — Capability-Aware Navigation & Authorization UX

---

# 1. Context

ADR-020 established:

```text
React
+
TypeScript
+
Vite
+
Client-Side SPA
```

ADR-021 established:

```text
app
platform
shared
modules
```

with business-module-owned route/navigation contracts.

ADR-023 and ADR-024 established context-sensitive navigation semantics:

```text
Tenant switch
→ /dashboard

Workspace switch
→ preserve route only when still valid
```

ADR-027 established:

```text
route exists
≠ user is authorized to enter it
```

and:

```text
navigation hidden
≠ route nonexistent
```

ADR-028 must now determine how these decisions are implemented as one coherent routing architecture.

---

# 2. Current Repository State

Repository inspection shows that frontend routing has not yet been implemented.

Current JavaScript build still points to:

```text
resources/js/app.js
```

and current browser route is still the Laravel scaffold:

```text
GET /
→ welcome.blade.php
```

The repository currently has no:

```text
React
React Router
TanStack Query
```

frontend implementation.

Therefore ADR-028 is setting the routing architecture before migration rather than documenting accidental existing behavior.

---

# 3. Decision Drivers

Routing architecture must support:

```text
1. Static SPA deployment

2. Deep-link navigation

3. Module ownership

4. Route-level lazy loading

5. Tenant/Workspace-aware guards

6. Capability-aware authorization UX

7. Direct URL authorization

8. Dirty-form protection

9. Route-level error isolation

10. Query-cache integration

11. Context race isolation

12. Small initial bundle

13. Hundreds of future routes

14. Predictable browser history

15. Maintainability

16. Testability

17. No duplicate data-fetching architecture
```

---

# 4. Alternatives Considered

## Option A — React Router Declarative Mode

Conceptually:

```tsx
<BrowserRouter>
    <Routes>...</Routes>
</BrowserRouter>
```

### Advantages

- Small routing API.
- Familiar React composition.
- Appropriate for simple SPAs.

### Trade-offs

EduCore needs more explicit route-level capabilities around:

```text
error boundaries
navigation lifecycle
lazy route modules
routing metadata
route preconditions
```

Data Mode offers these using route objects outside React rendering. Current React Router documentation describes Data Mode as adding loaders, actions, pending states, and other route-level APIs through `createBrowserRouter`.

### Decision

```text
VALID
but
NOT SELECTED
```

---

# 5. Option B — React Router Framework Mode

Framework Mode provides additional capabilities including:

```text
route-module conventions
type-safe route APIs
intelligent code splitting
SPA / SSR / static rendering strategies
```

according to current React Router documentation.

### Advantages

- Strong route conventions.
- Automated route-module tooling.
- Integrated type generation.
- Integrated rendering strategies.
- Strong code-splitting support.

### Problem for Current EduCore Architecture

EduCore already has architectural owners for:

```text
Build
→ Vite

Rendering
→ Client-side SPA

Server State
→ TanStack Query

Backend
→ Laravel API

Deployment
→ static/CDN
```

Framework Mode would add another broader application-framework layer whose additional SSR/static-render framework capabilities are currently unnecessary.

Its SPA Mode also retains build-time server rendering of the root route and introduces framework-specific runtime/build requirements even with runtime SSR disabled.

### Decision

```text
NOT SELECTED
for Foundation v1
```

This may be reconsidered if future architecture genuinely benefits from React Router Framework Mode rather than merely from additional convenience APIs.

---

# 6. Option C — Custom Router

Building EduCore's own:

```text
history handling
route matching
nested layouts
route params
navigation blocking
error boundaries
```

has no product value.

### Decision

```text
REJECTED
```

---

# 7. Option D — React Router Data Mode

Selected:

```text
createBrowserRouter
+
RouterProvider
+
route objects
+
lazy route implementations
```

Data Mode allows route configuration outside component rendering and supports client rendering plus lazy route modules.

### Decision

```text
SELECTED
```

---

# 8. React Router Version Policy

Architecture baseline:

```text
React Router 8.x
```

Exact minor/patch version is pinned during implementation through:

```text
package.json
+
lockfile
```

Normal compatible upgrades within the accepted major do not require another ADR.

A future major upgrade that changes architectural semantics requires review but not necessarily a new ADR unless this decision changes materially.

---

# 9. Router Ownership

Canonical routing infrastructure belongs to:

```text
platform/routing
```

while router composition occurs under:

```text
app
```

Conceptually:

```text
app
 ↓
compose platform routes
+
module route contributions
 ↓
createBrowserRouter
```

---

# 10. Router Is Created Once

The router is created once during application bootstrap.

It MUST NOT be reconstructed whenever:

```text
Tenant changes

Workspace changes

permissions change

capabilities reload
```

These are runtime context changes.

The route catalog is application-build state.

---

# 11. Why Routes Are Not Permission-Filtered at Construction

Rejected:

```text
capabilities
↓
build allowed route tree
↓
create router
```

because capability changes would require router reconstruction.

It would also turn:

```text
unauthorized route
```

into:

```text
route not found
```

which destroys the distinction between:

```text
404
and
Access Denied
```

Canonical model:

```text
Route exists
       ↓
Route policy evaluates
       ↓
Allowed / Denied / Context Required
```

---

# 12. Top-Level Route Architecture

Conceptually:

```text
Application Router
│
├── Public
│   └── Login
│
├── Protected Application
│   ├── Dashboard
│   ├── Academic /*
│   ├── HR /*
│   ├── Dormitory /*
│   ├── PPDB /*
│   └── future modules /*
│
└── Not Found
```

Exact URLs for future business features remain module-specific contracts.

---

# 13. Public Route Boundary

Public routes do not require authenticated application bootstrap.

Typical Foundation example:

```text
/login
```

Public route must not unnecessarily initialize:

```text
Workspace

capabilities

business module queries
```

before authentication.

---

# 14. Protected Application Boundary

Protected routes live beneath a shared application shell boundary.

Conceptually:

```text
ProtectedRoot
├── Session Bootstrap
├── Tenant Context
├── Workspace Context
├── Capability Context
├── Topbar
├── Sidebar
└── Outlet
```

Authentication/context infrastructure remains platform-owned.

---

# 15. Protected Route Guard Order

Canonical guard order remains:

```text
1. Authentication state

2. Membership/Tenant context

3. Route context requirement

4. Required capability projection readiness

5. Permission policy

6. Route rendering
```

This order follows ADR-027.

---

# 16. Authentication Unresolved

If authentication is:

```text
BOOTSTRAPPING
```

protected route does not:

```text
render protected component
```

and does not:

```text
redirect to login prematurely
```

It renders application bootstrap state until authentication becomes authoritative.

---

# 17. Unauthenticated Protected Route

Once authentication is authoritatively:

```text
UNAUTHENTICATED
```

a protected route navigates to:

```text
/login
```

with a safe intended-destination hint where appropriate.

---

# 18. Return Destination Security

A login return target may contain only a validated internal application location.

Forbidden:

```text
https://evil.example

//evil.example

javascript:...
```

as trusted post-login destinations.

The return path is navigation convenience:

```text
not security authority
```

and MUST NOT contain:

```text
bearer credential
password
session secret
```

---

# 19. Authenticated Login Route

An already authenticated user navigating to:

```text
/login
```

should normally be redirected to:

```text
/dashboard
```

or another canonical safe application route.

This avoids maintaining an authenticated and unauthenticated shell simultaneously.

---

# 20. Module Route Ownership

Each business module owns its route contribution.

Conceptually:

```text
modules/academic
→ AcademicRouteContribution

modules/hr
→ HRRouteContribution

modules/dormitory
→ DormitoryRouteContribution
```

Those are exposed through ADR-021 public contracts.

---

# 21. Application Composition

`app` is allowed to know which modules participate in the frontend build.

Conceptually:

```text
createAppRouter([
    platformRoutes,
    academicRoutes,
    hrRoutes,
    dormitoryRoutes,
])
```

Exact API remains TDD detail.

---

# 22. No Runtime Module Service Locator

Routing does not discover module routes by:

```text
serviceLocator.getRoutes()
```

or browser-side filesystem scanning.

The route composition graph is explicit and statically analyzable.

This preserves:

```text
readability
bundle analysis
type safety
dependency visibility
```

---

# 23. Backend Module Registry Does Not Build React Routes

The Laravel Module Registry is not a browser route manifest.

Frontend must not ask backend:

```text
which React components should I load?
```

and dynamically construct arbitrary module routes.

Frontend module inclusion is a build/composition concern.

A future plugin/micro-frontend architecture would require a separate ADR.

---

# 24. Lightweight Route Manifests

Module route contributions should remain lightweight.

They may eagerly contain:

```text
route ID

path

context requirement

authorization scope

permission requirement

lazy import reference

error-boundary metadata
```

but MUST NOT eagerly import heavy feature implementations.

---

# 25. Code-Splitting Boundary

Minimum required code splitting:

```text
Initial Application
├── router/runtime
├── application bootstrap
├── authentication
├── platform shell
└── active initial route

Academic
→ lazy

HR
→ lazy

Dormitory
→ lazy

Finance
→ lazy

Library
→ lazy
```

This preserves FE-8's bundle requirements.

---

# 26. Route-Level Lazy Loading

Business route implementations use dynamic imports.

React supports deferring component code using `lazy`, and React Router Data Mode supports lazy route definitions.

Conceptually:

```text
route metadata
    ↓
dynamic import
    ↓
route implementation
```

---

# 27. Module-Level and Feature-Level Splitting

A large module may split further.

Example:

```text
Dormitory
│
├── Dashboard
├── Buildings
├── Rooms
├── Residents
└── Placements
```

Opening:

```text
/dormitory
```

must not inherently require loading every heavy Dormitory feature implementation.

---

# 28. SPA Does Not Mean One Bundle

Explicit invariant:

```text
SPA
≠
single monolithic JavaScript bundle
```

Vite production builds support configurable code splitting, while dynamic imports form natural lazy boundaries. Current Vite 8 build documentation exposes chunk-splitting configuration through its Rolldown build options.

---

# 29. Default Before Manual Chunking

EduCore first relies on:

```text
dynamic-import boundaries
+
Vite production bundling
```

rather than immediately building a large handcrafted chunk topology.

Manual/custom code splitting is introduced only after:

```text
bundle analysis
+
real performance evidence
```

demonstrates need.

---

# 30. No Giant `vendor` Chunk Requirement

Foundation does not require manually combining all dependencies into:

```text
vendor.js
```

because a large global vendor chunk can itself become an initial-load bottleneck.

Chunk architecture should optimize actual dependency graphs rather than cosmetic filenames.

---

# 31. Bundle Budget Remains Mandatory

FE-8 budgets remain:

```text
Initial critical JS
target ≤ 300 KB gzip

Normal route incremental chunk
target ≤ 150 KB gzip
```

Large specialized functionality must justify or subdivide its chunk.

ADR-028 defines the splitting mechanism; CI/performance enforcement belongs to ADR-031/TDD.

---

# 32. Route Metadata Must Not Pull Heavy Modules Eagerly

Example rejected pattern:

```ts
import DormitoryDashboard from './Dashboard';
import ResidentManagement from './Residents';
import PlacementManagement from './Placements';

export const routes = [...]
```

in a route manifest loaded during application bootstrap.

This negates lazy-loading boundaries.

---

# 33. Lazy Import Must Be Bundler-Discoverable

Dynamic imports should use deterministic module references that Vite can analyze.

Avoid architecture based on unconstrained strings such as:

```text
import(userSuppliedModulePath)
```

for application routes.

Route modules are application code, not user-selected executable plugins.

---

# 34. Route Error Boundaries

Routing architecture provides error isolation at minimum:

```text
Application Boundary
       ↓
Module Route Boundary
       ↓
Page / Feature
```

React Router route objects support route-level error-boundary behavior in Data Mode.

---

# 35. Module Failure Isolation

Example:

```text
Dormitory route crashes
```

should not necessarily destroy:

```text
Topbar

Sidebar

Tenant controls

Workspace controls

Logout
```

if platform state remains valid.

This preserves FE-7 runtime isolation.

---

# 36. Lazy Chunk Failure

A route chunk can fail because of:

```text
network failure

deployment transition

stale HTML referencing unavailable hashed asset

CDN error
```

This is different from:

```text
API error
```

and from:

```text
authorization denial
```

---

# 37. Chunk Failure Recovery

Chunk load failure should produce controlled recovery UX.

Conceptually:

```text
route chunk unavailable
↓
check/report application version context
↓
offer refresh / bounded recovery
```

The frontend MUST NOT create:

```text
reload
↓
failure
↓
reload
↓
failure
...
```

loops.

---

# 38. Deployment Compatibility

Deployment architecture should retain old content-hashed assets long enough for already-loaded application documents to complete lazy navigation where practical.

This complements FE-8's immutable-artifact deployment strategy.

A deployment must not depend on:

```text
delete all old chunks immediately
```

as its release activation mechanism.

---

# 39. Browser History Strategy

EduCore uses normal History API URLs through:

```text
createBrowserRouter
```

rather than hash routing such as:

```text
/#/academic/students
```

React Router's `createBrowserRouter` is the browser runtime API for Data Mode routing.

---

# 40. Hash Router

Example:

```text
/#/dashboard
```

would avoid server/CDN history fallback requirements.

### Trade-off

It produces less natural URLs and introduces a technical URL fragment purely to avoid deployment configuration that EduCore already controls.

### Decision

```text
REJECTED
```

---

# 41. SPA History Fallback

Production static infrastructure MUST route unknown application paths such as:

```text
/academic/students

/dormitory/rooms

/dashboard
```

to the SPA entry document.

React Router's SPA guidance describes SPA deployments as serving application paths from a common HTML entry point.

---

# 42. History Fallback Exclusions

SPA fallback MUST NOT swallow:

```text
/api/*

actual static assets

favicon

robots.txt

other existing public files
```

Example bad behavior:

```text
GET /assets/missing.js
↓
index.html
200
```

That converts asset failures into misleading JavaScript parse errors.

A missing asset should remain an asset failure.

---

# 43. Same-Origin API Priority

If frontend and Laravel API eventually share one hostname:

```text
/api/*
```

routing must be resolved before SPA fallback.

Conceptually:

```text
/api/*
→ Laravel API

/assets/*
→ static asset

everything else application-like
→ index.html
```

If separate origins are used, the same conceptual boundary still applies.

---

# 44. Client-Side Not Found

An unmatched SPA route renders:

```text
Not Found
```

inside the application.

It is distinct from:

```text
Access Denied
```

and:

```text
Context Required
```

---

# 45. 404 vs Authorization Denial

Known route + missing permission:

```text
Access Denied
```

Unknown route:

```text
Not Found
```

Do not intentionally disguise authorization denial as 404 unless a future explicit security requirement requires resource-existence concealment for a specific API/domain flow.

---

# 46. Context Required Is Also Distinct

Known route that requires organizational context while current Workspace is:

```text
TENANT
```

produces:

```text
Context Required
```

rather than:

```text
Not Found
```

or immediate:

```text
Access Denied
```

if authorization has not yet been evaluated under a valid Workspace.

---

# 47. Route Policy Metadata

Each protected route may conceptually carry:

```text
routeId

contextRequirement

authorizationScope

requiredPermissions
```

from ADR-027.

Exact implementation may use React Router route metadata mechanisms, but the semantics are locked here.

---

# 48. Stable Route IDs

Every meaningful route should have a stable namespaced identifier.

Examples:

```text
core.dashboard

academic.students.index

dormitory.rooms.index

dormitory.residents.view
```

Route ID is useful for:

```text
navigation references

testing

observability

safe-route decisions
```

and should not depend on translated labels.

---

# 49. Route ID Uniqueness

Application composition tests must reject:

```text
duplicate route IDs
```

and conflicting canonical route paths where the conflict cannot be intentionally resolved.

Module prefixes and namespaced route IDs reduce collision risk.

---

# 50. Navigation References Routes

Navigation metadata should reference stable route identity/path definitions rather than re-declaring unrelated route semantics independently.

Goal:

```text
one route policy
```

rather than:

```text
route says permission A
navigation independently says permission B
```

Exact metadata composition remains TDD work.

---

# 51. Route Visibility Does Not Mutate Router

Capability changes cause:

```text
navigation recomputation
+
route guard re-evaluation
```

They do NOT cause:

```text
router recreation
```

or removal/addition of route objects.

---

# 52. Tenant Switch Navigation

ADR-023 remains authoritative.

After successful Tenant commit:

```text
navigate /dashboard
```

using route replacement where appropriate so the old Tenant resource page does not become the immediate back-navigation target under a changed context.

---

# 53. Why Tenant Switch Does Not Preserve Route

Example:

```text
Tenant A
/dormitory/residents/123
```

cannot safely be interpreted as the equivalent resource in Tenant B.

Therefore:

```text
Tenant switch
→ safe dashboard
```

remains deterministic.

---

# 54. Workspace Switch Navigation

ADR-024 remains authoritative.

Workspace switch may preserve the existing route only when:

```text
route exists

context requirement satisfied

target capability READY

required permission exists

route semantics allow selected Workspace
```

Otherwise:

```text
safe route
```

is selected.

---

# 55. Safe Route Evaluation

Canonical fallback ordering is conceptually:

```text
current route still valid?
    ↓ yes
keep route

no
    ↓
module landing valid?
    ↓ yes
module landing

no
    ↓
dashboard
```

Exact algorithm can be refined during TDD but must never select a route based on role-name assumptions.

---

# 56. Browser Back After Context Switch

History entries may represent locations created under previous context.

Therefore pressing Back after a context switch does not automatically make the old route authoritative.

The route guard re-evaluates it under the **current** Tenant/Workspace context.

If invalid:

```text
controlled fallback
```

occurs.

---

# 57. URL Does Not Restore Security Context

Route:

```text
/dormitory/rooms
```

does not imply:

```text
Tenant

Membership

Workspace
```

Those remain tab runtime contexts.

URL alone must never switch Tenant or authorize Workspace.

---

# 58. Membership IDs in URLs

Membership selection should not be encoded in normal business URLs merely to make routing work.

Example rejected default:

```text
/tenant/{membership_id}/dormitory/rooms
```

because active Membership is already an authenticated runtime context.

A future explicit product requirement could revisit contextual URLs, but they would remain locators and require backend validation.

---

# 59. Workspace IDs in URLs

Likewise active organizational assignment is not required as a route-authority segment.

Normal Workspace context remains tab-local.

Resource IDs may still appear in URLs when they identify actual business resources.

---

# 60. Route Params Are Untrusted Input

Values like:

```text
/student/:id

/rooms/:roomId
```

are user-controlled URL input.

Frontend may validate formatting for UX, but backend must remain final authority for:

```text
existence

Tenant ownership

Workspace ownership

authorization
```

---

# 61. Search Parameters

ADR-026 establishes URL state for shareable view state.

Appropriate examples:

```text
?page=2

?search=ahmad

?sort=name
```

when product behavior supports them.

Search parameters must be parsed and normalized.

---

# 62. Sensitive Data in URLs

Forbidden:

```text
password

bearer

browser-session secret

CSRF secret

sensitive form payload
```

in:

```text
path

query string

hash fragment
```

Existing FE-3 security guardrails remain unchanged.

---

# 63. Business Data Loading

Business server data continues to use:

```text
TanStack Query
```

from ADR-026.

React Router is not introduced as a second independent server-state cache.

---

# 64. Loader Policy

React Router Data Mode supports loaders, but EduCore defines a constrained use.

Route loader may perform:

```text
navigation prerequisites

safe redirects

query prefetch / ensure

route-specific bootstrap coordination
```

It MUST NOT introduce parallel direct-fetch infrastructure.

---

# 65. Loader + TanStack Query Integration

If a route wants data ready before rendering:

```text
route loader
↓
module query definition
↓
QueryClient.ensureQueryData / equivalent
↓
platform/api
```

Conceptually.

The resulting data remains owned by:

```text
TanStack Query
```

not by a separate route-loader cache.

---

# 66. No Duplicate Request Model

Rejected:

```text
loader fetches students directly
+
component useQuery fetches students again
```

The route and component must consume the same query identity/definition.

---

# 67. Router Actions

React Router Data Mode also supports route actions.

However EduCore does not select route actions as the default business mutation architecture.

Canonical business mutations remain:

```text
module mutation
↓
TanStack Query mutation
↓
platform/api
```

This preserves ADR-026 ownership.

---

# 68. Route Actions May Be Used Only Deliberately

A future route-centric interaction may use an action if there is a demonstrated benefit.

But it must:

```text
respect canonical API transport

avoid duplicate mutation state systems

respect mutation retry policy

respect context generation

integrate cache invalidation
```

It cannot create an independent architectural path.

---

# 69. Route-Based Prefetching

Prefetch may be used selectively to improve perceived navigation performance.

Possible triggers include:

```text
user intent

hover

keyboard focus

likely next route
```

React Router currently supports link prefetch behaviors including intent-based prefetching.

---

# 70. No Global Aggressive Prefetch

Rejected:

```text
user loads dashboard
↓
download every authorized module
↓
prefetch every route data set
```

This undermines:

```text
bundle budgets

API scalability

lazy loading
```

---

# 71. Code Prefetch vs Data Prefetch

These are different decisions.

### Code prefetch

```text
download likely route chunk
```

may occur based on navigation intent.

### Data prefetch

```text
call protected API
```

requires:

```text
authoritative context
+
appropriate capability
+
explicit query policy
```

Do not conflate them.

---

# 72. Unauthorized Data Prefetch

Frontend should not initiate business data prefetch for a route whose required capability is known to be absent.

Backend would still deny it, but avoiding the request reduces unnecessary traffic and confusing telemetry.

Again:

```text
performance optimization
≠ security boundary
```

---

# 73. Suspense Boundaries

Lazy route code should have purposeful loading boundaries.

React `lazy` suspends while deferred component code is loading and is normally paired with a Suspense fallback.

EduCore should prefer:

```text
module/page route fallback
```

over blanking the entire application shell.

---

# 74. Application Shell Remains Stable

When a lazy page loads:

```text
Topbar
Sidebar
Tenant context
Workspace context
```

should normally remain visible if platform context remains valid.

Only route content needs to show the route-level loading state.

---

# 75. Initial Application Loading Is Different

Application authentication bootstrap remains:

```text
APPLICATION_BOOTSTRAP
```

and can temporarily occupy the protected application surface.

Route chunk loading after the application is already operational is:

```text
PAGE / MODULE LOAD
```

and should not be presented as a new login/bootstrap event.

---

# 76. Navigation Pending State

Routing infrastructure should expose pending navigation semantics consistently.

Navigation feedback must avoid:

```text
multiple competing full-screen loaders
```

from router, Query, Tenant switching, and Workspace switching.

The owning transition determines presentation priority.

---

# 77. Transition Priority

Conceptually:

```text
Authentication recovery
>
Tenant switch
>
Workspace switch
>
normal route navigation
>
background query refetch
```

Higher-level transitions may suppress lower-level route loading presentation.

Exact visual behavior remains TDD/UI implementation.

---

# 78. Dirty Form Navigation Blocking

Internal route navigation must respect FE-7 dirty-form policy.

If form is dirty:

```text
route navigation
↓
confirmation
```

before current route is abandoned.

---

# 79. Tenant / Workspace Switch Uses Same Dirty-State Contract

Dirty-form detection should expose a reusable platform contract consumed by:

```text
route navigation

Tenant switch

Workspace switch
```

rather than each subsystem independently inspecting forms.

Exact implementation belongs to TDD.

---

# 80. Browser Reload / Close

Where the browser permits, dirty forms may also register best-effort:

```text
beforeunload
```

protection.

Browser UX limitations are accepted.

Sensitive draft data is not automatically persisted merely to guarantee recovery.

---

# 81. Route Error vs API Error

Route-level runtime error:

```text
React component throws
```

is not the same as:

```text
API returns 403
```

or:

```text
API returns 500
```

Routing error boundaries isolate application/runtime errors, while API error policy remains ADR-025.

---

# 82. Route Error vs Chunk Error

Also distinguish:

```text
component runtime error

lazy chunk load failure

API failure

contract failure

authorization denial
```

because they have different recovery mechanisms.

---

# 83. Not Found Route

The application has a canonical catch-all:

```text
*
→ Not Found
```

within the SPA route tree.

Unknown routes do not cause raw framework stack traces.

---

# 84. Production Source Maps

Routing does not alter ADR-031/FE-8 source-map policy.

Error observability may correlate:

```text
routeId

module

frontend version

chunk
```

without exposing stack traces to end users.

---

# 85. Route Observability

Routing should expose safe events such as:

```text
route_navigation_started

route_navigation_completed

route_navigation_failed

route_access_denied

route_context_required

route_chunk_load_failed
```

without high-volume telemetry on every component render.

---

# 86. Route ID for Observability

Telemetry should prefer:

```text
routeId
```

such as:

```text
dormitory.rooms.index
```

over raw path:

```text
/dormitory/rooms/2d98...
```

when the latter may contain record identifiers.

This reduces unnecessary sensitive identifier collection.

---

# 87. Module Prefix Strategy

Business modules should normally own stable URL prefixes.

Examples:

```text
/academic/*

/hr/*

/dormitory/*
```

This provides:

```text
human-readable URLs

clear module ownership

simple route collision detection

natural lazy-loading boundaries
```

Exceptions require demonstrated UX/domain need.

---

# 88. Route Path Style

Static route segments should use a consistent:

```text
lowercase
kebab-case where multiple words are needed
```

style.

Route labels remain translatable presentation text and are not derived mechanically from path strings.

---

# 89. No Duplicate Canonical URLs

A feature should have one canonical application URL where practical.

Avoid maintaining multiple equivalent URLs solely for internal implementation convenience.

Legacy aliases, if ever needed, should redirect explicitly.

---

# 90. Route Redirect Policy

Redirects must be:

```text
intentional

bounded

cycle-free
```

Tests must detect obvious redirect loops for:

```text
login

dashboard

context-required

unauthorized
```

flows.

---

# 91. Browser Refresh on Deep Route

Example:

```text
/dormitory/rooms
```

followed by browser refresh must:

```text
load index.html
↓
bootstrap React
↓
restore browser session
↓
restore Membership
↓
restore Workspace if valid
↓
evaluate route
↓
load route chunk
```

It must not depend on Laravel Blade having a server-side Dormitory route.

---

# 92. Static CDN Compatibility

Therefore static hosting configuration is part of routing correctness.

A frontend deployment is not complete merely because:

```text
navigation from /dashboard works
```

Deep-link refresh must also work in staging/production.

---

# 93. Route Tests

Minimum routing tests must prove:

```text
1. router is created once per SPA runtime.

2. permission changes do not rebuild router.

3. Tenant changes do not rebuild router.

4. Workspace changes do not rebuild router.

5. public login does not require protected bootstrap.

6. protected route waits for auth bootstrap.

7. unauthenticated protected route goes to login.

8. external return URL is rejected.

9. authenticated login route goes to safe route.

10. module routes are composed from public module contracts.

11. duplicate route IDs fail architecture validation.

12. direct unauthorized route renders Access Denied.

13. unknown route renders Not Found.

14. Tenant Workspace on organizational-required route
    renders Context Required.

15. route lazy component is not part of initial eager import graph.

16. business module route chunks load independently.

17. page route failure does not destroy valid application shell.

18. API failure and route runtime failure remain distinct.

19. deep-link browser refresh works.

20. /api/* is never handled by SPA fallback.

21. missing asset is not rewritten to index.html.

22. Tenant switch navigates to dashboard.

23. Workspace switch preserves valid route.

24. Workspace switch falls back when route becomes invalid.

25. browser Back re-evaluates route under current context.

26. route loader does not create parallel raw-fetch architecture.

27. loader prefetch and component query share query identity.

28. business mutation does not automatically migrate
    to router action architecture.

29. dirty form can block route navigation.

30. sensitive values do not enter route/search state.
```

---

# 94. Code-Splitting Tests

Build/CI should also prove:

```text
Academic
HR
Dormitory
```

feature implementations are not all present in the initial application chunk.

A change that causes:

```text
module public route manifest
↓
eagerly imports all module pages
```

must be treated as a bundle regression.

---

# 95. Chunk Budget Regression

CI should compare generated asset sizes against FE-8 budgets.

Significant new common-chunk growth requires investigation.

Architecture should not accept:

```text
route is technically lazy
```

as sufficient if a shared eager dependency still pulls the majority of module implementation into the initial bundle.

---

# 96. Critical Context Race Test

Scenario:

```text
User enters Workspace X route
↓
route chunk/data begins loading
↓
Workspace switches to Y
↓
old route work completes
```

Expected:

```text
old Workspace work
cannot render as authoritative Y content
```

ADR-024/026 context generation remains the correctness boundary.

---

# 97. Lazy Load During Logout

Scenario:

```text
protected lazy route starts loading
↓
session invalidated/logout
↓
route chunk finishes
```

Expected:

```text
component code may exist in memory
but protected route cannot become active
```

Security depends on current auth/context guard, not on whether JavaScript was downloaded.

---

# 98. Downloaded Code Is Not Authorization

Important invariant:

```text
user has JavaScript chunk
≠
user has permission
```

Code splitting is a performance mechanism.

Backend authorization remains security authority.

---

# 99. Architecture Enforcement

Static analysis/tests should detect where practical:

```text
business pages eagerly imported into root router

raw module-internal route imports across boundaries

duplicate route IDs

direct raw fetch in route loaders

hardcoded role-based route guards

router reconstruction from capabilities
```

---

# 100. Architectural Invariants

If ADR-028 is accepted:

```text
Router
= React Router 8 Data Mode

Browser runtime
= createBrowserRouter

Framework Mode
= NOT Foundation v1

Hash routing
= REJECTED

Router creation
= once per SPA runtime

Route catalog
= static per frontend build

Route existence based on permission
= FORBIDDEN

Navigation visibility based on capability
= REQUIRED

Route authorization
= runtime guard

Module routes
= module-owned

Route composition
= app-owned

Runtime route service locator
= REJECTED

Backend module registry as React router
= REJECTED

Business route implementation
= lazy by default

Initial bundle
= platform + active initial route

SPA
≠ monolithic bundle

Route-level error boundary
= REQUIRED direction

Deep-link history fallback
= REQUIRED

/api fallback to SPA
= FORBIDDEN

missing static asset → index.html
= FORBIDDEN

Tenant switch
= /dashboard

Workspace switch
= preserve only still-valid route

Route loader business raw-fetch layer
= FORBIDDEN

Business server state
= TanStack Query

Router actions as default mutation layer
= NOT SELECTED

Prefetch
= selective / bounded

Aggressive all-route prefetch
= REJECTED

Dirty form navigation protection
= REQUIRED

URL
≠ authentication context authority

Downloaded route code
≠ authorization

Backend
= final security authority
```

---

# 101. Consequences

## Positive

- Routing remains compatible with pure static SPA delivery.
- Business modules can scale without one giant route implementation bundle.
- Router lifecycle is independent of Tenant/Workspace changes.
- Access Denied remains distinguishable from Not Found.
- Route definitions remain module-owned.
- TanStack Query remains the single server-state cache.
- Deep links remain first-class.
- Route-level errors can be isolated.
- Bundle boundaries align naturally with module ownership.
- Capability changes do not require router reconstruction.
- Direct URL access remains predictable.
- Future route count can grow without eagerly downloading every page.

## Costs

- Production CDN/server requires correct SPA fallback rules.
- Module route manifests require discipline to stay lightweight.
- Data Mode adds routing concepts beyond basic `<Routes>`.
- Route loaders must be prevented from becoming another fetch layer.
- Lazy chunk failures require explicit recovery UX.
- Route metadata and navigation metadata require coordination.
- Deep-link and context-race tests are mandatory.

These costs are accepted because routing is a cross-cutting application boundary rather than simply a page-switching utility.

---

# 102. Risks

## Risk — Route manifests accidentally eager-load modules

Mitigation:

```text
dynamic imports
+
bundle regression CI
+
architecture tests
```

---

## Risk — Two server-state architectures emerge

Example:

```text
React Router loaders
+
TanStack Query
```

both independently caching/fetching data.

Mitigation:

```text
loaders use shared Query definitions
+
platform/api
```

---

## Risk — Permission filtering mutates route tree

Mitigation:

```text
static route tree

runtime route policy
```

---

## Risk — Deployment breaks old lazy chunks

Mitigation:

```text
immutable hashed assets

retain previous artifacts during release transition

controlled chunk-recovery UX
```

---

## Risk — Aggressive prefetch defeats lazy loading

Mitigation:

```text
prefetch only on demonstrated intent/benefit

data prefetch requires valid context/capability
```

---

# 103. Explicit Non-Decisions

ADR-028 does not decide:

```text
exact TypeScript route descriptor interfaces

exact React Router minor/patch version

exact Suspense component design

exact URL for every future feature

exact CDN vendor

exact CDN rewrite syntax

exact safe-route function implementation

exact query-prefetch timings

exact prefetch trigger thresholds

exact chunk naming

exact Vite codeSplitting configuration

exact route transition animation

exact breadcrumb implementation
```

Those belong to TDD/implementation or later module-specific work.

---

# 104. Follow-Up Dependency

With routing and bundle boundaries established, remaining foundational question becomes:

```text
How do we prove all of this
through automated frontend tests?
```

Therefore next:

```text
ADR-029
Frontend Testing Strategy
```

ADR-029 must cover:

```text
unit tests

component tests

integration tests

E2E tests

Tenant isolation

Workspace isolation

auth/BFF flows

route guards

race conditions

OpenAPI contract drift

CI test layers
```

without duplicating backend test responsibility.

---

# ADR-028 Proposed State

```text
ADR-028 — Routing & Code-Splitting Strategy

Status:
🔒 ACCEPTED / LOCKED

Router:
React Router 8

Mode:
Data Mode

Runtime:
createBrowserRouter

Framework Mode:
❌ NOT SELECTED

Hash Router:
❌ REJECTED

Router lifecycle:
one per SPA/tab

Route tree:
static per build

Permission-filtered route tree:
❌ FORBIDDEN

Module route ownership:
✅ MODULE-LOCAL

Route composition:
✅ APP COMPOSITION ROOT

Business modules:
✅ LAZY-LOADED

SPA History fallback:
✅ REQUIRED

/api → SPA fallback:
❌ FORBIDDEN

TanStack Query:
✅ CANONICAL SERVER-STATE OWNER

Router loader direct business fetch:
❌ FORBIDDEN

Router action default mutation:
❌ NOT SELECTED

Selective prefetch:
✅ ALLOWED

Aggressive all-route prefetch:
❌ REJECTED

Tenant switch:
→ /dashboard

Workspace switch:
→ preserve route only if still valid

Dirty-form route protection:
✅ REQUIRED

Backend authorization:
✅ FINAL AUTHORITY
```
