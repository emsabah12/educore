# ADR-021 — Frontend Modular Application Architecture

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Module Ownership, Dependency Direction & Public Contracts

---

> ## Decision Summary
>
> EduCore Frontend will use a **modular frontend architecture organized by ownership and product/domain capability**, with four primary architectural areas:
>
> ```text
> app
> platform
> shared
> modules
> ```
>
> The canonical frontend source boundary will be:
>
> ```text
> frontend/
> └── src/
> ```
>
> `app` is the application composition root.
>
> `platform` owns cross-cutting EduCore runtime infrastructure such as authentication, session, tenancy, workspace context, authorization projection, API infrastructure, routing infrastructure, configuration, and observability.
>
> `shared` contains reusable **domain-neutral** UI and technical primitives.
>
> `modules` contains independently owned product/business capabilities such as Academic, HR, Dormitory, and future modules.
>
> Business modules may depend on `platform` and `shared`, but **platform and shared must never depend on business modules**.
>
> Cross-module access must use an explicit public module contract. Imports into another module's internal implementation are forbidden.
>
> Frontend modules do **not** mechanically mirror Laravel/PHP folder structures, Eloquent models, service classes, or backend module internals. They align with backend domain ownership and public API contracts while remaining independently structured for frontend responsibilities.

## Related ADR

- ADR-002 — Modular Monolith Architecture
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-019 — Dormitory Integration Boundary
- ADR-020 — Frontend Framework & Rendering Strategy

---

# 1. Context

EduCore backend is already a modular monolith.

Current backend dependency direction includes:

```text
Core      → []
Auth      → Core
User      → Core, Auth
HR        → Core, Auth
Academic  → Core, HR, Auth
PPDB      → Core
Dormitory → Core
```

The existing backend architecture establishes important principles:

```text
explicit ownership

explicit dependency direction

acyclic dependency graph

foundation must not depend on downstream modules

cross-module interaction through intentional contracts

Core must stay fundamental and non-speculative
```

Frontend Foundation has equivalent scaling concerns.

EduCore will eventually contain many application domains:

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

If frontend code is organized only by generic technical folders:

```text
components/
hooks/
pages/
services/
stores/
utils/
```

then as the application grows those folders are likely to become global dumping grounds.

For example:

```text
components/
├── StudentTable.tsx
├── EmployeeForm.tsx
├── RoomSelector.tsx
├── PaymentDialog.tsx
├── ApplicantCard.tsx
└── ...
```

Such organization makes domain ownership increasingly unclear.

EduCore therefore needs a frontend module architecture before application implementation begins.

---

# 2. Decision Drivers

The architecture must support:

```text
1. Many independently evolving business domains

2. Clear ownership

3. Explicit dependency direction

4. Large-team maintainability

5. Route/module lazy loading

6. Capability-aware module exposure

7. Context-safe Tenant/Workspace behavior

8. Shared platform authentication/context infrastructure

9. Prevention of parallel auth/authorization implementations

10. Test isolation

11. Easy architectural enforcement

12. Low accidental coupling

13. Gradual addition of future modules

14. Single-repository development

15. KISS / YAGNI
```

---

# 3. Alternatives Considered

## Option A — Global Layer-Based Architecture

Example:

```text
src/
├── components/
├── pages/
├── hooks/
├── services/
├── stores/
├── types/
└── utils/
```

### Advantages

- Familiar.
- Easy for very small applications.
- Low initial setup.
- Straightforward during prototype stage.

### Problems at EduCore scale

As domain count grows:

```text
Academic
HR
Dormitory
Finance
Library
...
```

every global folder starts containing unrelated domain concepts.

Eventually:

```text
services/
├── studentService.ts
├── employeeService.ts
├── roomService.ts
├── paymentService.ts
└── ...
```

Ownership becomes implicit.

Cross-domain imports also become difficult to detect.

### Decision

```text
REJECTED
```

as the primary architecture.

Technical layering may still exist **inside an owning module**, but not as the global organizational model.

---

# 4. Option B — Exact Backend Folder Mirroring

Example:

```text
frontend/
└── Modules/
    ├── Core/
    ├── Auth/
    ├── User/
    ├── Academic/
    └── HR/
```

with frontend structures attempting to mirror:

```text
Repositories
Services
Entities
Providers
Contracts
Http
```

from Laravel.

### Advantages

- Backend/frontend names appear symmetrical.
- Domain ownership can look familiar.
- Backend developers can locate similarly named concepts.

### Problems

Frontend and backend have different runtime responsibilities.

For example, frontend needs concepts such as:

```text
routes
navigation metadata
components
forms
query state
interaction state
error boundaries
loading boundaries
```

while Laravel has:

```text
service providers
middleware
repositories
Eloquent models
database migrations
```

Forcing structural symmetry would create framework-driven architecture rather than responsibility-driven architecture.

### Decision

```text
REJECTED
```

Frontend domain ownership should align with backend domain meaning, but physical internals do not need to mirror PHP implementation.

---

# 5. Option C — Independent Micro-Frontends

Example:

```text
Academic Frontend
HR Frontend
Dormitory Frontend
Finance Frontend
```

deployed and potentially developed independently.

### Advantages

- Independent deployment.
- Strong organizational isolation.
- Teams can release modules separately.
- Potential technology independence.

### Trade-offs

Would introduce significant complexity:

```text
runtime composition
dependency/version coordination
shared authentication
cross-app navigation
shared UI versions
observability correlation
deployment coordination
frontend contract management
```

No current EduCore requirement demonstrates a need for independent frontend deployments.

### Decision

```text
REJECTED
for current architecture
```

This can be reconsidered only if organizational/deployment pressure appears in the future.

---

# 6. Option D — Modular Frontend in a Single Application

Architecture:

```text
frontend/src/
├── app/
├── platform/
├── shared/
└── modules/
```

Modules remain part of one React application but preserve clear ownership and dependency boundaries.

### Advantages

- Clear ownership.
- One application runtime.
- One deployment artifact.
- Simple authentication/context sharing.
- Supports lazy loading.
- Supports independent feature evolution.
- Architectural boundaries can be linted/tested.
- Does not introduce micro-frontend operational complexity.

### Decision

```text
ACCEPTED
```

---

# 7. Canonical Frontend Source Boundary

The frontend application source will live under:

```text
frontend/
├── index.html
└── src/
```

Conceptually:

```text
educore/
├── Modules/
├── app/
├── docs/
├── frontend/
│   ├── index.html
│   └── src/
└── ...
```

This creates an explicit physical boundary between:

```text
Laravel application
```

and:

```text
React application
```

while keeping both inside the same repository.

This aligns with ADR-020:

```text
single repository
+
independent frontend application boundary
+
static-hostable frontend artifact
```

The exact package-manager file placement and build command composition remain implementation/TDD concerns.

---

# 8. Canonical Top-Level Frontend Architecture

```text
frontend/src/
│
├── app/
│
├── platform/
│
├── shared/
│
└── modules/
```

Each directory has a fundamentally different ownership rule.

---

# 9. `app/` — Composition Root

`app` owns application composition.

Conceptually:

```text
app/
├── bootstrap/
├── providers/
├── shell/
└── composition/
```

Responsibilities include:

```text
application startup

React root composition

platform provider composition

application shell composition

top-level module composition

global error boundary composition

runtime configuration bootstrap
```

`app` is allowed to know which modules exist because it is the composition root.

Conceptually:

```text
app
 ↓
platform
 ↓
shared

app
 ↓
modules
```

`app` must not become a business-logic layer.

Forbidden:

```text
app/services/studentService.ts

app/business/employeeRules.ts

app/domain/dormitory.ts
```

Application composition is its responsibility; business behavior is not.

---

# 10. `platform/` — EduCore Runtime Foundation

`platform` owns cross-cutting functionality that defines how an EduCore frontend operates.

Initial conceptual ownership:

```text
platform/
├── api/
├── auth/
├── session/
├── tenancy/
├── workspace/
├── authorization/
├── routing/
├── config/
└── observability/
```

Responsibilities include:

```text
API transport infrastructure

authentication runtime

authenticated bootstrap

browser session orchestration

Membership/Tenant context

Workspace context

capability projection

routing infrastructure

canonical API errors

observability infrastructure
```

These concerns are shared across business modules and carry EduCore-specific semantics.

Therefore they do **not** belong in generic `shared`.

---

# 11. Platform Ownership Rule

Platform capabilities must be:

```text
fundamental
+
cross-cutting
+
demonstrably required
```

Do not move business logic into `platform` simply because multiple modules use it.

Forbidden examples:

```text
platform/students

platform/employees

platform/dormitory

platform/payments
```

unless a future architectural decision explicitly changes their ownership.

This mirrors the backend principle:

```text
Keep Core fundamental,
stable,
and non-speculative.
```

---

# 12. `shared/` — Domain-Neutral Reuse

`shared` contains reusable components and utilities that have no business-module ownership.

Conceptually:

```text
shared/
├── ui/
├── forms/
├── errors/
├── hooks/
└── lib/
```

Examples:

```text
Button

Dialog

Input

DataTable primitive

Spinner

Skeleton

ErrorPresentation

generic date formatter

generic invariant helper
```

`shared` must not become:

```text
misc/
utils/
everything-used-twice/
```

---

# 13. Shared Eligibility Test

Before code is placed in `shared`, ask:

```text
Does this code know what
Student,
Employee,
Dormitory,
Payment,
Applicant,
Tenant capability,
or Workspace
means?
```

If yes, it probably does not belong in generic shared infrastructure.

Example:

```text
shared/ui/Button
```

is valid.

But:

```text
shared/components/StudentStatusBadge
```

is not generic merely because several Academic pages use it.

It belongs to Academic ownership.

Likewise:

```text
WorkspaceSwitcher
```

contains EduCore platform semantics.

Therefore its behavior belongs under:

```text
platform/workspace
```

rather than generic `shared`.

---

# 14. `modules/` — Product & Business Ownership

Business/product capabilities live under:

```text
modules/
```

Example direction:

```text
modules/
├── academic/
├── hr/
├── dormitory/
├── ppdb/
├── finance/
├── library/
└── attendance/
```

Not all directories need to exist initially.

A module is created only when its product capability is implemented.

---

# 15. Module Internal Architecture

ADR-021 does **not** require every module to use an identical directory tree.

A reasonable module may contain:

```text
modules/academic/
├── features/
├── routes/
├── navigation/
├── api/
├── components/
├── model/
├── tests/
└── public.ts
```

A smaller module may need only:

```text
modules/example/
├── feature/
└── public.ts
```

Rule:

```text
structure follows responsibility
```

not:

```text
every module must contain
15 empty folders
```

This follows YAGNI and avoids cosmetic Clean Architecture.

---

# 16. Features Are Module-Owned

A global top-level:

```text
src/features/
```

is **not selected**.

Reason:

Once many business modules exist, global features reintroduce ambiguous ownership.

Instead:

```text
modules/academic/features/students

modules/hr/features/employees

modules/dormitory/features/rooms
```

Feature belongs inside its owning product/module boundary.

Platform capabilities may similarly contain internal features:

```text
platform/auth/

platform/workspace/
```

without creating a second global ownership system.

---

# 17. Dependency Direction

Canonical frontend dependency direction:

```text
                    app
                  /  |  \
                 /   |   \
                ↓    ↓    ↓
          platform modules shared
              ↓       ↓
            shared  shared
```

More explicitly:

```text
app
→ platform
→ modules
→ shared

platform
→ shared

modules
→ platform
→ shared

shared
→ no platform
→ no modules
```

---

# 18. Forbidden Reverse Dependencies

The following are forbidden:

```text
platform → modules/academic

platform → modules/hr

platform → modules/dormitory

shared → platform

shared → modules/*

shared → app

modules/* → app
```

Reason:

Foundation must not depend on downstream product implementation.

Example:

```text
platform/authorization
```

may expose capability utilities.

It must never import:

```text
modules/dormitory
```

just because Dormitory uses permissions.

Correct direction:

```text
Dormitory
        ↓
Platform Authorization
```

not:

```text
Platform Authorization
        ↓
Dormitory
```

---

# 19. Business Module Dependencies

A business module may depend on another business module only when there is a demonstrated product dependency.

Default:

```text
modules/A
↛
modules/B internals
```

Cross-module dependency must never appear merely for code reuse convenience.

Example of bad reason:

```text
Academic needs a nice EmployeeSelect component
therefore import HR internal component
```

That creates hidden coupling.

Instead first determine whether the concept represents:

```text
generic UI
platform contract
shared public product capability
or actual cross-module business dependency
```

Only the last case justifies an explicit module dependency.

---

# 20. Public Module Contracts

Each module that exposes functionality outside itself must have an explicit public surface.

Canonical entrypoint:

```text
public.ts
```

Example:

```text
modules/dormitory/public.ts
```

may expose:

```text
Dormitory route registration

Dormitory navigation contribution

documented public types

documented integration contracts
```

Internal code is not public merely because TypeScript can technically import it.

---

# 21. Cross-Module Import Rule

Allowed:

```text
@modules/hr/public
```

when HR intentionally exposes an integration contract.

Forbidden:

```text
@modules/hr/features/employees/components/EmployeeSelector

@modules/hr/internal/store

@modules/hr/api/private-client
```

from another module.

This creates a stable architectural boundary.

---

# 22. Public Contract Minimalism

`public.ts` must not export everything.

Bad:

```ts
export * from "./features";
export * from "./components";
export * from "./api";
export * from "./model";
```

because it effectively removes the module boundary.

Public contracts should expose only demonstrated integration needs.

Conceptual principle:

```text
private by default
public by decision
```

---

# 23. Module Navigation Ownership

Business modules own their own navigation metadata.

Example:

```text
Academic
    ↓
academic navigation contribution
```

Core `app` or `platform` should not contain one giant file such as:

```text
navigation.ts
```

with hardcoded knowledge of every feature in:

```text
Academic
HR
Dormitory
Finance
Library
...
```

Instead:

```text
Module public contract
        ↓
navigation contribution
        ↓
application composition
```

Authorization filtering will consume platform capability projection.

Exact metadata format belongs to ADR-027/ADR-028.

---

# 24. Module Route Ownership

Likewise, each module owns its own route definitions.

Conceptually:

```text
modules/academic
        ↓
Academic routes

modules/hr
        ↓
HR routes

modules/dormitory
        ↓
Dormitory routes
```

`app` composes them.

`app` does not own internal pages for every module.

Detailed routing contracts remain ADR-028 responsibility.

---

# 25. Backend and Frontend Module Alignment

Frontend should preserve **domain ownership alignment** with backend.

Example:

```text
Student
→ Academic

Employee
→ HR

Dormitory
→ Dormitory

Tenant/Membership
→ Platform foundation

Authorization
→ Platform foundation
```

However frontend does not need to reproduce backend implementation boundaries such as:

```text
Repositories/
Services/
DTO/
Providers/
Models/
```

Frontend consumes backend through public HTTP/OpenAPI contracts.

Canonical relationship:

```text
Frontend Module
       ↓
Generated/API Contract
       ↓
Backend Public API
       ↓
Backend Owning Module
```

not:

```text
Frontend structure
must equal
PHP folder structure
```

---

# 26. Backend Module Dependency Graph Is Not Automatically Frontend Dependency Graph

Important:

```text
backend dependency
≠ automatically
frontend dependency
```

Example:

Backend Academic may depend on HR because grading resolves an Employee actor.

That does **not** automatically mean:

```text
frontend Academic
→ frontend HR internals
```

If backend provides Academic's required projection through Academic API contracts, frontend Academic can remain independent.

Frontend module dependencies are created from **frontend product integration needs**, not inferred from PHP dependency metadata.

---

# 27. Server State Ownership

Business server state belongs near the business module consuming it.

Example:

```text
Academic student queries
→ modules/academic

Dormitory room queries
→ modules/dormitory
```

Platform server state:

```text
/auth/me

/my-memberships

/my-workspaces

capabilities
```

belongs to platform concerns.

Exact TanStack Query architecture is deferred to ADR-026.

---

# 28. Authentication and Context Ownership

Business modules must never implement independent:

```text
token storage

/auth/me bootstrap

Tenant switching

Workspace restoration

capability loading

canonical API error architecture
```

Instead:

```text
Business Module
      ↓
Platform Contracts
```

This prevents architecture drift such as:

```text
Academic auth system

Dormitory auth system

Finance workspace system
```

inside one application.

---

# 29. Module Context Rule

Business modules consume authoritative current context.

Conceptually:

```text
Platform Session
      ↓
Membership/Tenant Context
      ↓
Workspace Context
      ↓
Capability Projection
      ↓
Business Module
```

Modules may react to context changes but may not redefine canonical context semantics.

---

# 30. No Giant Global Store

Module architecture explicitly rejects centralizing all business state into:

```text
globalStore = {
    auth,
    students,
    employees,
    rooms,
    payments,
    permissions,
    navigation,
    forms,
    ...
}
```

Each state type remains owned according to concern.

The exact state technology is ADR-026 responsibility.

---

# 31. No Global Service Locator

Frontend must not introduce a global service registry where modules dynamically retrieve arbitrary dependencies.

Example rejected pattern:

```text
serviceLocator.get("studentService")

serviceLocator.get("workspaceService")

serviceLocator.get("employeeService")
```

Dependencies should be statically discoverable through:

```text
imports

React composition

explicit contracts
```

This improves readability and testability.

---

# 32. Import Aliases

Architecture should support stable aliases such as:

```text
@app/*
@platform/*
@shared/*
@modules/*
```

Purpose:

```text
readable imports

boundary enforcement

avoid ../../../ chains
```

Aliases are architecture navigation helpers, not permission to bypass public module boundaries.

For example this remains forbidden:

```text
@modules/hr/internal/...
```

from Academic.

---

# 33. Boundary Enforcement

Architecture boundaries must be machine-enforceable where practical.

Minimum direction:

```text
ESLint restricted imports
+
TypeScript path configuration
+
architecture-oriented tests/checks
```

CI should eventually reject forbidden dependencies.

Example checks:

```text
shared cannot import platform

shared cannot import modules

platform cannot import modules

business module cannot import another module's internals

modules cannot import app
```

Exact tooling belongs to implementation/TDD.

---

# 34. Circular Dependency Policy

Circular architectural dependencies are forbidden.

Example:

```text
Academic
→ HR
→ Academic
```

is invalid.

Likewise:

```text
platform/auth
→ platform/workspace
→ platform/auth
```

should be redesigned if it represents an architectural dependency cycle rather than harmless low-level module evaluation.

Cross-concern orchestration should occur at an appropriate higher composition layer when necessary.

---

# 35. Composition Instead of Reverse Coupling

If multiple capabilities need orchestration:

```text
Authentication
+
Workspace
+
Capabilities
```

do not make each subsystem directly own the others.

Prefer:

```text
App / Platform Orchestrator
     ├── Session
     ├── Workspace
     └── Capabilities
```

Ownership remains separate while orchestration is explicit.

This implements the PRD's canonical context direction without creating a giant subsystem.

---

# 36. Platform Internal Boundaries

Although all platform concerns live under `platform`, they remain distinct.

Example:

```text
platform/
├── auth/
├── session/
├── tenancy/
├── workspace/
└── authorization/
```

This does **not** imply:

```text
auth = session = workspace = authorization
```

They are separate owners participating in application orchestration.

ADR-022 through ADR-027 will define their detailed contracts.

---

# 37. Error Boundaries and Module Isolation

Each major business module must be capable of participating in route/module-level error isolation.

Conceptually:

```text
Application Boundary
        ↓
Module Boundary
        ↓
Feature/Page
```

A runtime failure inside:

```text
Dormitory
```

should not automatically destroy:

```text
Application Shell

Tenant context

Workspace controls

Logout
```

provided those platform states remain trustworthy.

---

# 38. Code-Splitting Compatibility

Business-module boundaries must be compatible with lazy loading.

Conceptually:

```text
application bootstrap
     ↓
active module public entry
     ↓
lazy module implementation
```

Architecture must not create hidden eager imports from:

```text
platform
```

into every business module.

Otherwise module boundaries would exist only cosmetically while the initial bundle still loads the entire product.

Detailed bundling decisions remain ADR-028.

---

# 39. Test Ownership

Tests should generally live near their owning behavior.

Conceptually:

```text
modules/dormitory/
├── ...
└── tests/

platform/workspace/
├── ...
└── tests/
```

Cross-cutting integration tests may live under dedicated frontend integration/E2E areas.

Exact test structure and tooling belong to ADR-029.

---

# 40. Styles and UI Ownership

Shared design primitives belong to:

```text
shared/ui
```

Domain presentation belongs to owning modules.

Example:

```text
Button
→ shared/ui

Dialog
→ shared/ui

StudentStatusBadge
→ Academic

RoomOccupancyIndicator
→ Dormitory

MembershipSwitcher
→ platform/tenancy

WorkspaceSwitcher
→ platform/workspace
```

This prevents domain semantics from leaking into generic component libraries.

---

# 41. Generated API Code

Generated OpenAPI code is an infrastructure artifact, not a business module.

Its canonical ownership will be determined by ADR-025.

However business modules must not independently generate incompatible clients for the same canonical API contract.

Direction:

```text
one canonical API infrastructure
        ↓
business module API adapters/query layer
```

rather than:

```text
Academic OpenAPI system

Dormitory OpenAPI system

HR OpenAPI system
```

---

# 42. Dependency Governance Example

Correct:

```text
modules/dormitory
        │
        ├──→ platform/auth
        ├──→ platform/workspace
        ├──→ platform/authorization
        └──→ shared/ui
```

Incorrect:

```text
platform/workspace
        ↓
modules/dormitory
```

Correct cross-module case if explicitly justified:

```text
Module A
   ↓
Module B public contract
```

Incorrect:

```text
Module A
   ↓
Module B internal component/service
```

---

# 43. Architectural Ownership Matrix

| Concern                       | Owner                    |
| ----------------------------- | ------------------------ |
| React application bootstrap   | `app`                    |
| Application composition       | `app`                    |
| Application shell composition | `app`                    |
| API transport foundation      | `platform/api`           |
| Authentication                | `platform/auth`          |
| Browser session               | `platform/session`       |
| Membership/Tenant context     | `platform/tenancy`       |
| Workspace context             | `platform/workspace`     |
| Capability projection         | `platform/authorization` |
| Routing infrastructure        | `platform/routing`       |
| Observability                 | `platform/observability` |
| Generic UI primitives         | `shared/ui`              |
| Generic form primitives       | `shared/forms`           |
| Domain-neutral utilities      | `shared/lib`             |
| Student UX                    | `modules/academic`       |
| Employee UX                   | `modules/hr`             |
| Dormitory UX                  | `modules/dormitory`      |
| Future Finance UX             | `modules/finance`        |

---

# 44. Proposed Initial Structure

Canonical architectural target:

```text
frontend/
├── index.html
│
└── src/
    ├── app/
    │   ├── bootstrap/
    │   ├── composition/
    │   ├── providers/
    │   └── shell/
    │
    ├── platform/
    │   ├── api/
    │   ├── auth/
    │   ├── session/
    │   ├── tenancy/
    │   ├── workspace/
    │   ├── authorization/
    │   ├── routing/
    │   ├── config/
    │   └── observability/
    │
    ├── shared/
    │   ├── ui/
    │   ├── forms/
    │   ├── errors/
    │   └── lib/
    │
    └── modules/
        ├── academic/
        │   └── public.ts
        │
        ├── hr/
        │   └── public.ts
        │
        └── dormitory/
            └── public.ts
```

This is the architectural baseline.

Subdirectories are added only when actual implementation responsibilities require them.

No empty-folder ceremony is required.

---

# 45. Migration from Current Scaffold

Current frontend scaffold:

```text
resources/js/app.js
resources/css/app.css
resources/views/welcome.blade.php
```

does not represent an application architecture that must be preserved.

During implementation the React SPA will establish:

```text
frontend/
```

as the frontend source boundary.

Existing Laravel Vite/Tailwind configuration may be adapted rather than duplicated where practical.

Migration must not accidentally create two production frontend systems such as:

```text
resources/js application
+
frontend/src application
```

Both must not become active architectural sources of truth.

---

# 46. Repository Strategy

ADR-021 does not split EduCore into separate Git repositories.

Canonical model remains:

```text
educore repository
│
├── Laravel backend
└── React frontend
```

This gives:

```text
atomic contract changes
shared CI
single review history
simpler local development
```

while still preserving clear physical source boundaries.

---

# 47. Why This Is Not a Micro-Frontend Architecture

Although the source is modular:

```text
Academic
HR
Dormitory
```

the deployment remains:

```text
one frontend application artifact
```

for Foundation v1.

Therefore:

```text
modular frontend
≠
micro-frontends
```

No module federation, iframe composition, or independent runtime deployment is required.

---

# 48. Architecture Invariants

The following are locked if ADR-021 is accepted:

```text
Frontend source boundary
= frontend/src

Primary architectural areas
= app + platform + shared + modules

app
= composition root

platform
= EduCore cross-cutting runtime foundation

shared
= domain-neutral reusable primitives

modules
= product/business ownership

platform → business module
= forbidden

shared → platform
= forbidden

shared → business module
= forbidden

module → app
= forbidden

cross-module internal import
= forbidden

cross-module access
= explicit public contract only

circular module dependency
= forbidden

global top-level features folder
= not selected

giant global store
= rejected

global service locator
= rejected

micro-frontends
= not Foundation v1

frontend structure
≠ forced PHP folder mirror

backend domain ownership
= respected

backend dependency graph
≠ automatically frontend dependency graph

private by default
public by explicit contract
```

---

# 49. Consequences

## Positive

- Clear ownership as application size grows.
- Business modules remain understandable independently.
- Platform foundation cannot silently accumulate business dependencies.
- Generic shared code stays genuinely reusable.
- Cross-module dependencies become auditable.
- Architecture supports code splitting.
- Easier module-level testing.
- Easier onboarding.
- Future modules can be added without growing global technical folders indefinitely.
- Alignment with backend modular principles without mechanically copying PHP architecture.

## Costs

- Developers must think about ownership before creating files.
- Some seemingly convenient cross-module imports will be prohibited.
- Public contracts require intentional design.
- Lint/boundary configuration must be maintained.
- A small amount of orchestration code is needed in the application composition root.

These costs are intentional safeguards against long-term coupling.

---

# 50. Risks

## Risk — `shared` becomes a dumping ground

Mitigation:

```text
domain-neutral eligibility rule
+
review ownership
+
restricted dependencies
```

---

## Risk — `platform` becomes frontend Core god-module

Mitigation:

```text
demonstrated cross-cutting need only

separate platform concerns

no business-domain ownership
```

---

## Risk — Excessive public module APIs

Mitigation:

```text
private by default

minimal public.ts

no blanket export *
```

---

## Risk — Artificial module boundaries cause duplication

Some duplication may initially be preferable to premature cross-domain coupling.

When repeated behavior demonstrates a stable shared abstraction:

```text
duplication
↓
architecture review
↓
extract correct owner
```

rather than immediately placing code into global shared infrastructure.

---

# 51. Explicit Non-Decisions

ADR-021 does not decide:

```text
credential storage

exact authentication implementation

TanStack Query configuration

query-key design

client state library

OpenAPI generator

route declaration API

route metadata format

permission guard API

form library

UI component library

testing framework

CSS design system details

observability vendor

exact build/package-manager layout
```

These belong to later ADR/TDD work.

---

# 52. Follow-Up ADRs

Next decisions remain:

```text
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

---

# 53. References

Project architecture:

- EduCore Frontend Foundation PRD — FE-0 through FE-9
- ADR-002 — Modular Monolith Architecture
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-017 — Module Runtime & Bootstrap Contract
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-019 — Dormitory Integration Boundary
- ADR-020 — Frontend Framework & Rendering Strategy
- `docs/architecture/architecture-principles.md`
- `docs/architecture/current-architecture.md`
- `docs/architecture/folder-structure.md`
- `docs/architecture/adr/README.md`

---

# ADR-021 Proposed State

```text
ADR-021 — Frontend Modular Application Architecture

Status:
🔒 ACCEPTED / LOCKED

Decision:

Repository
→ single EduCore repository

Frontend source
→ frontend/src

Architecture
→ app
→ platform
→ shared
→ modules

Business module ownership
→ module-local

Global features folder
→ NOT SELECTED

Cross-module internals
→ FORBIDDEN

Public integration
→ explicit public.ts contract

Platform → business module
→ FORBIDDEN

Shared → platform/modules
→ FORBIDDEN

Circular dependencies
→ FORBIDDEN

Micro-frontends
→ NOT Foundation v1

Backend folder mirroring
→ NOT REQUIRED
```
