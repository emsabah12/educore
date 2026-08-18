# ADR-020 — Frontend Framework & Rendering Strategy

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Framework & Rendering Strategy

---

> ## Decision Summary
>
> EduCore Frontend Foundation will use **React 19 + TypeScript with Vite 8 as a client-side rendered Single-Page Application (SPA)**.
>
> The authenticated EduCore application will be designed as an **API-first frontend** consuming the canonical Laravel `/api/v1` contract rather than using Laravel controllers to render application pages.
>
> The production frontend must be capable of being built as **static assets suitable for CDN/edge delivery**. Server-side rendering is not part of Frontend Foundation v1 because the current product is an authenticated operational application and has no SEO or public-content requirement that justifies SSR complexity.
>
> Vue + Vite remains technically viable but is not selected in order to preserve the already-agreed React direction and avoid maintaining multiple frontend paradigms. Next.js/React SSR is not selected because its server/static rendering capabilities are not currently required. Laravel + Inertia is not selected because its server-driven routing/data model conflicts with EduCore's locked API-first/OpenAPI-oriented frontend boundary.

## Related ADR

- ADR-002 — Modular Monolith Architecture
- ADR-015 — Authentication Token & Request Context
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-019 — Dormitory Integration Boundary

---

# 1. Context

EduCore backend foundation is already implemented as a Laravel modular monolith with a canonical versioned HTTP API.

The Frontend Foundation PRD requires:

```text
API-first architecture

SPA interaction model

TypeScript strict

OpenAPI-generated contracts/client

client-side Tenant/Membership context

client-side Workspace context

capability-aware navigation

per-tab Tenant isolation

context-safe server-state caching

business-module lazy loading

static/CDN-oriented delivery

no SSR requirement

no SEO requirement for the authenticated application
```

The repository currently contains:

```text
Vite 8
Tailwind CSS 4
laravel-vite-plugin
```

but does not yet contain React application code.

Current frontend state is effectively:

```text
resources/views/welcome.blade.php
resources/js/app.js
resources/css/app.css
```

Therefore this ADR establishes the actual frontend application architecture before implementation begins.

React 19 is a stable React major release, and React supports a browser-managed application root using `createRoot`.

Vite produces optimized production assets and supports builds intended for static hosting, which matches the PRD's CDN/static delivery direction.

---

# 2. Decision Drivers

The framework/rendering strategy must optimize for EduCore's actual requirements rather than general framework popularity.

Primary decision drivers are:

```text
1. API-first backend boundary

2. Rich authenticated application UX

3. Tenant/Membership context isolation

4. Workspace/context switching

5. Capability-aware dynamic UI

6. Large modular application structure

7. Route/module code splitting

8. Context-aware server-state caching

9. Static/CDN deployment

10. Low unnecessary infrastructure complexity

11. TypeScript ecosystem

12. Long-term maintainability
```

SEO and public-page server rendering are not primary decision drivers for Frontend Foundation v1.

---

# 3. Alternatives Considered

## Option A — React + TypeScript + Vite SPA

Architecture:

```text
Browser
   ↓
React SPA
   ↓
Frontend Platform Layer
   ↓
Generated API Client
   ↓
Laravel /api/v1
```

Rendering:

```text
Client-Side Rendering
```

Deployment:

```text
CI
 ↓
Vite Build
 ↓
Static Assets
 ↓
CDN / Edge
```

React manages the browser application tree, while routing, server-state, context orchestration, and authorization UX are implemented as explicit frontend concerns.

React's browser API supports mounting an application directly into a DOM root without requiring server-generated React markup.

### Advantages

- Direct alignment with API-first architecture.
- Clean separation between Laravel backend and frontend presentation.
- Strong fit for long-lived authenticated application sessions.
- Compatible with per-tab runtime context.
- Suitable for modular lazy-loaded business features.
- Static production artifact.
- No required Node.js application server in production.
- Preserves existing Vite direction.
- Aligns with the already-agreed React frontend direction.

### Trade-offs

- Initial browser load relies on JavaScript.
- Authentication/bootstrap loading UX must be designed carefully.
- SEO for application routes is limited compared with SSR.
- Frontend owns more routing, cache, and application-state orchestration.

Those trade-offs are acceptable for the authenticated EduCore application.

---

# 4. Option B — React with SSR / Full-Stack React Framework

Representative architecture:

```text
Browser
   ↓
React Framework
   ↓
SSR / Server Components / Static Rendering
   ↓
Laravel API
```

A framework such as Next.js can provide static export as well as server/client rendering models. Current Next.js documentation explicitly supports static-export deployment and server/client component architectures.

Therefore this option is technically viable.

However, EduCore currently has no requirement for:

```text
SEO-sensitive authenticated routes

server-rendered application pages

React server runtime

server-side data loading

public content rendering

edge-rendered application pages
```

Adding a separate full-stack frontend framework would therefore introduce another application/runtime architecture before there is a requirement for it.

### Advantages

- SSR available if future public pages require it.
- Strong full-stack React capabilities.
- Static generation also possible.
- Advanced rendering strategies available.

### Trade-offs

- Additional framework/runtime concepts.
- Increased deployment and operational surface if server rendering is enabled.
- Risk of duplicating responsibilities already owned by Laravel API.
- Server/client boundary complexity not currently required.
- Greater architectural coupling to framework-specific rendering semantics.

### Decision

```text
NOT SELECTED
for Frontend Foundation v1
```

This is not a claim that SSR frameworks cannot satisfy EduCore.

The decision is:

```text
current requirement
does not justify
additional rendering architecture
```

---

# 5. Option C — Vue + TypeScript + Vite SPA

Architecture:

```text
Browser
   ↓
Vue SPA
   ↓
Laravel /api/v1
```

Vue explicitly supports SPA applications and recommends Vue Router for most SPA routing use cases.

Therefore Vue is a technically valid alternative.

It could satisfy:

```text
SPA
TypeScript
Vite
API-first architecture
static deployment
modular features
client-side routing
```

### Advantages

- Strong SPA model.
- Vite-native tooling.
- Good TypeScript support.
- Static/CDN deployment is straightforward.
- Suitable for EduCore's application class.

### Trade-offs

The primary disadvantage is **not a technical deficiency**.

Selecting Vue now would change the frontend direction that has already been established during PRD work:

```text
React
+
TypeScript
+
Vite
```

Changing framework at this stage would require a product/architecture reason strong enough to justify that change.

No such requirement has been identified.

### Decision

```text
VALID ALTERNATIVE

NOT SELECTED
```

React is selected through architectural alignment and standardization, not because Vue is incapable of supporting EduCore.

---

# 6. Option D — Laravel + Inertia

Conceptually:

```text
Browser
   ↓
Inertia React/Vue pages
   ↓
Laravel routes/controllers
   ↓
Application services
```

Inertia's own architecture centers server-defined routes and server-side controllers, and its documentation explicitly states that an API is not required for the normal Inertia application model.

That model is valuable for server-driven Laravel applications.

However EduCore has already established:

```text
/api/v1
        ↓
canonical application contract

OpenAPI
        ↓
frontend contract source

SPA router
        ↓
frontend responsibility
```

Using Inertia as the primary application boundary would therefore pull routing and page-data orchestration back toward Laravel.

### Advantages

- Excellent Laravel integration.
- Simpler server-driven application development.
- Laravel controllers remain central to page delivery.
- Lower conceptual separation for traditional Laravel teams.

### Trade-offs for EduCore

- Conflicts with API-first frontend/backend boundary.
- Reduces value of canonical OpenAPI-driven client architecture.
- Makes server-side routes part of frontend navigation architecture.
- Couples frontend page composition more directly to Laravel.
- Weakens the intended frontend/backend deployment separation.

### Decision

```text
REJECTED
for the primary EduCore application
```

Inertia may be appropriate for another product architecture, but it does not match the already locked EduCore boundary.

---

# 7. Architecture-Fit Comparison

This comparison evaluates **fit for EduCore**, not overall framework quality.

| Criterion                    | React + Vite SPA | Vue + Vite SPA | React SSR Framework | Laravel + Inertia |
| ---------------------------- | ---------------- | -------------- | ------------------- | ----------------- |
| API-first `/api/v1`          | Excellent        | Excellent      | Good                | Weak              |
| OpenAPI client boundary      | Excellent        | Excellent      | Good                | Weak              |
| Static/CDN delivery          | Excellent        | Excellent      | Good–Excellent      | Weak              |
| Per-tab runtime context      | Excellent        | Excellent      | Excellent           | Good              |
| Large interactive admin SPA  | Excellent        | Excellent      | Excellent           | Good              |
| Independent client routing   | Excellent        | Excellent      | Excellent           | Weak              |
| No additional server runtime | Excellent        | Excellent      | Depends on mode     | Laravel-bound     |
| Existing Vite alignment      | Excellent        | Excellent      | Low                 | Good              |
| Locked PRD direction         | Excellent        | Good           | Moderate            | Low               |
| Current SSR requirement fit  | Excellent        | Excellent      | Over-capable        | Over-coupled      |

Result:

```text
React + Vite SPA
= best architecture fit
for current EduCore requirements
```

---

# 8. Selected Framework

EduCore selects:

```text
React 19
+
TypeScript
```

React major version:

```text
19
```

is the architecture baseline.

The exact supported minor/patch version must be pinned through:

```text
package.json
+
package lock
```

during implementation rather than hardcoded permanently into the ADR.

This permits normal compatible React 19 maintenance upgrades without requiring a new ADR.

React 19 is an established stable major release; subsequent React 19.x versions have continued within that major line.

---

# 9. Rendering Strategy

Canonical Frontend Foundation rendering strategy:

```text
Client-Side Rendering
        +
Single-Page Application
```

Application startup concept:

```text
Static HTML Shell
      ↓
JavaScript Bootstrap
      ↓
React Root
      ↓
Frontend Application Bootstrap
      ↓
Authentication Bootstrap
      ↓
Tenant / Workspace Context
      ↓
Capabilities
      ↓
Application Routes
```

The browser owns interactive application rendering after bootstrap.

---

# 10. Production Deployment Boundary

Canonical target:

```text
                ┌────────────────┐
Browser ───────→│ CDN / Edge     │
                │                │
                │ HTML/CSS/JS    │
                └────────────────┘
                         │
                         │ API requests
                         ▼
                ┌────────────────┐
                │ Laravel API    │
                │ /api/v1        │
                └────────────────┘
```

Frontend production artifacts must therefore be capable of being deployed as:

```text
static immutable build artifacts
```

Laravel remains:

```text
API
domain/application services
persistence
authentication verification
authorization authority
```

Laravel must not be required to render every authenticated React application route.

---

# 11. Current Laravel Vite Integration

The repository currently uses:

```text
laravel-vite-plugin
```

and Blade as the existing asset entry.

Laravel officially supports Vite for bundling application CSS and JavaScript.

This integration may be used during migration and local development.

However the architectural target is:

```text
frontend production artifact
must not depend on
Laravel page rendering
```

Exact Vite entry files, output paths, CDN configuration, and development proxy configuration belong to TDD/implementation.

---

# 12. Routing Boundary

ADR-020 establishes only that routing is:

```text
client-side SPA routing
```

React Router remains the selected routing direction.

React Router supports client-side declarative routing and navigation around a React application.

However ADR-020 does **not** decide:

```text
Declarative Mode
vs
Data Mode
vs
Framework Mode

route file organization

route metadata structure

lazy route composition

authorization guard composition
```

Those decisions belong to:

```text
ADR-028 — Routing & Code-Splitting Strategy
```

---

# 13. Server-State Boundary

ADR-020 does not make React responsible for canonical backend data ownership.

Canonical separation remains:

```text
React
→ presentation / component runtime

TanStack Query
→ server-state orchestration

Laravel
→ canonical domain state
```

Exact server/client-state ownership remains deferred to:

```text
ADR-026
Server-State & Client-State Ownership
```

---

# 14. Authentication Boundary

Framework selection must not dictate authentication semantics.

React must consume the authentication architecture rather than define it.

Therefore this ADR does not decide:

```text
memory-only credential

sessionStorage

HttpOnly cookie

BFF/session broker

hybrid credential architecture
```

Those choices remain owned by:

```text
ADR-022
Authentication Credential Storage
& Browser Session Isolation
```

---

# 15. SSR Policy

Frontend Foundation v1 does not implement:

```text
SSR
React Server Components as application architecture
server-rendered authenticated routes
```

This does not permanently prohibit those techniques.

If a future requirement introduces:

```text
public marketing pages
public catalog pages
SEO-critical pages
social-link previews
public content discovery
```

the preferred approach is first to evaluate whether that requirement should become:

```text
a separate public rendering surface
```

rather than automatically converting the authenticated EduCore application to SSR.

Any such change requires a new ADR or superseding ADR.

---

# 16. Code-Splitting Consequence

Because the selected model is SPA:

```text
SPA
≠
one giant bundle
```

The application must support:

```text
route-level lazy loading
+
business-module lazy loading
```

Example:

```text
Initial Application
│
├── Platform Shell
├── Authentication
└── Active Route

Academic
→ loaded when required

HR
→ loaded when required

Dormitory
→ loaded when required
```

Detailed code-splitting policy belongs to ADR-028.

---

# 17. Repository Consequence

Frontend remains initially within the EduCore repository.

Conceptually:

```text
educore/
├── app/
├── Modules/
├── docs/
│
└── frontend source
```

The exact frontend folder structure is intentionally not established here.

That decision belongs to:

```text
ADR-021
Frontend Modular Application Architecture
```

This prevents ADR-020 from prematurely locking implementation-level directory organization.

---

# 18. Consequences

## Positive

- Clear Laravel API / frontend presentation boundary.
- Static/CDN-oriented scalability.
- No mandatory frontend application server.
- Existing Vite investment is preserved.
- Suitable for rich authenticated application workflows.
- Clean fit for OpenAPI-generated clients.
- Supports independent business-module loading.
- React framework direction becomes explicit before implementation.
- Rendering architecture remains simpler than introducing SSR without requirement.

## Trade-offs

- Initial application requires JavaScript.
- Browser owns substantial application orchestration.
- Authentication bootstrap must show an intentional loading state.
- Client routing must be configured for SPA fallback.
- Frontend error monitoring becomes important.
- Route/module bundle discipline is required.
- Public SEO-sensitive functionality may require a separate strategy later.

---

# 19. Architectural Invariants

```text
Frontend framework
= React 19

Language
= TypeScript strict

Build system
= Vite 8

Authenticated application
= SPA

Primary rendering
= client-side rendering

Laravel
= API/backend authority

Laravel
≠ React page renderer

Production frontend
= static-hostable artifact

Frontend delivery
= CDN/edge capable

API contract
= /api/v1 + OpenAPI

SSR
= not Foundation v1 requirement

Next.js/full-stack React framework
= not Foundation v1

Inertia
= not primary application architecture

Vue
= valid alternative but not selected

React choice
≠ permission/security authority

Business module
= independently lazy-loadable direction
```

---

# 20. Explicit Non-Decisions

ADR-020 intentionally does not decide:

```text
frontend folder structure
state store technology
credential storage
CSRF implementation
TanStack Query configuration
query-key factories
workspace implementation
route hierarchy
route authorization API
testing frameworks
CSP details
observability vendor
component library
form library
OpenAPI generator tool
```

These decisions belong to subsequent ADR/TDD work.

---

# 21. Follow-Up ADR Dependencies

```text
ADR-020
Frontend Framework & Rendering Strategy
        ↓
ADR-021
Frontend Modular Application Architecture
        ↓
ADR-022
Authentication Credential Storage
& Browser Session Isolation
        ↓
ADR-023
Tenant / Membership Context Switching
        ↓
ADR-024
Workspace / Organizational Context Management
        ↓
ADR-025
API Client / OpenAPI / Errors
        ↓
ADR-026
Server-State & Client-State Ownership
        ↓
ADR-027
Capability-Aware Navigation
        ↓
ADR-028
Routing & Code-Splitting
```

ADR-022 remains a mandatory gate before authentication implementation.

---

# 22. References

Project:

- EduCore Frontend Foundation PRD — FE-0 through FE-9
- `package.json`
- `vite.config.js`
- `docs/architecture/current-architecture.md`
- `docs/architecture/architecture-principles.md`
- `docs/architecture/adr/README.md`
- ADR-002
- ADR-015
- ADR-017
- ADR-018
- ADR-019

External architecture references:

- React — Client React DOM APIs
- React — React 19 release documentation
- Vite — Getting Started / Production Build
- Laravel — Asset Bundling with Vite
- React Router — Routing modes
- Vue — SPA and routing documentation
- Next.js — Static Export and rendering documentation
- Inertia — Architecture and routing documentation

---

# ADR-020 Proposed State

```text
ADR-020 — Frontend Framework & Rendering Strategy

Status:
🔒 ACCEPTED / LOCKED

Decision:
React 19
+
TypeScript strict
+
Vite 8
+
Client-Side SPA
+
Static/CDN-oriented deployment

SSR:
NOT REQUIRED for Foundation v1

Inertia:
NOT SELECTED

Next.js / React SSR Framework:
NOT SELECTED

Vue + Vite:
VALID ALTERNATIVE
but NOT SELECTED
```
