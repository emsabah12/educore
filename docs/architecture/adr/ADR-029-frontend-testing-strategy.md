# ADR-029 — Frontend Testing Strategy

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Automated Testing Layers, Tooling, Contract Verification, Browser E2E & Isolation

---

> ## Decision Summary
>
> EduCore Frontend will use a **multi-layer automated testing strategy**:
>
> ```text
> Static / Architecture Gates
>         ↓
> Unit Tests
>         ↓
> Component Tests
>         ↓
> Frontend Integration Tests
>         ↓
> Contract / Build Tests
>         ↓
> Browser End-to-End Tests
> ```
>
> Canonical frontend test tooling:
>
> ```text
> Vitest
> +
> React Testing Library
> +
> Mock Service Worker (MSW)
> +
> Playwright
> ```
>
> Responsibilities:
>
> ```text
> Vitest
> → unit + component + frontend integration runner
>
> React Testing Library
> → user-observable component behavior
>
> MSW
> → HTTP-boundary mocking for frontend tests
>
> Playwright
> → real-browser E2E against running EduCore stack
>
> PHPUnit
> → backend/domain/API/BFF/OpenAPI authority
> ```
>
> Vitest is selected because it is Vite-native and reuses Vite's transform/configuration model, matching ADR-020's Vite architecture.
>
> Testing Library is selected for component interaction because its design explicitly prioritizes tests that resemble how users interact with the application rather than implementation details.
>
> Playwright is selected for E2E because it supports Chromium, Firefox and WebKit, built-in auto-waiting, browser assertions, tracing and parallel test execution.
>
> MSW is selected as the frontend API mocking boundary because it intercepts network requests and supports both browser and Node.js test environments, allowing tests to exercise the same HTTP-facing application layer instead of replacing the API client itself.
>
> Critical authentication, Tenant switching, Workspace switching, CSRF, browser-session, and multi-tab isolation tests MUST run against the **real Laravel backend/BFF** in Playwright E2E.
>
> MSW MUST NOT be used as evidence that security-sensitive browser/backend integration works correctly.
>
> Frontend tests MUST prove:
>
> ```text
> behavior
> context isolation
> recovery
> race safety
> contract consumption
> accessibility-critical interaction
> ```
>
> rather than duplicate backend domain algorithms already proven by PHPUnit.
>
> Global line coverage percentage is **not a release-quality definition**.
>
> Critical architectural behavior is mandatory regardless of aggregate coverage.

---

# 1. Context

EduCore backend already has significant automated test coverage using PHPUnit.

Current repository includes tests for:

```text
OpenAPI integrity

OpenAPI operation contracts

OpenAPI route coverage

validation error contracts

authentication

token lifecycle

Tenant context injection

Membership switching

Workspace discovery

capability projection

authorization

multi-tenancy isolation

organizational context

Dormitory persistence

concurrency

domain invariants
```

The frontend test stack has not yet been introduced.

Current `package.json` contains only build/development tooling around:

```text
Vite
Tailwind
laravel-vite-plugin
```

Therefore frontend testing architecture can be established before implementation rather than retrofitted later.

---

# 2. Testing Objective

The purpose of frontend testing is not:

```text
prove React renders something
```

or:

```text
maximize coverage %
```

The purpose is to provide evidence that the browser application preserves EduCore's locked architectural invariants.

Especially:

```text
authentication safety

Tenant isolation

Workspace isolation

capability behavior

API contract handling

route protection

race-condition safety

error recovery

accessible user interaction
```

---

# 3. Testing Philosophy

Canonical principle:

```text
Test behavior at the lowest layer
that provides sufficient confidence.
```

Not:

```text
everything unit tested
```

and not:

```text
everything E2E tested
```

The selected architecture uses multiple complementary layers.

---

# 4. Why Not E2E-Only

An E2E-only strategy would provide realistic browser confidence but would make:

```text
small pure policies

query-key logic

context reducers/state machines

error normalization

permission evaluation
```

slow and difficult to diagnose.

It would also cause a large number of expensive browser/database scenarios.

### Decision

```text
E2E-only
❌ REJECTED
```

---

# 5. Why Not Unit-Only

Unit tests cannot prove:

```text
browser cookie behavior

SPA routing

multi-tab isolation

real BFF integration

CSRF protection

deep-link refresh

real Laravel errors

CDN/history behavior
```

### Decision

```text
Unit-only
❌ REJECTED
```

---

# 6. Selected Model

EduCore uses complementary layers:

```text
Layer 0
Static / Architecture

Layer 1
Unit

Layer 2
Component

Layer 3
Frontend Integration

Layer 4
Contract / Build

Layer 5
Browser E2E
```

Each layer has explicit responsibility.

---

# 7. Layer 0 — Static & Architecture Gates

These are not runtime tests but are part of the testing strategy.

Required:

```text
TypeScript strict typecheck

ESLint

forbidden import checks

module-boundary checks

OpenAPI generation drift checks

route-ID uniqueness checks

permission-reference checks where practical

production build

bundle checks
```

Purpose:

```text
reject invalid architecture
before runtime tests are needed
```

---

# 8. Static Type Safety

Required CI command conceptually includes:

```text
tsc --noEmit
```

or equivalent project type checking.

No production build may rely only on Vite transpilation without separate TypeScript validation.

---

# 9. Architecture Tests

ADR-021 boundaries must be machine-verifiable where practical.

Examples:

```text
shared → modules
❌

platform → business module
❌

module A → module B internals
❌

module → app
❌
```

Architecture tests/lint rules are release gates.

---

# 10. Authorization Architecture Checks

Where practical, static analysis should identify suspicious authorization code such as:

```text
role === "admin"

role.name === ...

isGlobalSuperadmin ? true : ...

permission wildcard matching
```

The check must distinguish authorization logic from legitimate Role Administration screens.

---

# 11. API Architecture Checks

Static checks should reject where practical:

```text
raw EduCore fetch outside platform/api

manual Authorization header

manual Membership locator injection

manual Workspace locator injection

localStorage/sessionStorage bearer usage
```

These checks complement runtime tests.

---

# 12. Layer 1 — Unit Tests

Unit tests target deterministic logic without needing a rendered application.

Primary examples:

```text
permission evaluation

context state transitions

query-key factories

error classification

safe redirect validation

route-policy evaluation

restoration-hint parsing

cache-scope computation

parameter normalization

state-machine reducers

business presentation helpers
```

---

# 13. Unit Tests Must Remain Small

Unit tests should not bootstrap:

```text
full router

QueryClient

MSW

Laravel

browser
```

unless the behavior under test actually requires those dependencies.

The unit boundary should remain explicit.

---

# 14. Unit Test Example — Permission

Given:

```text
permissions = [
  "dormitory.rooms.view"
]
```

test:

```text
has("dormitory.rooms.view")
→ true

has("dormitory.rooms.manage")
→ false

has("dormitory.*")
→ false
```

Also:

```text
is_global_superadmin = true
permissions = []
```

must still produce:

```text
has("dormitory.rooms.manage")
→ false
```

per ADR-027.

---

# 15. Unit Test Example — Query Identity

Given:

```text
Tenant A / Workspace X
```

and:

```text
Tenant B / Workspace X
```

their query-key factories must produce different identities.

Likewise:

```text
Tenant A / Workspace X
```

and:

```text
Tenant A / Workspace Y
```

must differ for Workspace-scoped operations.

---

# 16. Layer 2 — Component Tests

Component tests validate rendered behavior through user-observable semantics.

Canonical tools:

```text
Vitest
+
React Testing Library
```

Testing Library explicitly recommends user-facing queries and behavior over testing internal component implementation.

---

# 17. Component Test Philosophy

Prefer assertions such as:

```text
user sees "Delete"

button is disabled

accessible error is announced

dialog opens after user action

unauthorized action is absent
```

instead of:

```text
component.state.canDelete === false

internal hook called 3 times

CSS class implementation equals X
```

unless implementation detail itself is the required contract.

---

# 18. Selector Policy

Preferred query priority:

```text
accessible role

accessible name

label

visible user text

semantic value
```

`data-testid` is allowed only as a stable escape hatch when semantic queries are impractical.

Testing Library explicitly treats test IDs as lower-priority than user-facing queries.

---

# 19. Component Test Examples

Examples include:

```text
Membership switcher

Workspace switcher

Capability-aware button

Navigation group

Validation form

Access Denied state

Context Required state

Error presentation

Dirty-form confirmation
```

---

# 20. Authorization Component Test

Example:

```text
permission absent
↓
Delete button
must not exist
```

Versus:

```text
permission exists
+
business condition closed
↓
Delete button visible
+
disabled
+
reason available accessibly
```

---

# 21. Accessibility in Component Tests

Shared platform components should include automated assertions for:

```text
labels

roles

focus behavior

dialog semantics

error associations

keyboard interaction
```

where applicable.

Accessibility testing is not deferred entirely to manual QA.

---

# 22. Layer 3 — Frontend Integration Tests

Frontend Integration Tests combine multiple frontend infrastructure layers without running the complete production backend.

Typical stack:

```text
React
+
Router
+
QueryClient
+
Platform contexts
+
MSW
```

These tests validate orchestration.

---

# 23. Why MSW

MSW intercepts outgoing network requests rather than requiring components to replace the API layer with custom mocks, and supports Node.js test environments.

This means tests can exercise:

```text
module query
↓
TanStack Query
↓
platform/api
↓
HTTP request
↓
MSW
```

instead of mocking:

```text
useStudentsQuery()
```

itself.

---

# 24. Mock at HTTP Boundary

Preferred:

```text
MSW
→ GET /api/v1/user/my-workspaces
```

Rejected default:

```text
mock useWorkspace()

mock QueryClient internals

mock permission hook internals
```

Mocking too high in the stack prevents integration defects from being detected.

---

# 25. Strict Unhandled Request Policy

Frontend integration tests should fail when an unexpected EduCore HTTP request is not handled.

Conceptually:

```text
unhandled API request
→ TEST FAILURE
```

not:

```text
silently call developer's network/backend
```

This keeps tests deterministic and reveals accidental API calls.

---

# 26. No Real Production Network in Vitest

Unit/component/frontend integration tests MUST NOT depend on:

```text
staging

production

developer-local shared API
```

Their network is deterministic.

Real-stack behavior belongs to Playwright E2E.

---

# 27. Integration Test — Bootstrap

Test:

```text
Browser session considered valid
↓
/auth/me
↓
/my-memberships
↓
/my-workspaces
↓
capabilities
↓
application READY
```

Assertions include:

```text
correct shell

correct Tenant

Tenant Workspace

capability-aware navigation
```

---

# 28. Integration Test — Bootstrap Error

For:

```text
/auth/me
→ authentication context invalid
```

frontend should:

```text
clear protected state
↓
show login
```

But:

```text
network failure
```

must NOT be treated as authentication failure.

---

# 29. Integration Test — Tenant Switch

MSW may model:

```text
Membership A
↓
switch B
↓
/auth/me B
↓
workspaces B
↓
capabilities B
```

to test frontend orchestration.

However this test does not prove BFF credential security.

That requires E2E/backend tests.

---

# 30. Integration Test — Failed Tenant Switch

Scenario:

```text
A active
↓
switch B
↓
MEMBERSHIP_SWITCH_DENIED
```

Expected:

```text
A preserved

B not committed

membership catalog refreshed

no logout
```

---

# 31. Integration Test — Workspace Recovery

Scenario:

```text
Workspace X active
↓
request
↓
ORGANIZATIONAL_CONTEXT_DENIED
```

Expected:

```text
X stale
↓
clear hint
↓
/my-workspaces
↓
TENANT
↓
Tenant capabilities
↓
safe route
```

No retry with X.

---

# 32. Integration Test — Authorization Reconciliation

Scenario:

```text
capability says permission exists
↓
mutation
↓
AUTHORIZATION_DENIED
```

Expected:

```text
mutation remains failed

no retry

capability refresh

UI re-evaluates
```

---

# 33. Integration Test — Old Context Response

MSW test handlers may deliberately delay requests.

Example:

```text
Students A request
↓ delayed

Tenant switches B
↓
Students B ready

Students A resolves
```

Assertion:

```text
A result cannot update B UI
```

This is a mandatory race test.

---

# 34. Controlled Time

Tests involving:

```text
retry

debounce

polling

timeout

session UI
```

should use deterministic/fake time where appropriate rather than arbitrary real sleeps.

Rejected:

```text
await sleep(5000)
```

inside deterministic frontend tests.

---

# 35. Layer 4 — Contract Tests

Contract testing spans repository/backend/frontend boundaries.

Canonical contract:

```text
docs/api/openapi.yaml
```

Backend already has OpenAPI integrity/operation/route/schema tests.

ADR-029 retains those backend tests as authoritative.

Frontend adds:

```text
generated-client drift validation

generated type compilation

critical fixture/schema compatibility tests
```

---

# 36. OpenAPI Source of Truth

Frontend does not create a separate mock schema.

MSW fixtures and factories should be based on canonical generated types where practical.

This prevents:

```text
mock API contract
≠ real API contract
```

from silently growing.

---

# 37. Generated Client Drift Test

CI:

```text
OpenAPI validation
↓
code generation
↓
git diff
```

If generated frontend contract differs from committed source:

```text
FAIL
```

per ADR-025.

---

# 38. Backend Contract Tests Remain Backend-Owned

Frontend must not recreate PHPUnit assertions proving:

```text
Membership belongs to Person

Tenant isolation SQL

token cryptography

RBAC persistence

Dormitory capacity lock
```

Those remain backend responsibilities.

Frontend consumes their public effects.

---

# 39. Frontend Contract Responsibility

Frontend must prove that it correctly handles:

```text
documented response

documented machine code

documented validation structure

context metadata

BrowserSession transport

Membership locator

Workspace locator
```

---

# 40. Browser-Safe Authentication Contract

Backend tests must prove:

```text
browser login never returns bearer

browser switch never returns bearer

browser logout destroys session

session cookie hardened

Membership locator validated

CSRF enforcement
```

Frontend integration tests prove that the SPA consumes those contracts correctly.

---

# 41. Layer 5 — Browser End-to-End

Canonical E2E runner:

```text
Playwright
```

Playwright supports multiple major browser engines and provides auto-waiting, assertions and tracing suitable for browser-level flows.

---

# 42. E2E Uses Real Stack

Critical E2E tests run:

```text
real browser
↓
built/running React SPA
↓
real BrowserSession/BFF
↓
real Laravel application
↓
real test database
```

Not:

```text
Playwright
↓
MSW fake backend
```

for the critical security flows.

---

# 43. E2E Environment

E2E runs against isolated:

```text
test environment
```

with deterministic database fixtures.

It must never target:

```text
production
```

for mutation/security flows.

---

# 44. Test Data Isolation

Each E2E worker/test group must have deterministic test identities and data isolation.

Tests must not depend on:

```text
whatever data previous test left behind
```

Parallel execution must not cause Tenant/User collisions.

---

# 45. Database Reset Strategy

Exact implementation is TDD/backend-test infrastructure concern, but tests require a predictable reset/fixture mechanism.

Possible approaches include:

```text
transaction/reset

database refresh

namespace-specific fixtures
```

provided parallel correctness is maintained.

---

# 46. Critical E2E — Login

Must prove:

```text
login form
↓
browser session established
↓
bearer absent from browser-visible response/storage
↓
/auth/me bootstrap
↓
authenticated shell
```

---

# 47. Critical E2E — Bearer Absence

Browser-level test must inspect appropriate browser-visible surfaces to verify canonical bearer is not stored in:

```text
localStorage

sessionStorage

IndexedDB

URL
```

and is not exposed in safe browser-auth responses.

Backend tests remain responsible for proving server-side bearer custody in detail.

---

# 48. Critical E2E — Reload

Scenario:

```text
login
↓
Tenant A ready
↓
browser reload
```

Expected:

```text
browser session survives
↓
authoritative bootstrap
↓
Tenant A restored
```

without persistent protected Query cache.

---

# 49. Critical E2E — Multi-Tab Tenant Isolation

Mandatory:

```text
Tab A
→ Tenant A

Tab B
→ Tenant B
```

Both remain operational simultaneously.

Then:

```text
Tab B switches Tenant
```

must not change Tab A.

This is one of the most important architecture E2E tests in EduCore.

---

# 50. Critical E2E — Multi-Tab Workspace Isolation

Given same Membership:

```text
Tab A → Workspace X

Tab B → Workspace Y
```

changing Tab B Workspace must not alter Tab A.

---

# 51. Critical E2E — Old Token Semantics

Backend test proves old canonical token remains valid after Membership switch.

Browser E2E proves the user-visible consequence:

```text
Tab A remains functional
while
Tab B switches Membership
```

The frontend does not need to inspect raw bearer tokens to test this.

---

# 52. Critical E2E — Dirty Form Tenant Switch

Scenario:

```text
form dirty
↓
Tenant switch
```

Expected:

```text
confirmation
```

Cancel:

```text
no switch request
```

Confirm:

```text
transition proceeds
```

---

# 53. Critical E2E — Workspace Staleness

Test setup may deactivate an assignment through test/backend fixture machinery while browser remains open.

Next Workspace-scoped request:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

must produce:

```text
TENANT fallback
```

without infinite retries.

---

# 54. Critical E2E — Authorization Changes

Scenario:

```text
user capability initially includes permission
```

then backend permission changes.

Operation:

```text
denied by backend
```

Expected browser behavior:

```text
operation failure
↓
capability reconciliation
↓
UI adapts
```

No automatic mutation replay.

---

# 55. Critical E2E — Direct URL

Test:

```text
user without permission
opens protected URL directly
```

Expected:

```text
Access Denied
```

not:

```text
Not Found
```

and backend remains protected.

---

# 56. Critical E2E — Deep Link Refresh

Example:

```text
/dormitory/rooms
```

opened directly/refreshed must:

```text
serve SPA
↓
bootstrap auth/context
↓
evaluate route
```

This tests deployment routing, not just React Router.

---

# 57. Critical E2E — API Fallback Protection

Test deployment configuration:

```text
/api/unknown
```

must not return:

```text
index.html
```

Likewise missing static asset must not receive SPA HTML.

---

# 58. Critical E2E — CSRF

ADR-022 requires CSRF protection.

E2E/backend security testing must prove:

```text
legitimate SPA mutation
→ accepted

forged/missing required CSRF condition
→ rejected
```

Exact mechanism comes from ADR-030.

---

# 59. Critical E2E — Logout

Test:

```text
multiple EduCore tabs
↓
logout in Tab A
```

Expected:

```text
browser session invalid
```

Tab B must become unauthenticated when it next contacts/revalidates protected state.

Protected cache must not re-authorize it.

---

# 60. Browser Matrix

Foundation E2E browser engines:

```text
Chromium
Firefox
WebKit
```

Playwright supports these engines directly.

Not every test necessarily needs all engines on every local run.

CI policy may divide:

```text
critical smoke
→ all supported engines

broader suite
→ primary engine on every PR
+
full matrix on protected branch/nightly
```

Exact pipeline cadence remains implementation/CI policy.

---

# 61. Primary Browser

One browser project may be designated the fast primary CI path, normally Chromium-compatible, while cross-browser critical flows run across the supported matrix.

This is a CI optimization.

It does not redefine the browser-support requirement.

---

# 62. Playwright Auto-Waiting

E2E tests should rely on:

```text
locators

web-first assertions

auto-waiting
```

rather than arbitrary sleeps.

Playwright automatically waits for actionability conditions before actions such as clicks.

---

# 63. E2E Selector Policy

Preferred:

```text
role

accessible name

label

stable visible behavior
```

Avoid:

```text
:nth-child(4) > div > button

implementation CSS classes
```

Playwright's own testing guidance recommends resilient user-facing locators and explicit contracts.

---

# 64. Test IDs in E2E

`data-testid` is permitted for:

```text
ambiguous complex controls

non-user-visible stable test contracts

otherwise inaccessible deterministic selectors
```

but not as the default selector for every element.

---

# 65. E2E Tracing

On CI failures, Playwright tracing should be retained according to bounded failure-oriented policy.

Trace Viewer can provide detailed post-run execution inspection.

Artifacts may include:

```text
trace

screenshot

video if configured
```

but must be treated as potentially sensitive test artifacts.

---

# 66. Sensitive Test Artifacts

Screenshots/traces/videos may contain:

```text
test-person identities

Tenant names

form content

business fixture data
```

Therefore:

```text
production data
```

must not be used in E2E fixtures.

Artifact retention should also be bounded.

---

# 67. No Credentials in Test Reports

Test reports/artifacts must not print:

```text
bearer credential

browser session cookie

password

CSRF secret
```

Debug helpers must redact secrets.

---

# 68. Test Accounts

Test credentials may exist only in:

```text
test/CI secret management

test fixtures
```

according to environment design.

They must not be committed as real production credentials.

---

# 69. Test Environment Guard

E2E infrastructure should fail safely if configured toward a prohibited environment.

For destructive suites:

```text
production
→ refuse execution
```

is the expected direction.

Exact guard implementation belongs to TDD/CI.

---

# 70. API Mocking Policy

MSW is allowed for:

```text
component tests

frontend integration tests

failure simulation

race simulation

rare backend edge conditions
```

MSW is NOT sufficient evidence for:

```text
BFF cookie correctness

Laravel middleware

real CORS

real CSRF

database Tenant isolation

actual backend authorization
```

---

# 71. No Duplicated Mock Universe

Do not build giant hand-maintained fake backend behavior.

MSW handlers should model only behavior required by the test.

Fixtures should reuse generated API types where practical.

Backend remains canonical.

---

# 72. Mock Factory Ownership

Platform fixtures:

```text
auth context

memberships

workspaces

capabilities

ApiError
```

belong to frontend test-support infrastructure.

Business fixtures:

```text
students

employees

rooms
```

belong to their owning modules.

---

# 73. Test Support Is Not Production Architecture

Test helpers must not leak into production source as:

```text
if (process.env.TEST) {
   bypassAuthorization()
}
```

or:

```text
window.__TEST_AUTH__ = ...
```

unless an explicitly safe test instrumentation contract is reviewed.

Default:

```text
no test-only security bypass in production bundle
```

---

# 74. No Authorization Test Backdoor

E2E tests authenticate through legitimate browser/backend test flows or controlled server-side test fixture preparation.

They MUST NOT introduce production-accessible:

```text
/login-as-any-user

/bypass-permission

/set-active-tenant
```

routes.

---

# 75. Clock & Expiry Testing

Session/token expiry edge cases may require controlled clock/test configuration.

The architecture should support deterministic expiry tests without:

```text
wait 2 real hours
```

Backend test clock injection or test-specific configuration is preferable.

Exact backend mechanism belongs to BFF TDD.

---

# 76. Race Testing Is Mandatory

EduCore's correctness depends heavily on asynchronous context changes.

Race tests are first-class, not optional.

Critical categories:

```text
Tenant switch vs old request

Workspace switch vs old request

logout vs old request

capability refresh vs denied mutation

route navigation vs query response

candidate context vs current context
```

---

# 77. Race Test — Tenant

```text
R1 Tenant A starts
↓
switch B
↓
B commits
↓
R1 returns
```

Expected:

```text
R1 cannot mutate B rendering
```

---

# 78. Race Test — Workspace

```text
R1 Workspace X starts
↓
switch Y
↓
Y commits
↓
R1 returns
```

Expected:

```text
R1 cannot authorize/render Y
```

---

# 79. Race Test — Logout

```text
protected query starts
↓
logout
↓
query returns
```

Expected:

```text
query cannot repopulate authenticated UI
```

---

# 80. Race Test — Multiple Switch Requests

```text
A → B starts
```

then user attempts:

```text
A → C
```

Expected:

```text
second switch blocked
```

Same for Workspace switch.

---

# 81. Contract Error Tests

Frontend must test:

```text
valid ApiError

unknown machine code

malformed ApiError

unexpected success payload

unknown validation field
```

Critical malformed bootstrap response must:

```text
fail safely
+
be observable
```

---

# 82. Network Error Tests

Frontend integration tests must explicitly distinguish:

```text
HTTP 500

HTTP 401

HTTP 403

network disconnect

cancelled request
```

They must not collapse into one generic failure.

---

# 83. Retry Tests

Required:

```text
safe transient read
→ bounded retry

AUTHORIZATION_DENIED
→ no generic retry

ORGANIZATIONAL_CONTEXT_DENIED
→ recovery, not generic retry

mutation
→ no automatic retry

Tenant switch
→ no automatic retry
```

---

# 84. Optimistic Mutation Tests

Any feature that opts into optimistic updates must include tests for:

```text
optimistic application

backend rejection

rollback

context switch during mutation

duplicate operation prevention
```

High-risk operations remain pessimistic by default.

---

# 85. Routing Tests

Router integration tests must cover:

```text
auth bootstrap

login redirect

safe return path

Access Denied

Context Required

Not Found

Tenant switch → dashboard

Workspace route preservation

Workspace safe fallback

browser Back re-evaluation

dirty-form navigation block
```

---

# 86. Lazy Loading Tests

Build/integration tests should prove business pages are not eagerly imported into initial routing bootstrap.

At least major modules:

```text
Academic

HR

Dormitory
```

must remain independent lazy-loading boundaries when implemented.

---

# 87. Chunk Failure Test

Frontend routing/error tests should simulate route-chunk failure and prove:

```text
controlled recovery

no infinite reload loop

shell preservation where possible
```

Full CDN transition behavior may require staging/deployment tests.

---

# 88. Accessibility Testing Layers

Accessibility quality uses several layers:

```text
semantic component tests

keyboard interaction tests

automated accessibility scan for critical views

manual review for complex interactions
```

Automated tools do not prove full WCAG conformance.

They are one part of the verification strategy.

---

# 89. Keyboard Tests

Critical controls require keyboard tests, especially:

```text
navigation

menus

dialogs

Membership switcher

Workspace switcher

forms

error focus
```

---

# 90. Focus Recovery

Tests should verify intentional focus after:

```text
route navigation

dialog close

validation failure

Access Denied

context switch
```

where UX design defines the focus target.

---

# 91. Visual Regression

Visual regression testing is **not mandatory Foundation-wide**.

It may be introduced selectively for:

```text
shared design-system primitives

critical shell layouts

high-risk visual components
```

rather than screenshotting every page.

This avoids brittle test maintenance.

---

# 92. Snapshot Testing

Large DOM snapshot testing is:

```text
NOT selected as primary component test strategy
```

Small targeted snapshots may be used for stable serialization/output where useful.

Behavioral assertions are preferred.

---

# 93. Coverage Policy

Coverage is measured for diagnostics and gap identification.

It is not the only release gate.

Rejected:

```text
80% coverage
therefore architecture is safe
```

A project can have high coverage while missing:

```text
multi-tab isolation

old-context race

CSRF

Workspace fallback
```

---

# 94. Critical-Path Coverage

Critical platform behaviors must have explicit named tests regardless of global percentages.

Examples:

```text
browser session

Tenant switch

Workspace switch

capability fail-closed

backend denial reconciliation

logout

old-context fencing
```

---

# 95. Coverage Threshold

ADR-029 does not lock a universal percentage yet.

During TDD, reasonable thresholds may be established separately for:

```text
platform pure logic

shared primitives

business modules
```

without incentivizing meaningless assertions solely to satisfy a number.

---

# 96. Test Naming

Test names should describe observable behavior.

Preferred:

```text
preserves Tenant A when switching to Tenant B is denied
```

rather than:

```text
test switch handler
```

Names serve as executable architecture documentation.

---

# 97. Test Location

Following ADR-021 ownership:

```text
platform/tenancy
→ tenancy tests

platform/workspace
→ workspace tests

modules/dormitory
→ dormitory frontend tests
```

Shared test utilities live in a clearly bounded:

```text
frontend/test
```

or equivalent test-support area.

Exact directories are implementation/TDD details.

---

# 98. Colocation Direction

Unit/component tests should generally remain near their owning code.

Cross-platform integration tests may live in:

```text
frontend/tests/integration
```

E2E:

```text
frontend/tests/e2e
```

or equivalent.

The exact physical structure can be finalized during setup.

---

# 99. Frontend Test Utilities

A common render helper may compose:

```text
Router

QueryClient

platform contexts

MSW lifecycle
```

for integration tests.

But it must not hide critical assumptions so deeply that tests become impossible to understand.

---

# 100. Fresh QueryClient per Test

Component/integration tests requiring TanStack Query must use isolated QueryClients.

Rejected:

```text
global QueryClient shared across tests
```

because cache leakage can make tests order-dependent.

---

# 101. No Test Order Dependency

Tests must pass independently.

Rejected:

```text
test 2 assumes test 1 already switched Tenant
```

Each test constructs the state it requires.

---

# 102. Randomness

Random fixture data should be deterministic through:

```text
fixed seed
```

or explicit values where failure reproducibility matters.

Uncontrolled randomness that makes failures unreproducible is rejected.

---

# 103. Dates and Time Zones

EduCore backend has timezone consistency tests.

Frontend tests involving:

```text
date display

time zones

expiry

calendar boundaries
```

must explicitly control relevant time zone/clock context instead of depending on developer-machine locale.

---

# 104. Locale

When localization is introduced, tests should not accidentally depend on the host machine locale.

Translation keys/visible labels should be provided deterministically according to test configuration.

---

# 105. Console Errors

Unexpected:

```text
console.error

unhandled rejection

React error
```

during successful component/integration tests should fail or be surfaced clearly.

Tests that intentionally exercise an error boundary may explicitly expect/suppress the known error through controlled helper logic.

---

# 106. Flaky Tests

Flaky tests are treated as defects.

Do not normalize:

```text
rerun until green
```

as the primary solution.

Retries at E2E runner level may be used diagnostically in CI, but repeated flaky behavior must be investigated.

---

# 107. Playwright Retries

A small CI retry policy may help distinguish environmental flakiness and generate trace evidence.

It MUST NOT become:

```text
five retries until test accidentally passes
```

and hide deterministic defects.

Exact count is CI policy.

---

# 108. E2E Parallelism

Playwright can execute tests in parallel.

EduCore test-data architecture must therefore assume parallel execution rather than relying on one global mutable:

```text
current test Tenant
```

database fixture.

---

# 109. Test Sharding

As suite duration grows, E2E may be sharded across CI workers.

This is an operational optimization and does not change test semantics.

Test independence is a prerequisite.

---

# 110. CI Layering

Canonical CI progression:

```text
Install from lockfile
↓
Lint / Architecture
↓
Typecheck
↓
Unit
↓
Component
↓
Frontend Integration
↓
OpenAPI codegen drift
↓
Production build
↓
Bundle checks
↓
Backend PHPUnit
↓
Critical E2E
```

Exact job parallelization may differ.

---

# 111. Fast Feedback Principle

Cheap deterministic tests should fail before expensive E2E runs where pipeline structure permits.

For example:

```text
TypeScript failure
```

should not require waiting for a full multi-browser E2E suite.

---

# 112. Pull Request Gate

Every pull request affecting frontend must at minimum pass:

```text
lint/architecture

typecheck

unit/component/integration tests

production build

contract drift check
```

Critical E2E smoke tests also become merge gates once browser-auth foundation exists.

---

# 113. Full Regression

Before architecture-critical releases, run:

```text
frontend regression
+
backend PHPUnit regression
+
critical browser matrix
```

A frontend release must not assume backend tests are irrelevant merely because frontend code changed.

API contracts are shared.

---

# 114. Backend vs Frontend Responsibility Matrix

| Concern                              | Primary Test Owner               |
| ------------------------------------ | -------------------------------- |
| Token cryptography                   | PHPUnit                          |
| Membership ownership validation      | PHPUnit                          |
| Tenant database isolation            | PHPUnit                          |
| Organizational assignment resolution | PHPUnit                          |
| Permission evaluation                | PHPUnit                          |
| OpenAPI route/schema integrity       | PHPUnit + contract CI            |
| Browser does not receive bearer      | PHPUnit/BFF + E2E                |
| React auth bootstrap UX              | frontend integration + E2E       |
| Multi-tab Tenant UX                  | Playwright                       |
| Workspace restoration                | integration + E2E                |
| Capability-aware navigation          | unit/component/integration       |
| Route guards                         | integration + E2E                |
| Query isolation                      | unit/integration                 |
| Old-response fencing                 | integration + E2E critical cases |
| Form accessibility                   | component + selected E2E         |
| SPA deep-link deployment             | E2E/staging                      |
| CSRF                                 | backend security tests + E2E     |
| Database concurrency                 | PHPUnit/backend                  |
| Business domain invariants           | owning backend module            |

---

# 115. No Backend Algorithm Duplication

Frontend should not create tests to prove backend algorithms by reconstructing them in TypeScript.

Example rejected:

```text
frontend test recalculates scoped RBAC inheritance
and compares result
```

Frontend receives capability projection.

Backend tests prove the projection is correct.

Frontend tests prove the UI behaves correctly given the projection.

---

# 116. Business Module Testing Contract

Every future business module must provide tests appropriate to its own behavior.

Minimum expectations:

```text
route visibility

route access

critical forms

API success/error states

context isolation

module-specific actions

loading/empty/error UX
```

plus domain-specific critical E2E where justified.

---

# 117. Empty vs Error Testing

Resource pages must test separately:

```text
200 []
→ EMPTY

500
→ ERROR

network unavailable
→ NETWORK state
```

Do not use one generic:

```text
nothing displayed
```

test.

---

# 118. Loading Tests

Test distinct states:

```text
initial load

background refetch

mutation pending

Tenant switch

Workspace switch

capability loading
```

to prevent one global loading pattern from masking context semantics.

---

# 119. Error-Boundary Tests

At minimum test:

```text
module runtime failure
```

and ensure:

```text
shell remains usable
```

when platform state is safe.

Root application error behavior should also have controlled coverage.

---

# 120. Observability Tests

Frontend observability adapter tests must prove sensitive redaction.

Examples:

```text
Authorization
→ not logged

cookie
→ not logged

password
→ not logged

safe error code
→ allowed
```

Exact vendor integration belongs to ADR-031.

---

# 121. Performance Tests

ADR-029 does not make unit test duration proxies for user performance.

Performance budgets are verified through:

```text
build bundle analysis

browser performance measurement

production telemetry
```

under ADR-031.

Selected E2E performance smoke assertions may be used cautiously but must not replace real-user metrics.

---

# 122. Contract Fixtures

Fixtures representing API payloads should be typed against generated contracts where practical.

Example:

```text
WorkspaceProjection fixture
```

should fail TypeScript compilation if a required canonical field changes.

This provides fast frontend evidence of contract impact.

---

# 123. No Sensitive Production Fixture Copies

Do not paste real production:

```text
student records

employee data

resident data

financial information
```

into frontend fixtures.

Use synthetic deterministic data.

---

# 124. Security Test Layers

Frontend security verification is distributed:

```text
Static
→ forbidden APIs/imports

Unit
→ safe redirect/policy functions

Integration
→ fail-closed UX/error behavior

Backend
→ cookie/session/CSRF/BFF authority

E2E
→ browser-level security workflow
```

ADR-030 will specify the concrete security controls to test.

---

# 125. E2E Authentication Is Mandatory Before Frontend Foundation Lock

Frontend Foundation implementation cannot be considered complete with only mocked authentication.

Before implementation phase closes:

```text
real browser
+
real BFF
+
real Laravel
```

must successfully prove the critical authentication/session flows.

---

# 126. E2E Tenant Isolation Is Mandatory

Likewise:

```text
multi-tab Tenant isolation
```

is a release gate.

It cannot be deferred merely because unit/integration context tests pass.

---

# 127. E2E Workspace Isolation Is Mandatory

At least one critical multi-tab Workspace isolation test is required.

This proves the absence of accidental:

```text
localStorage synchronization

global server active Workspace

shared frontend state
```

behavior.

---

# 128. Test Failure Artifacts

CI may retain failure artifacts:

```text
Playwright trace

screenshots

videos where configured

test report

browser console/network metadata
```

for debugging.

Retention must be bounded and privacy-conscious.

---

# 129. Source Control

Generated:

```text
test reports

coverage output

screenshots

videos

traces
```

must not normally be committed to Git.

Only intentional small fixture/golden artifacts are versioned.

---

# 130. Local Developer Workflow

Developers should be able to run:

```text
fast targeted tests

watch mode

specific component/integration test

specific Playwright scenario

full frontend suite
```

without requiring the entire production-like stack for every unit test.

Vitest provides a Vite-integrated development test loop, matching this need.

---

# 131. Test Scripts

Conceptual scripts:

```text
test
test:unit
test:integration
test:watch
test:e2e
test:e2e:ui
test:coverage
typecheck
lint
```

Exact package script names may be finalized during TDD/setup.

---

# 132. Version Policy

Testing tool versions are pinned through:

```text
package.json
+
lockfile
```

Architecture selects the tools, not permanent minor/patch versions.

Normal compatible upgrades do not require a new ADR.

---

# 133. Required Tooling

Selected:

```text
Vitest
✅

React Testing Library
✅

MSW
✅

Playwright
✅
```

Not selected as parallel alternatives:

```text
Jest as second unit runner
❌

Cypress as second E2E runner
❌
```

Having two equivalent runners without a demonstrated need would increase maintenance.

---

# 134. Why One Unit Runner

Vite is the canonical build system.

Vitest is Vite-native and uses the Vite configuration/transform pipeline.

Therefore introducing Jest in parallel provides no current architectural benefit.

---

# 135. Why One E2E Runner

Playwright already provides:

```text
browser automation

cross-browser projects

auto-waiting

tracing

assertions
```

for EduCore's needs.

A second E2E framework would duplicate infrastructure without a requirement.

---

# 136. Architectural Invariants

If ADR-029 is accepted:

```text
Unit/component/integration runner
= Vitest

Component interaction
= React Testing Library

Frontend HTTP mocking
= MSW

Browser E2E
= Playwright

Backend tests
= PHPUnit

E2E critical security flows
= REAL BACKEND

MSW as BFF security proof
= FORBIDDEN

Testing only implementation details
= DISCOURAGED

Semantic user-facing selectors
= DEFAULT

data-testid everywhere
= REJECTED

Global coverage %
= NOT sole quality gate

Critical behavior tests
= MANDATORY

Tenant race tests
= MANDATORY

Workspace race tests
= MANDATORY

Multi-tab Tenant E2E
= MANDATORY

Multi-tab Workspace E2E
= MANDATORY

Browser bearer absence test
= MANDATORY

CSRF E2E/backend tests
= MANDATORY

OpenAPI generation drift
= CI GATE

Protected query cache isolation
= TESTED

Test order dependency
= FORBIDDEN

Production security test bypass
= FORBIDDEN

Real production data in fixtures
= FORBIDDEN

Raw sleeps as synchronization
= REJECTED

Playwright user-facing locators
= DEFAULT

Snapshot-heavy component strategy
= REJECTED

Frontend duplicating backend domain tests
= REJECTED
```

---

# 137. Consequences

## Positive

- Testing responsibilities align with architecture ownership.
- Fast tests remain fast.
- Critical browser behavior is tested in real browsers.
- Backend domain/security logic is not unnecessarily duplicated.
- Multi-tab isolation becomes an executable release requirement.
- API mocks exercise the real frontend HTTP layer.
- Contract drift becomes visible before runtime.
- Race conditions receive explicit coverage.
- Accessibility behavior becomes testable early.
- Cross-browser regressions can be detected.
- Failures have better diagnosis through Playwright traces.
- Coverage incentives remain aligned with meaningful behavior.

## Costs

- Multiple testing layers require developer education.
- E2E requires isolated Laravel/database infrastructure.
- Multi-tab tests are more complex than simple page tests.
- MSW fixtures require maintenance.
- Playwright browser binaries increase CI requirements.
- Race tests require intentional deterministic orchestration.
- Contract generation must be integrated into CI.
- Test-data isolation is necessary for parallel execution.

These costs are accepted because a multi-tenant administrative SPA cannot obtain sufficient confidence from a single testing layer.

---

# 138. Risks

## Risk — Too Many E2E Tests

Mitigation:

```text
test business logic lower in pyramid

reserve E2E for browser/integration-critical behavior
```

---

## Risk — Mock Drift

Mitigation:

```text
OpenAPI-generated fixture typing

small handlers

real-stack critical E2E
```

---

## Risk — Brittle Component Tests

Mitigation:

```text
semantic queries

user behavior assertions

avoid DOM internals
```

---

## Risk — Flaky Browser Tests

Mitigation:

```text
Playwright locators/auto-waiting

deterministic fixtures

no arbitrary sleeps

trace failures

fix flakes instead of hiding them
```

---

## Risk — Security Test Backdoors Leak to Production

Mitigation:

```text
no production auth bypass routes

test fixture preparation occurs outside application security contract
```

---

# 139. Explicit Non-Decisions

ADR-029 does not decide:

```text
exact Vitest minor version

exact Playwright minor version

exact MSW minor version

coverage percentage

exact axe accessibility package

exact browser-matrix cadence

exact CI provider

exact sharding count

exact E2E database reset mechanism

exact Playwright retry count

exact trace/video retention duration

exact test directory names

exact test factory library
```

Those belong to TDD/CI implementation.

---

# 140. Follow-Up Dependency

After testing architecture, the remaining frontend foundation ADRs are:

```text
ADR-030
Frontend Security Baseline

ADR-031
Frontend Observability
& Performance Strategy
```

ADR-030 is next because ADR-022 introduced:

```text
HttpOnly Browser Session
+
BFF
+
CSRF requirement
```

and we now have a testing strategy capable of proving those controls.

---

# ADR-029 Proposed State

```text
ADR-029 — Frontend Testing Strategy

Status:
🔒 ACCEPTED / LOCKED

Unit / component / integration runner:
Vitest

Component testing:
React Testing Library

HTTP mocking:
MSW

E2E:
Playwright

Backend/domain/API tests:
PHPUnit

Critical browser auth tests:
REAL Laravel/BFF

Critical Tenant isolation:
Playwright multi-tab

Critical Workspace isolation:
Playwright multi-tab

OpenAPI drift:
CI GATE

Race-condition tests:
MANDATORY

Mock backend as security evidence:
❌ REJECTED

Snapshot-heavy strategy:
❌ REJECTED

Implementation-detail-first component tests:
❌ REJECTED

Coverage % as sole gate:
❌ REJECTED

Production auth test backdoors:
❌ FORBIDDEN

Real production data in fixtures:
❌ FORBIDDEN

Test order dependency:
❌ FORBIDDEN

Next:
ADR-030 — Frontend Security Baseline
```
