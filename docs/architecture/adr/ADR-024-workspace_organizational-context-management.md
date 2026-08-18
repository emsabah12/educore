# ADR-024 — Workspace / Organizational Context Management

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Workspace Discovery, Selection, Restoration, Switching & Stale Context Recovery

---

> ## Decision Summary
>
> EduCore treats **Workspace as a tab-local runtime projection of organizational context**, not as an authentication context and not as a persisted Core domain entity.
>
> Canonical workspace types are:
>
> ```text
> TENANT
> ORGANIZATION
> ORGANIZATION_UNIT
> ```
>
> The available workspace catalog is discovered exclusively through:
>
> ```text
> GET /api/v1/user/my-workspaces
> ```
>
> A Tenant workspace is always the safe baseline after a verified Membership/Tenant context is established.
>
> Organization and OrganizationUnit workspaces are identified by:
>
> ```text
> X-EduCore-Organizational-Assignment-Id
> ```
>
> The assignment identifier is a **locator only**.
>
> Backend validation remains authoritative for:
>
> ```text
> Membership
> Tenant
> OrganizationalAssignment
> Organization
> OrganizationUnit
> active state
> authorization
> ```
>
> Workspace switching:
>
> ```text
> DOES NOT
> change Membership
>
> DOES NOT
> change Tenant
>
> DOES NOT
> exchange bearer credentials
>
> DOES NOT
> mutate a server-side active Workspace session
> ```
>
> Workspace switching uses:
>
> ```text
> prepare
> ↓
> verify capabilities
> ↓
> commit
> ```
>
> For `TENANT`, verification uses:
>
> ```text
> GET /api/v1/core/authorization/capabilities
> ```
>
> without the organizational-assignment header.
>
> For `ORGANIZATION` or `ORGANIZATION_UNIT`, verification uses:
>
> ```text
> GET /api/v1/core/authorization/workspace-capabilities
> ```
>
> with the selected assignment locator.
>
> Only after the target context and its capability projection are authoritative may the frontend atomically publish the new Workspace.
>
> A stale active organizational Workspace that returns:
>
> ```text
> ORGANIZATIONAL_CONTEXT_DENIED
> ```
>
> triggers deterministic recovery:
>
> ```text
> clear stale Workspace
> ↓
> discard restoration hint
> ↓
> rediscover /my-workspaces
> ↓
> fallback to TENANT
> ↓
> reload Tenant capabilities
> ↓
> safe route
> ```
>
> No retry loop with the same stale assignment is permitted.

## Related ADR

- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation
- ADR-023 — Tenant / Membership Context Switching

---

# 1. Context

EduCore backend already distinguishes three different concepts:

```text
Authentication context
        ↓
Membership / Tenant

Organizational context
        ↓
OrganizationalAssignment

Authorization context
        ↓
effective capability projection
```

These concepts must remain separate on the frontend.

The backend does not expose:

```text
Workspace model
Workspace table
Workspace CRUD
```

Instead, `/my-workspaces` produces a read projection for the currently verified Membership/Tenant.

Repository inspection confirms that the projection always starts with:

```text
TENANT
```

and then adds active:

```text
ORGANIZATION
ORGANIZATION_UNIT
```

assignments belonging to the current Membership and Tenant.

Inactive assignments, inactive organizations, inactive units, cross-Membership assignments, and cross-Tenant assignments are excluded.

---

# 2. Canonical Workspace Projection

The canonical workspace DTO currently contains:

```text
type

organizational_assignment_id

organization_id

organization_unit_id

label
```

For Tenant:

```text
type = TENANT

organizational_assignment_id = null

organization_id = null

organization_unit_id = null
```

For Organization:

```text
type = ORGANIZATION

organizational_assignment_id = <assignment>

organization_id = <organization>

organization_unit_id = null
```

For OrganizationUnit:

```text
type = ORGANIZATION_UNIT

organizational_assignment_id = <assignment>

organization_id = <organization>

organization_unit_id = <unit>
```

Frontend must consume this projection rather than reconstruct organizational topology independently.

---

# 3. Workspace Is Not Authentication State

Workspace must never be placed inside:

```text
bearer claims
```

or used to replace:

```text
membership_id
tenant_id
```

Workspace change therefore does not require:

```text
POST membership switch
new bearer credential
browser login
```

Canonical relationship:

```text
Membership / Tenant
        ↓
available Workspaces
```

not:

```text
Workspace
        ↓
new authentication identity
```

---

# 4. Workspace Is Not a Persisted Core Entity

The frontend term:

```text
Workspace
```

represents a UI/runtime abstraction over:

```text
Tenant
Organization
OrganizationUnit
```

It does not justify introducing:

```text
workspaces table

Workspace model

Workspace repository

Workspace CRUD API
```

into Core.

If future product requirements need an independently persisted concept called Workspace, that would be a new requirement rather than an implicit extension of this ADR.

---

# 5. Decision Drivers

The Workspace architecture must guarantee:

```text
1. Tenant and Workspace remain distinct.

2. Tenant-level context is always valid after successful
   Membership/Tenant bootstrap.

3. Assignment ID remains locator-only.

4. Workspace selection is tab-local.

5. Workspace switching exchanges no authentication credential.

6. Stale assignments cannot remain authoritative.

7. Old Workspace capabilities cannot authorize new Workspace UI.

8. Old Workspace responses cannot contaminate new context.

9. Browser reload may restore Workspace safely.

10. Tenant switching always resets Workspace.

11. Backend remains final organizational-context authority.

12. No global browser Workspace synchronization.

13. No server-side global active Workspace is required.

14. Context failures recover deterministically.

15. Business modules receive one coherent effective context.
```

---

# 6. Alternatives Considered

The following models were evaluated:

```text
A. Workspace persisted as backend domain entity

B. Workspace embedded in authentication token

C. Global server/browser active Workspace

D. Client runtime projection with backend validation
```

---

# 7. Option A — Persisted Workspace Entity

Example:

```text
workspaces
├── id
├── tenant_id
├── organization_id
└── ...
```

### Problem

The backend already possesses canonical organizational entities:

```text
Tenant
Organization
OrganizationUnit
OrganizationalAssignment
```

Adding another entity would duplicate semantics without a demonstrated product requirement.

### Decision

```text
REJECTED
```

---

# 8. Option B — Workspace in Bearer Credential

Example:

```text
token {
    membership_id,
    tenant_id,
    workspace_id
}
```

### Problems

Workspace switching would require credential exchange.

That would incorrectly merge:

```text
authentication context
```

with:

```text
organizational runtime context
```

It would also make frequent organizational navigation unnecessarily mutate authentication state.

### Decision

```text
REJECTED
```

---

# 9. Option C — Global Active Workspace

Examples:

```text
localStorage.activeWorkspace
```

or:

```text
BrowserSession.activeWorkspace
```

### Problem

Two tabs must be able to use:

```text
Tab A
Workspace X

Tab B
Workspace Y
```

under the same Membership/Tenant.

Global Workspace state would couple those tabs.

### Decision

```text
REJECTED
```

---

# 10. Option D — Runtime Projection

Selected architecture:

```text
Membership / Tenant
        ↓
GET /my-workspaces
        ↓
tab-local Workspace
        ↓
verified organizational locator
        ↓
capability projection
```

### Decision

```text
SELECTED
```

---

# 11. Frontend Ownership

Under ADR-021:

```text
platform/workspace/
```

owns:

```text
Workspace discovery

active Workspace state

Workspace restoration

Workspace switch orchestration

organizational assignment locator

stale Workspace recovery

Workspace transition generation
```

It does not own:

```text
Tenant switching

authentication credential custody

authorization calculation

business module state
```

---

# 12. Canonical Workspace State

Conceptually:

```text
WorkspaceContext {
    type
    organizationalAssignmentId
    organizationId
    organizationUnitId
    label
    workspaceGeneration
}
```

For Tenant:

```text
organizationalAssignmentId = null
```

The exact TypeScript interface belongs to TDD.

---

# 13. Tenant Workspace Is First-Class

Tenant context is not:

```text
no Workspace
```

It is itself a valid:

```text
TENANT Workspace
```

Therefore application state should not use ambiguous logic such as:

```text
workspace === null
```

to mean Tenant mode.

Prefer explicit state:

```text
workspace.type === "TENANT"
```

This reduces branching ambiguity.

---

# 14. Tenant Workspace Has No Fake Assignment

Frontend MUST NOT create:

```text
organizational_assignment_id = tenant_id
```

or:

```text
organizational_assignment_id = "tenant"
```

for Tenant context.

Canonical Tenant Workspace has:

```text
organizational_assignment_id = null
```

Requests in Tenant scope omit the organizational-assignment header.

---

# 15. Workspace Discovery

Canonical endpoint:

```text
GET /api/v1/user/my-workspaces
```

requires verified:

```text
Membership
+
Tenant
```

context.

The returned catalog is scoped exclusively to that current Membership/Tenant.

---

# 16. Discovery Is Context-Sensitive

This means:

```text
/my-workspaces
under Membership A
```

is not equivalent to:

```text
/my-workspaces
under Membership B
```

Even if the same User owns both Memberships.

Workspace discovery cache identity therefore includes at minimum:

```text
membershipId
tenantId
```

---

# 17. Discovery Is Not Permanent Authority

A Workspace returned earlier may later become invalid because:

```text
assignment becomes INACTIVE

Organization becomes inactive

OrganizationUnit becomes inactive

assignment is removed

membership changes
```

Therefore:

```text
discovered Workspace
≠ permanent authorization
```

Backend validates organizational context again on every relevant request.

---

# 18. Organizational Assignment Header

Canonical locator:

```text
X-EduCore-Organizational-Assignment-Id
```

is sent only when:

```text
workspace.type
=
ORGANIZATION
or
ORGANIZATION_UNIT
```

Tenant scope:

```text
header absent
```

---

# 19. Header Is Locator Only

The backend currently validates:

```text
UUID format

current Tenant

current Membership

assignment ownership

assignment ACTIVE

Organization ACTIVE

OrganizationUnit ACTIVE

organization/unit consistency
```

Therefore client possession of:

```text
organizational_assignment_id
```

never grants access.

---

# 20. Forged Assignment Behavior

Suppose the frontend sends:

```text
X-EduCore-Organizational-Assignment-Id:
<assignment from another Membership>
```

Backend must return denial.

Frontend MUST NOT infer:

```text
header accepted because ID existed
```

Assignment identity remains untrusted request input.

---

# 21. Workspace Switching Is Client Runtime Selection

Workspace switch does not call a backend operation analogous to:

```text
POST /workspace/switch
```

because there is no canonical mutable server-side Workspace state.

Instead:

```text
select target
↓
verify target
↓
publish target locally
```

The server continues resolving organizational context independently on each request.

---

# 22. Workspace State Machine

Canonical conceptual states:

```text
UNRESOLVED
      ↓
READY
      ↓
SWITCHING
      ↓
READY
```

Additional recovery state:

```text
RECOVERING
```

may be used when an active organizational assignment becomes stale.

---

# 23. Initial Workspace After Login

After fresh authentication bootstrap:

```text
/auth/me
↓
/my-workspaces
```

the frontend chooses:

```text
TENANT
```

as the safe initial context.

It does not automatically enter the first Organization in the list.

---

# 24. Initial Workspace After Tenant Switch

ADR-023 locks:

```text
Tenant switch
↓
Workspace reset
```

Therefore after successful Tenant switch:

```text
active Workspace = TENANT
```

Even if the new Tenant contains only one organizational assignment.

This creates deterministic security behavior.

---

# 25. Normal Reload Is Different

Normal reload may attempt to restore a previously selected Workspace because:

```text
Membership
and
Tenant
```

did not intentionally change.

However restoration must happen only after authoritative rediscovery.

---

# 26. Workspace Restoration Hint

A tab may persist:

```text
organizational_assignment_id
```

as a non-secret restoration hint.

Conceptually:

```text
sessionStorage
→ educore.workspace_hint
```

Exact key naming remains implementation detail.

---

# 27. Restoration Hint Is Not Authority

Saved value:

```text
assignment A
```

does not mean:

```text
restore A unconditionally
```

Correct flow:

```text
/auth/me
↓
verified Membership/Tenant
↓
/my-workspaces
↓
does assignment A still exist?
```

Only then may restoration continue.

---

# 28. Restoration Must Match Current Tenant Context

A Workspace hint created under:

```text
Membership A / Tenant A
```

must not be restored under:

```text
Membership B / Tenant B
```

even if an identical identifier somehow appears.

The saved restoration information must therefore be associated conceptually with:

```text
membershipId
tenantId
organizationalAssignmentId
```

or discarded whenever Membership/Tenant changes.

---

# 29. Restoration Verification

For an organizational Workspace that remains present in `/my-workspaces`, frontend still needs current capability projection before publishing protected UI.

Conceptual restoration:

```text
discover Workspace A
↓
GET workspace-capabilities
   header: Assignment A
↓
capability READY
↓
commit Workspace A
```

If capability/context verification fails:

```text
restore = rejected
```

---

# 30. Failed Restoration

Failed restoration falls back to:

```text
TENANT
```

The stale restoration hint is discarded.

Then:

```text
GET /authorization/capabilities
```

establishes authoritative Tenant capabilities.

---

# 31. Workspace Switch Preconditions

A switch may begin only if:

```text
authentication READY

Membership/Tenant READY

Workspace catalog READY

target Workspace belongs to current discovery result

target != current

no Workspace switch already active

dirty-form policy permits the change
```

---

# 32. Selecting Current Workspace

If:

```text
target == current
```

behavior is:

```text
NO-OP
```

No capability refetch is required solely because the current option was reselected.

Normal capability revalidation is a separate operation.

---

# 33. Dirty Form Gate

Before switching:

```text
current form dirty?
```

If yes:

```text
Discard changes and switch Workspace?
```

If user cancels:

```text
switch does not start
```

This mirrors Tenant switching while remaining a lighter transition.

---

# 34. One Workspace Switch Per Tab

Only one transition may execute at a time per tab.

Rejected race:

```text
Workspace X → Y
and
Workspace X → Z
```

concurrently.

The switch control remains pending until commit or rollback.

---

# 35. Phase 1 — Start Transition

Conceptually:

```text
READY(X)
↓
SWITCHING(X → Y)
```

At transition start:

```text
new business mutations blocked

old Workspace data becomes non-interactive

superseded reads cancelled where practical

old Workspace request generation fenced
```

---

# 36. Workspace Switch Is Lighter Than Tenant Switch

A Workspace switch does not require:

```text
credential exchange

/auth/me

Membership discovery

Tenant change
```

Therefore the transition may be visually lighter than Tenant switching.

But:

```text
lighter UX
≠ weaker correctness
```

Old Workspace data must still not remain interactive.

---

# 37. Switching to Tenant Workspace

Target:

```text
TENANT
```

requires:

```text
no organizational assignment header
```

Verification:

```text
GET /api/v1/core/authorization/capabilities
```

The resulting capability projection must reach:

```text
READY
```

before commit.

---

# 38. Switching to Organizational Workspace

For:

```text
ORGANIZATION
```

or:

```text
ORGANIZATION_UNIT
```

verification uses:

```text
GET /api/v1/core/authorization/workspace-capabilities
```

with:

```text
X-EduCore-Organizational-Assignment-Id
```

The backend resolves and verifies organizational context for that request.

---

# 39. Why Capabilities Are Used as Verification

The capability endpoint is particularly appropriate because it simultaneously proves:

```text
authenticated Tenant/Membership context valid

organizational assignment resolves

authorization projection available
```

and returns exactly the capability state required to render protected Workspace UX.

Therefore Workspace should not first be committed and then authorized afterward.

---

# 40. Candidate Workspace

During transition the application may internally hold:

```text
active = X

candidate = Y
```

Candidate Y must remain unpublished to normal business modules until verification succeeds.

---

# 41. Atomic Commit

Once candidate capability state is authoritative:

```text
candidate = {
    workspace: Y,
    capabilities: Y,
    generation: N+1
}
```

is published atomically.

After commit:

```text
Workspace Y
+
Capabilities Y
```

become the coherent active context.

---

# 42. No Capability Carry-Over

Forbidden:

```text
Workspace X
permissions = X
↓
select Y
↓
Workspace Y
permissions temporarily still = X
```

Protected UI fails closed until the new projection is authoritative.

---

# 43. Capability State

Workspace transition must preserve the distinction:

```text
LOADING
READY
ERROR
```

and:

```text
permissions = []
```

means:

```text
READY
with zero effective permissions
```

not capability failure.

---

# 44. Route Preservation

Unlike Tenant switch, Workspace switch does not automatically force `/dashboard`.

After successful commit:

```text
current route
```

may remain only when:

```text
route is still structurally valid
+
required capability exists
+
route context requirements are satisfied
```

Otherwise:

```text
safe route
```

is selected.

---

# 45. Route Example

Suppose current page is:

```text
/academic/students
```

and Workspace X has:

```text
academic.students.view
```

If Workspace Y also has the permission and the page supports Y:

```text
route may remain
```

If not:

```text
navigate to safe dashboard/module landing
```

Exact route-policy mechanics belong to ADR-027/ADR-028.

---

# 46. Workspace-Sensitive Server State

Query identity must distinguish:

```text
Tenant A / Workspace X
```

from:

```text
Tenant A / Workspace Y
```

when the endpoint is organizational-context sensitive.

Conceptually:

```text
resource
+
membershipId
+
tenantId
+
workspaceIdentity
+
parameters
```

---

# 47. Tenant-Scoped Data Does Not Need Artificial Workspace Keys

Not every query is Workspace-sensitive.

Example:

```text
Tenant-wide settings
```

may depend only on:

```text
Membership/Tenant
```

Therefore query architecture must distinguish:

```text
tenant-scoped resource
```

from:

```text
workspace-scoped resource
```

rather than adding Workspace to every cache key mechanically.

Detailed query-key policy remains ADR-026.

---

# 48. Workspace Generation

Every successful Workspace commit creates a new logical:

```text
workspaceGeneration
```

or equivalent context revision.

Example:

```text
Workspace X
generation 41

Workspace Y
generation 42
```

Context-sensitive requests capture that generation when they start.

---

# 49. Old Workspace Response Fence

Example:

```text
R1 starts
Workspace X / generation 41
```

then:

```text
switch → Workspace Y / generation 42
```

then R1 finishes.

R1 MUST NOT update active Workspace Y UI.

Cancellation alone is not sufficient.

Correctness relies on:

```text
context-aware cache identity
+
response fencing
```

---

# 50. Mutations During Workspace Switch

From transition start until commit/rollback:

```text
business mutations
= BLOCKED
```

This prevents an operation initiated visually under one Workspace from executing using another.

Platform recovery/bootstrap calls remain allowed.

---

# 51. Switch Failure Before Commit

If candidate Workspace verification fails:

```text
target is not committed
```

Current Workspace X may remain active if it is still authoritative.

Frontend may refresh discovery when failure indicates target staleness.

---

# 52. Candidate `ORGANIZATIONAL_CONTEXT_DENIED`

Suppose:

```text
Workspace Y
```

was present in the previously loaded catalog but became inactive before switching.

Capability verification returns:

```text
403
ORGANIZATIONAL_CONTEXT_DENIED
```

Behavior:

```text
do not commit Y
↓
remove/discard candidate restoration state
↓
refresh /my-workspaces
↓
preserve X if X remains authoritative
```

This differs from stale **currently active** Workspace recovery.

---

# 53. Active Workspace Becoming Stale

A more important scenario:

```text
Workspace X is already active
```

and a normal API call later returns:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

This indicates that X can no longer be trusted.

Frontend must not keep X active.

---

# 54. Canonical Stale Workspace Recovery

Required sequence:

```text
ORGANIZATIONAL_CONTEXT_DENIED
        ↓
mark active Workspace stale
        ↓
stop affected operation
        ↓
block old Workspace interaction
        ↓
clear Workspace X
        ↓
discard X restoration hint
        ↓
GET /my-workspaces
        ↓
select TENANT fallback
        ↓
GET Tenant capabilities
        ↓
commit TENANT
        ↓
safe route
```

---

# 55. No Retry With Same Stale Assignment

Explicitly forbidden:

```text
request using X
↓
ORGANIZATIONAL_CONTEXT_DENIED
↓
retry using X
↓
denied
↓
retry using X
...
```

There is no infinite organizational-context retry.

Once X is denied:

```text
X becomes stale
```

until rediscovery proves otherwise in a later explicit user action.

---

# 56. Why Recovery Falls Back to Tenant

Tenant Workspace is guaranteed by `/my-workspaces` whenever canonical Membership/Tenant context remains valid.

Therefore it is the deterministic safe organizational fallback.

The frontend must not arbitrarily pick:

```text
first Organization
```

from the refreshed list.

---

# 57. Tenant Fallback Can Also Fail

If Tenant capability bootstrap fails because authentication/Membership/Tenant context itself is no longer authoritative:

```text
Workspace recovery stops
```

and session/authentication recovery takes precedence.

Security hierarchy:

```text
Authentication validity
>
Tenant/Membership validity
>
Workspace validity
```

---

# 58. `ORGANIZATIONAL_CONTEXT_REQUIRED`

If an operation explicitly requires organizational context but frontend sends no assignment:

```text
ORGANIZATIONAL_CONTEXT_REQUIRED
```

this is not necessarily a stale-context event.

It may indicate:

```text
route/operation requires Organization/Unit Workspace
but current Workspace = TENANT
```

Frontend should handle this as:

```text
context requirement not satisfied
```

rather than repeatedly selecting an arbitrary Workspace.

---

# 59. Invalid Assignment Identifier

Backend may return:

```text
INVALID_ORGANIZATIONAL_ASSIGNMENT_ID
```

for malformed assignment locators.

Because frontend should only use authoritative discovery values, this normally indicates:

```text
client state corruption
contract bug
tampering
```

The application should fail safely, discard the invalid hint/state, and recover through discovery.

---

# 60. Context Resolution Failure

A backend:

```text
ORGANIZATIONAL_CONTEXT_RESOLUTION_FAILED
```

represents an unexpected server/infrastructure error.

It is not equivalent to:

```text
user has no Workspace
```

Frontend must expose server-error recovery rather than silently converting it into Tenant fallback unless subsequent authoritative recovery succeeds.

---

# 61. Workspace Restoration on Reload

Canonical bootstrap:

```text
browser session
↓
Membership hint
↓
/auth/me
↓
/my-workspaces
↓
read Workspace hint
```

Then:

### No hint

```text
TENANT
```

### Hint exists and remains valid

```text
verify capability
↓
restore
```

### Hint missing from discovery

```text
discard hint
↓
TENANT
```

### Verification denied

```text
discard hint
↓
TENANT
```

---

# 62. Workspace Restoration Must Not Delay Security Bootstrap

Workspace hint restoration happens only after:

```text
authentication
+
Membership/Tenant
```

are authoritative.

Frontend must never start an organizational request immediately from raw `sessionStorage` before `/auth/me` establishes current context.

---

# 63. Multi-Tab Workspace Isolation

Example:

```text
Tab A
Membership A
Workspace Organization X

Tab B
Membership A
Workspace OrganizationUnit Y
```

must remain valid simultaneously.

No:

```text
localStorage workspace sync

BroadcastChannel automatic Workspace sync

BFF global active Workspace
```

is required.

---

# 64. Tenant and Workspace Isolation Combined

The complete context may look like:

```text
Tab A

Membership A
Tenant A
Workspace X
```

while:

```text
Tab B

Membership B
Tenant B
Workspace Y
```

Each tab remains independent.

---

# 65. Workspace Has No Credential Map

Unlike Membership context under ADR-022:

```text
Workspace
```

does not require a separate bearer token.

One Membership credential can support multiple organizational assignments because the assignment locator is validated per request.

Therefore the BFF credential map remains:

```text
Membership
→ Bearer
```

not:

```text
Membership
→ Workspace
→ Bearer
```

---

# 66. Browser-BFF Forwarding

For an organizational request:

```text
React Tab
    │
    │ Membership selector
    │ Organizational assignment locator
    ▼
BFF
    │
    │ resolves Membership bearer
    │ preserves assignment locator
    ▼
canonical /api/v1
```

The BFF must not transform the assignment locator into trusted authorization state.

---

# 67. BFF Must Not Cache Organizational Authority

Rejected architecture:

```text
BFF sees Assignment X once
↓
marks X authorized forever
```

Backend must remain capable of detecting:

```text
assignment deactivation

organization deactivation

unit deactivation
```

on later requests.

---

# 68. Capability Projection

Canonical capability endpoint selection:

## Tenant

```text
GET /api/v1/core/authorization/capabilities
```

## Organization / OrganizationUnit

```text
GET /api/v1/core/authorization/workspace-capabilities
+
assignment header
```

Frontend must not calculate organizational scoped-role inheritance itself.

---

# 69. Workspace Type Is Presentation/Context Metadata

Frontend may branch on:

```text
TENANT
ORGANIZATION
ORGANIZATION_UNIT
```

to determine:

```text
header behavior
labels
context requirements
presentation
```

But Workspace type does not itself grant a permission.

Example forbidden:

```text
if workspace.type === "ORGANIZATION"
    allow edit
```

Authorization still comes from capability projection and backend enforcement.

---

# 70. Workspace Labels Are Display Data

The `label` returned by discovery is for UX.

It must not become:

```text
authorization key

route authority

business identifier
```

IDs remain canonical identifiers.

---

# 71. Workspace Catalog Refresh

Catalog may be refreshed when:

```text
normal bootstrap occurs

active Workspace becomes stale

user explicitly refreshes relevant context

other platform behavior demonstrates staleness
```

No high-frequency polling is required.

Default:

```text
workspace polling = OFF
```

---

# 72. Context Changes Outside the Browser

EduCore accepts that administrators may change organizational assignments while a user is active.

The architecture handles this through:

```text
backend per-request validation
+
stale Workspace recovery
```

rather than aggressive polling of assignments.

---

# 73. Navigation Interaction

Navigation catalog may include entries that require:

```text
Tenant context
```

or:

```text
organizational context
```

and capability requirements.

Exact policy belongs to ADR-027.

Workspace context should be an input to navigation evaluation, not duplicated inside every module.

---

# 74. Business Module Contract

Business modules may consume:

```text
current Workspace projection

current capabilities

Workspace-aware API infrastructure
```

They MUST NOT directly implement:

```text
sessionStorage restoration

organizational header injection

stale Workspace recovery

workspace capability bootstrap
```

Those remain platform responsibilities.

---

# 75. Header Injection Ownership

The mechanism that adds:

```text
X-EduCore-Organizational-Assignment-Id
```

belongs to canonical API/context infrastructure.

Business features should not manually repeat:

```text
headers: {
  "X-EduCore-Organizational-Assignment-Id": ...
}
```

throughout the codebase.

Exact API client implementation belongs to ADR-025.

---

# 76. Endpoint Context Metadata

ADR-025/ADR-026 should enable API calls to distinguish whether an operation is:

```text
Tenant-scoped

Workspace-scoped

Context-neutral where applicable
```

so header and cache behavior are explicit rather than accidentally inferred.

---

# 77. Workspace Switch Observability

Useful safe events include:

```text
workspace_switch_started

workspace_switch_succeeded

workspace_switch_denied

workspace_restore_succeeded

workspace_restore_failed

workspace_became_stale

workspace_fallback_to_tenant
```

Allowed metadata may include justified:

```text
workspace type

assignment ID

membership ID

tenant ID

request/correlation ID
```

subject to privacy policy.

Never include credentials.

---

# 78. Error Boundary Between Context and Authorization

Important distinction:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

means the selected assignment cannot be resolved as valid current context.

That differs from a normal operation-level:

```text
authorization denied
```

after valid context resolution.

Frontend recovery must preserve this distinction.

---

# 79. No Automatic Workspace Selection Based on Role

Forbidden:

```text
if role === "teacher"
    choose classroom workspace
```

or:

```text
if role === "admin"
    choose Tenant workspace
```

Workspace availability comes from discovery.

Authorization comes from capabilities.

Persona/role labels do not determine runtime context.

---

# 80. No Automatic Child Workspace Selection

Selecting Organization:

```text
Organization A
```

does not automatically select one of its OrganizationUnits.

Selecting a Unit is a separate explicit Workspace choice represented by its own assignment.

---

# 81. Organization Hierarchy Does Not Imply Assignment Authority

Frontend may display organizational hierarchy for UX if a later requirement needs it.

However:

```text
parent Organization access
```

does not allow frontend to infer:

```text
all child Unit access
```

Available Workspace assignments remain authoritative discovery projections.

---

# 82. Context Generation Composition

ADR-023 defines Tenant/Membership context generation.

ADR-024 adds Workspace revision semantics.

Conceptually, a request context may contain:

```text
tenantGeneration
workspaceGeneration
```

or one equivalent composed runtime context identity.

The implementation detail is deferred.

Invariant:

```text
Workspace switch invalidates
Workspace-sensitive observation
without pretending Tenant changed
```

---

# 83. Cache Retention

Inactive Workspace cache may remain temporarily for bounded reuse if ADR-026 chooses that strategy.

But:

```text
retained
≠ active
```

and:

```text
cached Workspace X permissions
```

must never authorize Workspace Y.

Logout clears protected server-state cache.

---

# 84. Tests Required

Implementation must prove:

```text
1. Tenant Workspace is always represented explicitly.

2. Tenant Workspace has null assignment locator.

3. /my-workspaces is authoritative discovery source.

4. cross-Tenant assignments are not selectable.

5. cross-Membership assignments are not selectable.

6. inactive assignments are not selectable.

7. inactive organizations are not selectable.

8. inactive units are not selectable.

9. forged assignment locator cannot grant context.

10. Workspace switch does not exchange bearer credential.

11. Workspace switch does not change Membership.

12. Workspace switch does not change Tenant.

13. Tenant → Organization verifies workspace capabilities.

14. Organization → Tenant verifies Tenant capabilities.

15. old capabilities are not reused after switch.

16. one Workspace switch executes at a time per tab.

17. dirty form may cancel Workspace switch.

18. business mutations are blocked during transition.

19. failed candidate verification preserves valid current Workspace.

20. active ORGANIZATIONAL_CONTEXT_DENIED clears stale Workspace.

21. stale assignment hint is discarded.

22. stale recovery rediscoveries /my-workspaces.

23. stale recovery falls back to Tenant.

24. stale recovery does not retry same assignment indefinitely.

25. normal reload can restore still-valid Workspace.

26. invalid reload hint falls back to Tenant.

27. Workspace hint is never treated as authorization authority.

28. Tenant switch clears Workspace restoration state.

29. Tab A Workspace change does not affect Tab B.

30. old Workspace response cannot update new Workspace UI.

31. route remains only when still valid after switch.

32. zero capabilities differs from capability request failure.
```

---

# 85. Critical Race Tests

### Race A

```text
Request X starts
Workspace A
↓
switch to Workspace B
↓
Request X finishes
```

Expected:

```text
response X cannot mutate
active Workspace B UI
```

### Race B

```text
Workspace B discovered
↓
assignment B deactivated
↓
user selects B
↓
workspace-capabilities denied
```

Expected:

```text
B never commits
```

### Race C

```text
Workspace B active
↓
B becomes inactive server-side
↓
normal request denied
```

Expected:

```text
B cleared
↓
rediscovery
↓
Tenant fallback
```

---

# 86. Architectural Invariants

If ADR-024 is accepted:

```text
Workspace
= runtime/read projection

Workspace persistence entity
= NOT REQUIRED

Workspace types
= TENANT
  ORGANIZATION
  ORGANIZATION_UNIT

Workspace discovery
= GET /api/v1/user/my-workspaces

Tenant Workspace
= always safe baseline after valid Membership/Tenant

Tenant assignment ID
= null

Organizational locator
= X-EduCore-Organizational-Assignment-Id

assignment locator
= NOT authorization authority

Workspace selection
= tab-local

global browser Workspace
= FORBIDDEN

global BFF active Workspace
= FORBIDDEN

Workspace switch
= no credential exchange

Workspace switch
= prepare → verify → commit

Tenant capability endpoint
= /core/authorization/capabilities

Organizational capability endpoint
= /core/authorization/workspace-capabilities

old capability reuse
= FORBIDDEN

Workspace restoration hint
= non-authoritative

fresh login Workspace
= TENANT

fresh Tenant switch Workspace
= TENANT

normal reload restoration
= allowed after authoritative rediscovery

ORGANIZATIONAL_CONTEXT_DENIED
= stale Workspace recovery trigger

stale retry loop
= FORBIDDEN

stale fallback
= TENANT

backend organizational validation
= final authority
```

---

# 87. Consequences

## Positive

- Authentication and organizational context remain cleanly separated.
- No extra credential churn for Workspace navigation.
- Per-tab Workspace independence is preserved.
- Backend organizational validation remains authoritative.
- Tenant is always a deterministic safe fallback.
- Stale assignments recover predictably.
- Capability projection and Workspace activation remain coherent.
- Workspace cannot silently become a duplicate Core entity.
- Cache and request isolation have explicit context boundaries.
- Business modules do not need to understand organizational-resolution internals.

## Costs

- Workspace switches require capability verification before commit.
- Runtime context orchestration requires generation/fencing.
- Dirty-form handling must participate in Workspace transitions.
- API infrastructure must understand Workspace-scoped calls.
- Race-condition tests are required.
- Restoration requires rediscovery rather than blindly trusting browser storage.

These costs are accepted because organizational-context contamination can expose or mutate data under the wrong operational scope.

---

# 88. Explicit Non-Decisions

ADR-024 does not decide:

```text
exact sessionStorage restoration key

exact React provider structure

exact Workspace selector component

exact query-key factory

cache staleTime/gcTime

navigation metadata format

route guard API

OpenAPI generator

organizational header implementation API

capability hook API

observability provider

exact safe-route algorithm
```

Those remain later ADR/TDD concerns.

---

# 89. Follow-Up Dependency

With:

```text
Authentication
↓
Membership/Tenant
↓
Workspace
```

context architecture established, the next cross-cutting boundary is:

```text
ADR-025
API Client, OpenAPI
& Canonical Error Handling
```

ADR-025 must integrate:

```text
BFF browser authentication

tab-local Membership selector

Workspace assignment locator

OpenAPI-generated contracts

canonical machine-readable errors
```

without making individual business modules manually reproduce those headers and recovery rules.

---

# ADR-024 Proposed State

```text
ADR-024 — Workspace / Organizational Context Management

Status:
🔒 ACCEPTED / LOCKED

Workspace:
runtime/read projection

Persisted Workspace entity:
❌ REJECTED

Workspace in bearer token:
❌ REJECTED

Global active Workspace:
❌ REJECTED

Canonical types:
TENANT
ORGANIZATION
ORGANIZATION_UNIT

Discovery:
GET /api/v1/user/my-workspaces

Organizational locator:
X-EduCore-Organizational-Assignment-Id

Locator authority:
❌ NONE

Workspace switch:
prepare
→ verify capability
→ commit

Authentication credential exchange:
❌ NONE

Fresh login:
TENANT Workspace

Fresh Tenant switch:
TENANT Workspace

Normal reload restore:
✅ allowed after rediscovery + verification

Active stale Workspace:
clear
→ rediscover
→ TENANT fallback
→ Tenant capabilities
→ safe route

Infinite stale retry:
❌ FORBIDDEN

Multi-tab Workspace isolation:
✅ REQUIRED

Backend organizational validation:
✅ FINAL AUTHORITY
```
