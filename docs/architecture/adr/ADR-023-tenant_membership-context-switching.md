# ADR-023 — Tenant / Membership Context Switching

**Version** : 1.1
**Status** : Accepted
**Date** : 2026-08-18
**Implementation Resolution** : 2026-08-29
**Scope** : Frontend Foundation — Tenant/Membership Runtime Context, Switching Transaction & Isolation

---

> ## Decision Summary
>
> EduCore treats **Tenant switching as an explicit Membership authentication-context transition**, not as a simple UI preference change.
>
> The browser maintains the selected Membership as **tab-local, non-authoritative context state**.
>
> The Browser Authentication BFF established by ADR-022 maintains canonical membership-scoped bearer credentials server-side.
>
> Switching from:
>
> ```text
> Membership A / Tenant A
> ```
>
> to:
>
> ```text
> Membership B / Tenant B
> ```
>
> is implemented as a **prepare → verify → commit transaction**.
>
> The new context MUST NOT become active in the React application merely because the user selected Membership B or because the canonical switch endpoint returned a new credential.
>
> The target context is first established server-side and then verified through canonical authenticated bootstrap:
>
> ```text
> POST /api/v1/user/memberships/{membership_id}/switch
>                  ↓
> server-side Bearer B
>                  ↓
> GET /api/v1/auth/me
>                  ↓
> verified Membership B / Tenant B
> ```
>
> followed by Workspace discovery and Tenant-level capability loading.
>
> Only after the target context is authoritative and bootstrap succeeds does the frontend **atomically commit Membership B / Tenant B as the tab's active context**.
>
> Until that commit:
>
> ```text
> Membership A / Tenant A
> ```
>
> remains the recoverable current context.
>
> A failed switch MUST NOT partially expose the target Tenant or leave the UI in a mixed Tenant state.
>
> Old-context requests and responses are fenced by context identity/generation so they can never contaminate the newly active context.

## Related ADR

- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-018 — Organizational Topology & Scoped Authorization
- ADR-020 — Frontend Framework & Rendering Strategy
- ADR-021 — Frontend Modular Application Architecture
- ADR-022 — Authentication Credential Storage & Browser Session Isolation

---

# Implementation Resolution — 2026-08-29

The prepare → verify → commit decision remains unchanged.

References throughout the original ADR to the bearer-returning route:

```text
POST /api/v1/user/memberships/{membership_id}/switch
```

are the canonical Membership-scoped bearer-switch contract for supported non-browser/API clients and the server-side domain semantics used by the browser mediation layer. Those historical references are **not** the route called directly by the first-party React SPA.

The finalized first-party browser contract is:

```text
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

The browser-safe switch:

```text
does not expose access_token
does not transfer canonical bearer custody to React
does not make the target Membership authoritative merely from client selection
```

The effective browser transition is:

```text
PREPARE
POST /api/v1/browser/user/memberships/{membership_id}/switch
        ↓
server establishes/updates target Membership credential
        ↓
VERIFY
GET /api/v1/auth/me
using BrowserSessionAuth
and X-EduCore-Membership-Id target locator
        ↓
authoritative target Membership / Tenant verified
        ↓
COMMIT
tab-local active context changes atomically
```

`X-EduCore-Membership-Id` remains:

```text
untrusted locator only
≠ authentication
≠ Tenant authority
≠ authorization
```

Failed preparation or verification preserves the previously valid tab context.

Superseded responses remain fenced by context identity/generation; cancellation alone is not sufficient correctness evidence.

Frontend Foundation implementation completed through FEI-12 at:

```text
1094dad05ec4589a9e83a40fae249eef01591b94
```

This clarification records the finalized HTTP transport and does not supersede the Tenant/Membership switching decision.

# 1. Context

Canonical EduCore identity is:

```text
Person
  │
  ├── optional User
  │
  └── Membership × Tenant
```

Therefore Tenant access is not modeled as:

```text
User owns Tenant
```

Instead:

```text
Person
   ↓
Membership
   ↓
Tenant
```

The current backend already exposes:

```text
GET /api/v1/user/my-memberships
```

for discovery and:

```text
POST /api/v1/user/memberships/{membership_id}/switch
```

for authentication-context exchange.

Repository verification confirms that the switch operation currently:

```text
validates authenticated User

resolves canonical User → Person

validates target Membership belongs to that Person

requires target Membership ACTIVE

requires target Tenant ACTIVE

issues a new membership-scoped bearer

does not include role/permission claims

does not store active Membership in Laravel session

does not store active Tenant in Laravel session

does not revoke the previous bearer automatically
```

The existing feature tests also prove:

```text
Token A → Tenant A
```

and:

```text
Token B → Tenant B
```

remain independently valid after a switch.

This property must be preserved by the browser architecture.

---

# 2. Relationship to ADR-022

ADR-022 changes browser credential custody, but does not change canonical switch semantics.

Previously the backend contract conceptually returns:

```text
access_token = Bearer B
```

to an API client.

For the first-party browser SPA:

```text
Bearer B
```

must instead remain inside the server-side Browser Session Broker.

Therefore browser switching becomes:

```text
React Tab
   │
   │ target Membership B
   ▼
Browser BFF
   │
   │ canonical Token A
   ▼
POST Membership B /switch
   │
   │ receives Token B
   ▼
Server-side credential vault
```

The browser receives safe context information only.

---

# 3. Decision Drivers

The context-switch architecture must guarantee:

```text
1. Per-tab Tenant isolation

2. No bearer credential in browser JavaScript

3. Backend remains final authority

4. Membership ownership cannot be forged client-side

5. Switch failure preserves current valid context

6. No mixed Tenant state

7. Old-context response isolation

8. No optimistic Tenant switching

9. Dirty-form protection

10. No automatic mutation retry

11. Workspace reset on Tenant switch

12. Capability refresh for new Tenant

13. Multi-tab switching independence

14. Maintainable server-state caching

15. Clear recovery behavior
```

---

# 4. Alternatives Considered

Four primary models were considered.

```text
A. Global browser active Tenant

B. Server-side global active Tenant

C. Immediate client commit after switch response

D. Tab-local prepare → verify → commit
```

---

# 5. Option A — Global Browser Active Tenant

Example:

```text
localStorage.activeTenant = B
```

All tabs observe the same value.

### Advantages

- Simple implementation.
- Easy state synchronization.
- Only one current Tenant concept.

### Problem

This directly violates:

```text
Tab A → Tenant A
Tab B → Tenant B
```

It also conflicts with ADR-022 prohibition against globally synchronizing authentication context across tabs.

### Decision

```text
REJECTED
```

---

# 6. Option B — Server-Side Global Active Tenant

Example:

```text
Browser Session
    ↓
active_membership_id = B
```

Every request from the browser session uses B.

### Problem

All tabs share the Browser Session Broker cookie.

Therefore:

```text
Tab B switches A → B
```

would also silently alter Tab A.

This would recreate the exact coupling ADR-022 was designed to avoid.

### Decision

```text
REJECTED
```

The Browser Session Broker may store membership credentials, but MUST NOT own one mutable global:

```text
active_membership_id
```

for the browser session.

---

# 7. Option C — Immediate Commit

Flow:

```text
user chooses B
↓
switch endpoint succeeds
↓
frontend immediately sets active Tenant = B
↓
then bootstrap begins
```

### Problem

The application could temporarily enter:

```text
Tenant B selected
+
Tenant A capabilities
+
Tenant A Workspace
+
Tenant A cached data
```

or discover after committing that `/auth/me` cannot actually establish authoritative context.

### Decision

```text
REJECTED
```

The switch response alone is insufficient to publish the new frontend context.

---

# 8. Option D — Transactional Context Switch

Selected:

```text
PREPARE
   ↓
VERIFY
   ↓
COMMIT
```

If verification fails:

```text
ROLLBACK
```

### Decision

```text
SELECTED
```

---

# 9. Canonical Context Ownership

Frontend Tenant context is owned by:

```text
platform/tenancy
```

under ADR-021.

Business modules consume it.

Business modules MUST NOT own:

```text
activeMembership

activeTenant

Tenant switch state machine
```

independently.

Canonical direction:

```text
Platform Tenancy
       ↓
Business Module
```

not:

```text
Academic Tenant state

Dormitory Tenant state

HR Tenant state
```

---

# 10. Active Context Model

Conceptually the active Tenant context contains:

```text
TenantContext {
    membershipId
    tenantId
    tenantName
    contextGeneration
}
```

Only authoritative data returned from backend bootstrap may populate this state.

In particular:

```text
tenantId
tenantName
```

must not be derived from arbitrary client input.

---

# 11. Membership ID Is a Selector, Not Authority

The frontend may possess:

```text
membership_id
```

because it is needed to select a Membership.

However:

```text
membership_id
≠ credential

membership_id
≠ authorization

membership_id
≠ proof of ownership
```

Tampering with:

```text
membership_id
```

must never grant access.

The Browser Session Broker and canonical backend must validate the selected Membership.

---

# 12. Membership Discovery

Canonical discovery remains:

```text
GET /api/v1/user/my-memberships
```

The current backend returns active Person-owned Membership projections containing:

```text
membership_id

membership_status

tenant_id

tenant_name

tenant_subdomain
```

The frontend switcher MUST be populated from this authoritative discovery.

It must not construct arbitrary Tenant options from:

```text
route parameters

local configuration

hardcoded arrays

role assumptions
```

---

# 13. Discovery Does Not Grant Authority

Even though `/my-memberships` returned Membership B earlier:

```text
B may later become inactive
```

or:

```text
Tenant B may later become inactive
```

Therefore discovery is:

```text
selection vocabulary
```

not permanent authorization.

The canonical switch operation revalidates the Membership at execution time.

---

# 14. Initial Login Context

Current canonical authentication requires an initial Tenant context.

After Browser Authentication succeeds, the BFF has an initial canonical Membership credential.

Conceptually:

```text
Login
 ↓
Membership A / Tenant A
 ↓
server-held Bearer A
```

The React application then bootstraps:

```text
/auth/me
```

and derives the initial authoritative Tenant context.

The browser does not derive it from login form input alone.

---

# 15. Tab-Local Selection

Active Membership selection belongs to the individual tab.

Conceptually:

```text
Tab A
membership = A

Tab B
membership = B
```

A tab may use `sessionStorage` for a non-secret restoration hint:

```text
educore.membership_hint
```

Exact key naming remains implementation detail.

The value is:

```text
non-secret
non-authoritative
tab-local
```

and must always be revalidated.

---

# 16. No Global Active Membership in BFF

Server-side browser session state may contain:

```text
Membership A → Bearer A

Membership B → Bearer B
```

but not:

```text
activeMembership = B
```

as global session authority.

The requesting browser tab supplies its Membership selector on protected BFF requests.

Exact HTTP representation of that selector is deferred to ADR-025.

It may eventually be represented by:

```text
dedicated request header
```

or another explicit browser-BFF transport contract.

What is locked here is its semantic role:

```text
selector only
```

---

# 17. Credential Lookup Rule

For ordinary protected requests:

```text
Browser session
+
Membership selector
```

allows the BFF to locate an already-established canonical credential.

Conceptually:

```text
Browser Session
   │
   ├── Membership A → Bearer A
   └── Membership B → Bearer B
```

Request:

```text
selector = B
```

results in:

```text
Bearer B
```

being attached server-side.

---

# 18. Forged Selector Behavior

Suppose browser JavaScript sends:

```text
membership_id = X
```

where X belongs to another Person.

The BFF MUST NOT:

```text
trust X

construct Tenant X

create authorization state

select arbitrary bearer
```

If there is no legitimate broker-held context:

```text
fail closed
```

Establishing a new Membership credential requires the explicit canonical switch operation.

---

# 19. No Implicit Switch During Ordinary Requests

An ordinary request using:

```text
membership selector = B
```

must not silently execute:

```text
POST Membership B /switch
```

behind the scenes.

Context creation is an explicit operation.

This prevents:

```text
typo/tampering
→ authentication-context mutation
```

and keeps state transitions observable.

---

# 20. Selecting Current Membership

If the user selects the Membership that is already authoritative for the current tab:

```text
targetMembershipId
===
currentMembershipId
```

frontend behavior is:

```text
NO-OP
```

No context transition is required.

No additional token should be requested merely because the user reselected the current option.

---

# 21. Canonical Switch State Machine

Platform tenancy uses conceptual states:

```text
UNRESOLVED
      ↓
READY
      ↓
SWITCHING
      ↓
READY
```

Recovery state may temporarily be:

```text
RECOVERING
```

Authentication failure exits the tenancy state machine into the session/authentication recovery flow defined elsewhere.

---

# 22. Switch Preconditions

Tenant switch can begin only when:

```text
authentication is authoritative

current Membership/Tenant is authoritative

target Membership is known from discovery

target != current

no other Tenant switch is active in this tab

dirty-form policy permits transition
```

If any precondition fails:

```text
switch does not start
```

---

# 23. Dirty Form Gate

Before switching:

```text
current page
   ↓
dirty?
```

If yes:

```text
confirm discard
```

If user cancels:

```text
Tenant switch
= cancelled
```

No backend switch request is made.

Tenant context transitions must never silently discard unsaved business input.

---

# 24. One Switch Per Tab

Only one Tenant switch may execute at a time per tab.

While:

```text
SWITCHING
```

the Tenant switch control is:

```text
disabled / pending
```

A second target cannot race against the first.

This prevents:

```text
A → B
and
A → C
```

from competing for final active context.

---

# 25. Phase 1 — Transition Start

Starting the transition:

```text
READY(A)
    ↓
SWITCHING(A → B)
```

immediately causes the current business UI to become non-interactive.

Existing A data may remain visually present during the transition, but it must not accept business actions.

Frontend also:

```text
blocks new business mutations

cancels superseded reads where possible

marks existing request generation obsolete
```

---

# 26. Why Current Data May Remain Visible

There is no requirement to blank the entire application instantly.

Keeping Tenant A content visible beneath a transition state can reduce visual disruption.

However:

```text
visible
≠ interactive
```

During switch it must be unmistakable that context transition is in progress.

---

# 27. Phase 2 — Prepare Target Credential

The BFF invokes canonical:

```text
POST /api/v1/user/memberships/{B}/switch
```

using a legitimate authenticated credential from the current browser session.

Canonical backend validates:

```text
User

Person

Membership B ownership

Membership B active state

Tenant B active state
```

and produces:

```text
Bearer B
```

---

# 28. Bearer B Custody

Under ADR-022:

```text
Bearer B
```

is never returned to React.

It is stored server-side within the Browser Session Broker.

Conceptually:

```text
Browser Session
├── A → Bearer A
└── B → Bearer B
```

The browser may receive safe target context metadata, but that metadata is not sufficient for commit.

---

# 29. Previous Credential Is Preserved

Canonical backend behavior intentionally does not revoke Bearer A.

Therefore during preparation:

```text
Bearer A remains usable
```

This gives the context transaction a safe recovery point.

It also preserves:

```text
another tab
still using Tenant A
```

---

# 30. Phase 3 — Verify `/auth/me`

The frontend next requests target context bootstrap using Membership B through the BFF:

```text
GET /api/v1/auth/me
```

resolved server-side using:

```text
Bearer B
```

Expected authoritative result:

```text
membership.id = B

tenant.id = expected Tenant B
```

This response establishes canonical target identity.

---

# 31. Consistency Check

The candidate must satisfy:

```text
requested membership
===
/auth/me membership.id
```

and target context returned by the switch operation must agree with `/auth/me`.

If there is an impossible mismatch:

```text
CONTRACT / SECURITY FAILURE
```

The switch MUST NOT commit.

The inconsistency must be logged through safe observability without credential leakage.

---

# 32. Phase 4 — Target Workspace Discovery

Before activating normal business navigation:

```text
GET /api/v1/user/my-workspaces
```

is executed under candidate Membership B.

This establishes available organizational contexts for Tenant B.

Workspace selection itself remains governed by ADR-024.

---

# 33. Workspace Reset

Tenant switch does not preserve the previous Workspace.

Example:

```text
Tenant A
Workspace X
```

switching to:

```text
Tenant B
```

must result in:

```text
Tenant B
Tenant-level Workspace
```

not:

```text
Tenant B
Workspace X
```

even if an assignment with a similar identifier happens to exist.

---

# 34. Workspace Restoration After Tenant Switch

Fresh Tenant switch intentionally chooses:

```text
Tenant-level safe context
```

rather than automatically restoring a previously remembered Workspace for Tenant B.

Reason:

Tenant transition itself is security-sensitive.

The safe deterministic sequence is:

```text
Tenant B
↓
Tenant-level context
↓
user may then select Workspace
```

Normal browser reload restoration is a different operation governed by ADR-024.

---

# 35. Phase 5 — Tenant Capability Projection

Candidate Tenant B loads:

```text
GET /api/v1/core/authorization/capabilities
```

without an organizational-assignment header.

This establishes Tenant-level effective capabilities.

Old capabilities from Tenant A:

```text
MUST NOT
```

be temporarily reused for Tenant B.

---

# 36. Capability Failure

If capability projection for candidate Tenant B cannot be established:

```text
target protected UI
must remain closed
```

The application MUST NOT activate Tenant B using Tenant A capabilities.

Depending on the canonical failure category, the transition either:

```text
rolls back to A
```

or:

```text
enters authentication/session recovery
```

It never fails open.

---

# 37. Phase 6 — Atomic Commit

Only after target bootstrap is authoritative does the frontend commit.

Conceptually:

```text
candidate = {
    membership: B,
    tenant: B,
    workspace: TENANT,
    capabilities: B
}
```

then:

```text
atomic publish
```

produces:

```text
active = candidate
```

At this moment:

```text
Membership B
Tenant B
Tenant-level Workspace B
Capabilities B
```

become one coherent active context.

---

# 38. Commit Is the Frontend Context Boundary

Token issuance alone:

```text
≠ frontend switch complete
```

Successful `/auth/me` alone:

```text
≠ frontend switch complete
```

The frontend considers switching complete only after required context bootstrap has reached a coherent commit point.

---

# 39. Post-Commit Navigation

After commit:

```text
navigate /dashboard
```

Tenant switching intentionally does not attempt to preserve the previous business route.

Example:

```text
Tenant A
/dormitory/residents/123
```

must not automatically become:

```text
Tenant B
/dormitory/residents/123
```

because route identifiers and permissions may have different meaning.

Safe destination:

```text
/dashboard
```

---

# 40. Transition Success

Final transition:

```text
READY(A)
 ↓
SWITCHING(A → B)
 ↓
PREPARED(B)
 ↓
VERIFIED(B)
 ↓
COMMIT
 ↓
READY(B)
 ↓
/dashboard
```

---

# 41. Rollback Principle

If the transition cannot reach commit:

```text
active context remains A
```

provided A itself is still authoritative.

Candidate B must never be partially published.

Conceptually:

```text
A active
+
B candidate
```

is permitted internally during transition.

But:

```text
Tenant B shell
+
Tenant A permissions
```

is never permitted.

---

# 42. Failure Before Token Issuance

Examples:

```text
network failure

AUTHENTICATION_REQUIRED

MEMBERSHIP_SWITCH_DENIED

INTERNAL_SERVER_ERROR
```

before authoritative candidate B is established.

Default:

```text
do not commit B
```

For:

```text
MEMBERSHIP_SWITCH_DENIED
```

frontend:

```text
preserves A

refreshes /my-memberships

shows safe failure feedback
```

It does not logout.

---

# 43. Stale Membership Discovery

Example:

```text
/my-memberships
previously contained B
```

but B has since been suspended.

Switch returns:

```text
403
MEMBERSHIP_SWITCH_DENIED
```

Frontend behavior:

```text
keep A
↓
refresh membership discovery
↓
remove unavailable B from selector
```

No role/permission inference is attempted.

---

# 44. Failure After Bearer B Is Issued

A more subtle case:

```text
canonical switch succeeds
↓
BFF stores Bearer B
↓
browser loses network
```

The tab has not committed B.

Therefore:

```text
active remains A
```

Bearer B may exist as an unused server-side credential.

This does not constitute partial frontend switch state.

Broker credential lifecycle must remain bounded through ADR-022/backend implementation rules.

---

# 45. Candidate Bootstrap Failure

Example:

```text
Bearer B issued
↓
Membership B becomes inactive
↓
/auth/me under B fails
```

The target context is invalid.

Frontend:

```text
does not commit B
↓
preserves/revalidates A
↓
refreshes Membership discovery
```

If A is also no longer authoritative, normal authentication recovery takes precedence.

---

# 46. No Automatic Switch Retry

Tenant switch is a mutation-like context exchange.

Default:

```text
automatic retry = OFF
```

A network failure may occur after the server has already created Bearer B.

Automatically issuing another POST could create unnecessary credentials and obscure the true transaction state.

The user may explicitly retry after recovery/reconciliation.

---

# 47. Same-Membership No-Op Prevents Token Churn

Because:

```text
select active Membership
```

is a no-op, accidental repeated selection does not repeatedly issue credentials.

This avoids unnecessary authentication-token churn for the most common duplicate action.

---

# 48. Context Generation

Each authoritative frontend context carries a logical:

```text
contextGeneration
```

or equivalent monotonically distinct identity.

Example:

```text
Tenant A
generation 17
```

after successful switch:

```text
Tenant B
generation 18
```

The exact implementation may use a numeric epoch, unique transition token, or equivalent mechanism.

The invariant is what matters:

```text
requests know
which context generation created them
```

---

# 49. Request Context Snapshot

Every context-sensitive request conceptually captures:

```text
{
    membershipId,
    tenantId,
    workspace,
    contextGeneration
}
```

at request start.

A response may update currently rendered protected UI only if it still belongs to the relevant active context.

---

# 50. Old-Context Response Fence

Example:

```text
Request R1
Tenant A / generation 17
starts
```

then:

```text
switch → Tenant B / generation 18
```

then R1 completes.

Result:

```text
R1 may be cached only
inside its correct isolated partition
if architecture permits

R1 MUST NOT update
Tenant B active UI
```

This is mandatory regardless of network cancellation support.

---

# 51. Cancellation Is Not the Security Boundary

Frontend should cancel superseded requests where possible.

However:

```text
AbortController
```

or library cancellation is not sufficient as the only correctness control.

Requests may already have reached the server or may complete despite lifecycle changes.

Therefore correctness relies on:

```text
context-aware request identity
+
response fencing
```

not cancellation alone.

---

# 52. Switch-Start Fencing

When a switch begins, currently in-flight business requests are considered superseded for interactive rendering.

If the switch later fails and A remains active:

```text
A may be refetched
```

under a fresh active request generation.

This is safer than allowing pre-transition requests to race through a failed switch recovery.

---

# 53. Server-State Cache Identity

ADR-023 locks the Tenant portion of cache identity:

```text
resource
+
membershipId / tenantId
+
workspace when applicable
+
query parameters
```

Example:

```text
Students / Membership A / Tenant A
```

is never the same cache identity as:

```text
Students / Membership B / Tenant B
```

Detailed TanStack Query implementation remains ADR-026.

---

# 54. Old Tenant Cache

A successful Tenant switch does not require every inactive partition to be physically destroyed immediately for correctness.

An implementation may retain bounded Tenant A cache for later reuse if:

```text
it remains strictly partitioned

it is no longer rendered as current state

it cannot authorize Tenant B UX

logout clears protected cache
```

The active observer/context must detach from Tenant A immediately on commit.

Cache-retention policy belongs to ADR-026.

---

# 55. Mutations During Switching

From:

```text
SWITCHING
```

until either commit or rollback:

```text
business mutations = blocked
```

Examples:

```text
create student

delete room

assign role

record payment
```

must not begin while Tenant identity is transitioning.

This prevents ambiguity over which Tenant receives the operation.

---

# 56. Platform Recovery Requests

Blocking business mutations does not prohibit platform requests needed to finish or recover the transition.

Allowed examples:

```text
switch operation

/auth/me verification

/my-memberships refresh

/my-workspaces discovery

capability projection

session recovery
```

---

# 57. Multi-Tab Isolation Example

Browser session contains:

```text
A → Bearer A

B → Bearer B
```

Tab A:

```text
activeMembership = A
```

Tab B:

```text
activeMembership = B
```

Tab B switches:

```text
B → C
```

Only Tab B changes.

Tab A:

```text
remains A
```

There is:

```text
no BroadcastChannel Tenant synchronization

no localStorage active Tenant

no global BFF active Membership
```

---

# 58. Same Tenant in Multiple Tabs

Two tabs may independently select the same Membership:

```text
Tab A → Membership B

Tab B → Membership B
```

This does not require independent browser-visible credentials.

They may resolve to the same valid broker-held membership credential because:

```text
authentication context is identical
```

while Workspace context may still differ per tab.

Credential reuse/replacement inside the BFF is an implementation concern as long as context correctness and lifecycle guarantees remain intact.

---

# 59. Broker Credential Replacement

Repeated legitimate switches can issue replacement credentials for the same Membership.

The BFF must avoid an unbounded in-memory collection of obsolete bearer credentials.

Canonical conceptual ownership is:

```text
Browser Session
   ↓
bounded current credential set
   ↓
Membership → usable credential
```

Exact replacement/revocation timing belongs to the backend Browser Authentication workstream.

It must account for in-flight requests and canonical token expiry.

---

# 60. No Silent Cross-Membership Fallback

If the active Membership's authentication context becomes invalid, frontend/BFF MUST NOT silently jump to another Membership merely because another valid broker credential exists.

Example rejected:

```text
Membership A invalid
↓
automatically use Membership B
```

That could cause an unexpected security-context change.

Recovery must be explicit according to authentication UX.

---

# 61. Tenant Switch vs Workspace Switch

The distinction is architectural:

## Tenant switch

```text
Membership changes

Tenant changes

authentication credential context changes

Workspace resets

capabilities reload

/dashboard
```

## Workspace switch

```text
Membership unchanged

Tenant unchanged

credential unchanged

organizational context changes
```

Workspace behavior is ADR-024.

These operations MUST NOT share one ambiguous:

```text
switchContext()
```

implementation with hidden mode-dependent behavior.

---

# 62. Tenant Switch vs Role Change

Tenant switching is not role switching.

Frontend MUST NOT send:

```text
role
```

to select Tenant authorization.

Role/permission changes are independent authorization-domain operations.

After Tenant commit:

```text
capability projection
```

determines effective runtime UI.

---

# 63. Tenant Switch vs User Identity

Switching Membership does not change:

```text
Person
```

or:

```text
User
```

The authenticated digital identity remains the same.

The changed part is:

```text
Membership/Tenant authentication context
```

This distinction should remain visible in architecture naming.

---

# 64. Naming Rule

Prefer terms:

```text
Membership context

Tenant context

switch Membership

Tenant/Membership switch
```

Avoid ambiguous terms such as:

```text
change account
change user
login as Tenant
```

unless the actual operation changes User identity.

---

# 65. Error Semantics

Switch orchestration branches on:

```text
HTTP status
+
canonical machine code
```

Never:

```text
error.message.includes(...)
```

Important responses include:

```text
AUTHENTICATION_REQUIRED

AUTHENTICATION_CONTEXT_DENIED

MEMBERSHIP_SWITCH_DENIED

INTERNAL_SERVER_ERROR

NETWORK
```

Future canonical errors are handled safely as unknown/contract failures when appropriate.

---

# 66. Authentication Failure Has Priority

If during switching the canonical current authentication session itself becomes invalid:

```text
security recovery
>
switch rollback UX
```

Frontend does not attempt to preserve a context that is no longer authoritative.

Protected state is cleared according to ADR-022/FE-7.

---

# 67. Switch Failure Is Not Logout

A valid current context plus:

```text
MEMBERSHIP_SWITCH_DENIED
```

means:

```text
target unavailable
```

not:

```text
current authentication invalid
```

Therefore frontend:

```text
does not logout
```

---

# 68. Loading UX

Tenant switch uses:

```text
application-level context transition
```

rather than a tiny button spinner while the old application remains fully interactive.

The user should still understand:

```text
switching to <Tenant Name>
```

without exposing sensitive implementation details.

---

# 69. Tenant Identification During Transition

The UI may show:

```text
Switching to School B...
```

using the discovery projection for user feedback.

But final application header must only display Tenant B as active after authoritative commit.

Before commit:

```text
discovery label
≠ active context
```

---

# 70. Browser Reload During Switching

A full browser reload destroys the in-memory transition transaction.

After reload:

```text
no partial candidate state is trusted
```

The application re-enters normal bootstrap.

It uses:

```text
browser session
+
tab-local Membership restoration hint
+
authoritative backend validation
```

If the hint still points to A because B was never committed:

```text
A is restored
```

This is intentional.

---

# 71. Commit and Restoration Hint

Only after successful context commit should the tab's restoration hint be replaced:

```text
A
↓
B
```

Do not persist B before verification.

Otherwise a reload during failed switching could incorrectly attempt to restore an uncommitted target.

---

# 72. Logout Cleanup

Logout must clear tab-local Tenant restoration state including:

```text
membership hint

workspace hint

context-generation state
```

Protected query/client caches must also be cleared according to ADR-026.

A later User must never inherit authoritative context from the previous login.

---

# 73. Observability

Tenant switching should emit safe operational events such as:

```text
tenant_switch_started

tenant_switch_succeeded

tenant_switch_denied

tenant_switch_bootstrap_failed

tenant_switch_rolled_back
```

Safe metadata may include justified identifiers such as:

```text
source membership ID

target membership ID

request/correlation ID

application version
```

subject to privacy policy.

Never record:

```text
bearer credential

authentication cookie

password
```

---

# 74. Audit Boundary

Frontend telemetry is not the canonical security audit trail.

Canonical backend operations such as Membership switch should continue producing backend audit/log events where required.

Frontend observability exists to diagnose:

```text
UX

network

runtime

transition
```

behavior.

---

# 75. Browser-BFF Transport

ADR-023 establishes the requirement:

```text
every protected BFF request
must identify its intended Membership context
```

where needed.

It intentionally does not yet lock:

```text
header name

request envelope

URL structure

generated-client adaptation
```

Those belong to:

```text
ADR-025
API Client, OpenAPI
& Canonical Error Handling
```

The transport must preserve the semantic rule:

```text
Membership selector
= locator only
```

---

# 76. Why Membership Selector Should Be Explicit

The BFF cannot infer the requesting tab from an HttpOnly cookie because the same browser session cookie is shared across tabs.

Therefore the request needs explicit context information originating from tab-local state.

This is not a security weakness because:

```text
selector is untrusted
```

and canonical credentials/backend validation remain authoritative.

---

# 77. No Tab Identifier Required for Authority

ADR-023 does not require storing a server-side:

```text
tab_id → active Tenant
```

map.

The essential model can remain:

```text
browser session
+
request Membership selector
+
server credential map
```

This avoids additional server lifecycle complexity.

A technical tab identifier may still be introduced later for observability or UX if demonstrated necessary, but it cannot become authorization authority.

---

# 78. Security Properties

The selected architecture ensures:

```text
forged membership ID
≠ access

stale discovery
≠ access

old Tenant cache
≠ new Tenant authorization

switch response
≠ commit

browser cookie
≠ active Tenant

sessionStorage membership hint
≠ authority

React state
≠ backend authority
```

---

# 79. Frontend Ownership

Under ADR-021:

```text
platform/tenancy/
```

owns:

```text
Membership discovery

active Tenant/Membership projection

switch state machine

restoration hint

transition fencing

switch orchestration interface
```

It does not own:

```text
Workspace implementation

capability calculation

business module data
```

It orchestrates those platform capabilities during transition through explicit contracts.

---

# 80. Composition Principle

Tenancy should not directly absorb all downstream implementations.

Prefer composition such as:

```text
TenantSwitchCoordinator
     │
     ├── Session/Auth contract
     ├── Tenancy contract
     ├── Workspace contract
     ├── Capability contract
     └── Query/context invalidation contract
```

rather than one giant:

```text
TenantStore
```

owning all application state.

Exact TypeScript abstractions remain TDD concerns.

---

# 81. Test Requirements

Implementation must prove at minimum:

```text
1. membership list only drives selectable candidates.

2. arbitrary membership ID cannot switch ownership.

3. inactive membership is rejected.

4. inactive Tenant is rejected.

5. selecting current Membership is a no-op.

6. only one switch executes per tab.

7. dirty form can cancel switch before backend mutation.

8. switch blocks business mutations.

9. Bearer B never reaches React.

10. old Bearer A remains usable for another tab.

11. target /auth/me must succeed before commit.

12. /auth/me mismatch prevents commit.

13. Workspace resets to Tenant context.

14. Tenant B capabilities load without Tenant A reuse.

15. switch failure preserves A when A remains valid.

16. MEMBERSHIP_SWITCH_DENIED does not logout.

17. stale membership discovery is refreshed after denial.

18. old A response cannot update B UI.

19. reload during uncommitted switch restores authoritative context.

20. restoration hint changes only after commit.

21. Tab A switching does not alter Tab B.

22. same browser can maintain Tenant A and Tenant B concurrently.

23. no automatic retry occurs for switch POST.

24. successful switch routes to /dashboard.

25. logout clears Tenant restoration state.
```

---

# 82. Integration/Race Tests

Critical race-condition tests include:

```text
Request A starts
↓
Tenant switch begins
↓
Request A returns
↓
response ignored for active rendering
```

and:

```text
switch B succeeds server-side
↓
candidate bootstrap delayed
↓
old A response returns
↓
candidate/active B state not contaminated
```

and:

```text
switch B fails
↓
old pre-switch request A completes
↓
old request still not blindly re-applied
↓
A recovery uses fresh generation/refetch
```

---

# 83. Backend Regression Requirements

Existing backend behavior must remain covered:

```text
old token remains independent after switch

new token contains target membership_id

new token contains target tenant_id

role claim absent

permission claim absent

/auth/me verifies new target context

switch remains stateless with respect to Laravel active Tenant session
```

Browser BFF tests are additive.

---

# 84. Architectural Invariants

If ADR-023 is accepted:

```text
Tenant switch
= Membership authentication-context switch

Membership discovery
= /api/v1/user/my-memberships

canonical backend switch
= POST /api/v1/user/memberships/{id}/switch

canonical verification
= /api/v1/auth/me

active Membership
= tab-local

BFF global active Membership
= FORBIDDEN

localStorage active Tenant
= FORBIDDEN

membership hint
= selector/restoration hint only

switch model
= prepare → verify → commit

optimistic Tenant commit
= FORBIDDEN

automatic switch retry
= FORBIDDEN

current Membership reselection
= NO-OP

business mutations during switch
= BLOCKED

old Workspace after Tenant switch
= CLEARED

new initial Workspace
= TENANT

old capability reuse
= FORBIDDEN

successful switch destination
= /dashboard

old-context response contamination
= FORBIDDEN

context-sensitive request identity
= REQUIRED

backend
= final context authority

role-name Tenant selection
= FORBIDDEN
```

---

# 85. Consequences

## Positive

- Multi-tab Tenant isolation is preserved.
- Browser never owns bearer credentials.
- Failed switches have a reliable rollback path.
- No mixed Tenant/Workspace/capability state.
- Race conditions become explicit architecture concerns.
- Business modules receive one coherent context.
- Backend canonical switch semantics remain intact.
- Active Tenant is not turned into mutable server-session global state.
- Context-aware caching becomes deterministic.
- Security decisions do not depend on trusting client identifiers.

## Costs

- Switch requires multiple bootstrap operations.
- Transition orchestration is more complex than changing one global variable.
- Request context must be explicit.
- Race-condition testing is mandatory.
- BFF must maintain bounded per-Membership credentials.
- Frontend needs a context-generation/fencing mechanism.
- Switch may take slightly longer because authoritative target state is verified before activation.

These costs are accepted because Tenant mixing is a correctness and security failure, not merely a UX defect.

---

# 86. Explicit Non-Decisions

ADR-023 does not decide:

```text
exact Membership selector header

exact BFF route names

exact sessionStorage key

TanStack Query key factory implementation

cache staleTime/gcTime

Workspace restoration mechanics

Workspace switching mechanics

capability state API

React provider structure

state-management library

exact transition component UI

BFF credential replacement timing

CSRF implementation
```

These belong to later ADR/TDD work.

---

# 87. Follow-Up Dependency

ADR-023 establishes:

```text
Authenticated Membership/Tenant
        ↓
Workspace
```

Therefore the next architectural decision is:

```text
ADR-024
Workspace / Organizational Context Management
```

ADR-024 must preserve:

```text
Tenant switch
→ authentication-context exchange

Workspace switch
→ runtime organizational-context change only
```

and must never merge the two operations.

---

# ADR-023 Proposed State

```text
ADR-023 — Tenant / Membership Context Switching

Status:
🔒 ACCEPTED / LOCKED

Selected:
Tab-local Membership context
+
server-side membership bearer map
+
prepare → verify → commit transition

Global browser active Tenant:
❌ REJECTED

Global BFF active Membership:
❌ REJECTED

Immediate optimistic switch:
❌ REJECTED

Canonical discovery:
GET /api/v1/user/my-memberships

Canonical backend switch:
POST /api/v1/user/memberships/{membership_id}/switch

Canonical target verification:
GET /api/v1/auth/me

Workspace after switch:
TENANT safe context

Capabilities after switch:
fresh Tenant projection

Successful destination:
/dashboard

Old-context response contamination:
❌ FORBIDDEN

Automatic switch retry:
❌ FORBIDDEN

Multi-tab Tenant isolation:
✅ PRESERVED

Backend foundation:
✅ PRESERVED
```
