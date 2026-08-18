# ADR-031 — Frontend Observability & Performance Strategy

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-18
**Scope** : Frontend Foundation — Runtime Observability, Error Reporting, RUM, Performance Budgets, Release Correlation & Privacy

---

> ## Decision Summary
>
> EduCore Frontend akan menggunakan **vendor-neutral observability architecture** melalui:
>
> ```text
> platform/observability
> ```
>
> Business module tidak boleh bergantung langsung pada:
>
> ```text
> Sentry
> Datadog
> New Relic
> OpenTelemetry SDK
> analytics vendor
> ```
>
> atau provider observability tertentu.
>
> Canonical frontend observability contract mencakup:
>
> ```text
> Runtime Errors
> API Failures
> Contract Failures
> Route / Chunk Failures
> Security-Relevant Client Events
> Core Web Vitals
> SPA Navigation Timings
> Context Transition Timings
> Release / Build Identity
> Backend Correlation Metadata
> ```
>
> Frontend performance akan dinilai melalui dua complementary sources:
>
> ```text
> CI / Build-Time
> → bundle budgets
> → production build integrity
>
> Real User Monitoring
> → actual browser performance
> → Core Web Vitals
> → SPA transition timings
> ```
>
> Core Web Vitals target tetap mengikuti Frontend PRD:
>
> ```text
> p75 LCP ≤ 2.5s
> p75 INP ≤ 200ms
> p75 CLS ≤ 0.1
> ```
>
> Threshold tersebut masih merupakan current Core Web Vitals "good" thresholds. ([web.dev][1])
>
> `web-vitals` menjadi preferred browser collector untuk Core Web Vitals, tetapi berada di balik platform observability adapter. Library tersebut mendukung pengumpulan Core Web Vitals dan diagnostic metrics tanpa menentukan telemetry vendor. ([GitHub][2])
>
> SPA-specific operations seperti:
>
> ```text
> Application Bootstrap
> Route Navigation
> Tenant Switch
> Workspace Switch
> ```
>
> tidak akan dipalsukan sebagai Core Web Vitals. Mereka diukur sebagai **EduCore custom performance measurements** menggunakan browser Performance/User Timing APIs.
>
> W3C Performance Timeline menyediakan `PerformanceObserver`, sedangkan User Timing menyediakan application-defined high-resolution measurements untuk kebutuhan seperti ini. ([W3C][3])
>
> Production source maps boleh dihasilkan untuk debugging, tetapi:
>
> ```text
> MUST NOT
> be publicly deployed with static application assets
> ```
>
> Vite mendukung `hidden` source maps; file map tetap dihasilkan tetapi bundle tidak mereferensikannya melalui source-map comment. EduCore CI kemudian harus mengunggahnya ke private observability storage/provider dan mengecualikannya dari public CDN artifact. ([vitejs][4])

---

# 1. Context

Frontend Foundation sekarang telah menetapkan:

```text
React SPA
↓
Static/CDN delivery
↓
Browser Session BFF
↓
Tenant/Membership Context
↓
Workspace Context
↓
Capability Projection
↓
TanStack Query
↓
Modular Routing
```

Architecture tersebut menciptakan beberapa failure/performance boundaries yang tidak dapat didiagnosis hanya melalui Laravel logs.

Contoh:

```text
React runtime exception

lazy route chunk failure

browser network failure

malformed API response

stale context response

slow application bootstrap

slow Tenant switch

slow Workspace switch

poor INP

layout instability
```

Backend observability tetap diperlukan, tetapi browser memiliki visibility terhadap experience yang backend tidak dapat lihat secara lengkap.

---

# 2. Current Repository State

Repository saat ini belum memiliki frontend:

```text
observability SDK

RUM collector

error-reporting provider

Web Vitals implementation

source-map upload pipeline

frontend release metadata contract
```

Repository juga belum memiliki canonical:

```text
request_id
trace_id
correlation_id
```

contract pada frontend.

Karena itu ADR-031 menetapkan boundary dan semantics terlebih dahulu tanpa mengikat EduCore pada vendor tertentu.

---

# 3. Decision Drivers

Observability strategy harus memenuhi:

```text
1. Production debugging capability

2. Real-user performance visibility

3. Tenant security/privacy

4. Vendor portability

5. Low runtime overhead

6. CSP compatibility

7. Release correlation

8. Backend request correlation

9. Bundle regression detection

10. Module ownership

11. Failure isolation

12. Multi-tab context awareness

13. No secret leakage

14. No domain payload dumping

15. Horizontal scale readiness
```

---

# 4. Alternatives Considered

## Option A — Direct Vendor SDK Usage Throughout Features

Contoh:

```text
Dormitory
→ Sentry.captureException()

Academic
→ Sentry.addBreadcrumb()

HR
→ Sentry.setUser()
```

### Problem

Ini mengikat business modules ke infrastructure vendor.

Akibatnya:

```text
provider replacement
↓
application-wide refactor
```

serta membuat privacy policy sulit dikontrol secara terpusat.

### Decision

```text
REJECTED
```

---

# 5. Option B — Console Logs Only

Contoh:

```text
console.error(error)
```

### Problem

Tidak memberikan production aggregation, release correlation, RUM, atau cross-user failure visibility.

### Decision

```text
REJECTED
```

---

# 6. Option C — Backend Logs Only

Backend logs dapat melihat:

```text
API request

server exception

database errors
```

tetapi tidak dapat secara lengkap melihat:

```text
React exception

chunk load failure

layout instability

browser network disconnect

SPA rendering delay
```

### Decision

```text
REJECTED
as complete frontend observability strategy
```

Backend logs tetap complementary.

---

# 7. Option D — Vendor-Neutral Platform Port

Selected:

```text
Business / Platform Code
        ↓
Observability Port
        ↓
Provider Adapter
        ↓
Telemetry Backend
```

### Decision

```text
SELECTED
```

---

# 8. Frontend Ownership

Canonical owner:

```text
platform/observability
```

Responsibilities:

```text
event API

error normalization

safe metadata

privacy filtering

sampling

release context

performance metrics

provider adapter
```

Business modules consume only the public observability contract.

---

# 9. Conceptual Observability API

Conceptually:

```ts
captureException(...)
captureEvent(...)
recordMetric(...)
recordTiming(...)
```

plus controlled contextual metadata.

Exact TypeScript interface remains TDD detail.

The API should remain intentionally small.

---

# 10. No Vendor Types Outside Adapter

Forbidden:

```text
modules/*
→ import vendor SDK types
```

or:

```text
platform/workspace
→ vendor-specific transaction API
```

Provider-specific objects remain inside the provider adapter.

---

# 11. Provider Is Not Yet Selected

ADR-031 intentionally does not choose:

```text
Sentry

Datadog

New Relic

Grafana Faro

OpenTelemetry collector vendor

other provider
```

The selected architecture must permit any suitable provider that satisfies:

```text
security

privacy

CSP

performance

operational

cost
```

requirements.

---

# 12. No Remote Runtime Script Requirement

ADR-030 remains authoritative:

```text
third-party runtime script tags
= forbidden by default
```

Therefore an observability provider requiring:

```html
<script src="https://vendor.example/sdk.js">
```

is not preferred.

Provider SDK should normally be:

```text
package dependency
↓
bundled with Vite
```

and compatible with production CSP.

---

# 13. Signal Categories

Frontend observability recognizes:

```text
ERROR

EVENT

PERFORMANCE

SECURITY

RELEASE
```

as conceptual categories.

Exact event schema is implementation detail.

---

# 14. Runtime Errors

Capture at minimum:

```text
unhandled React errors

route error-boundary errors

unhandled promise rejections

unexpected JavaScript runtime exceptions
```

These should include safe:

```text
routeId

module

releaseId

environment
```

context.

---

# 15. Expected Errors Are Not Exceptions

Expected application outcomes such as:

```text
VALIDATION_FAILED

AUTHORIZATION_DENIED

RESOURCE_NOT_FOUND
```

should not automatically generate high-severity exception reports.

They may produce structured operational events when useful.

This prevents ordinary user behavior from overwhelming error monitoring.

---

# 16. Contract Failures Are High Signal

ADR-025 `ContractFailure` represents cases such as:

```text
malformed bootstrap

invalid error envelope

impossible context mismatch
```

These should normally be reported because they indicate:

```text
frontend/backend contract drift
or
unexpected runtime corruption
```

---

# 17. Chunk Load Failures

ADR-028 establishes lazy modules.

Failures loading application chunks must be separately observable.

Useful metadata:

```text
routeId

module

releaseId

chunk failure category
```

Avoid sending raw full URLs when unnecessary.

---

# 18. API Failure Observability

API observability should prefer:

```text
OpenAPI operationId

HTTP status

machine code

duration

correlation metadata
```

rather than:

```text
raw URL

request body

response body
```

Example:

```text
operation:
user.memberships.switch

status:
403

code:
MEMBERSHIP_SWITCH_DENIED
```

is more useful and safer than logging the entire HTTP exchange.

---

# 19. Network Failure

Browser:

```text
NETWORK
```

failure should be distinguishable from:

```text
SERVER 500
```

and:

```text
AUTHENTICATION failure
```

Telemetry preserves the same canonical failure distinction as ADR-025.

---

# 20. Context Transition Events

Useful operational signals include:

```text
tenant_switch_started

tenant_switch_succeeded

tenant_switch_failed

workspace_switch_started

workspace_switch_succeeded

workspace_recovery_started

workspace_fallback_to_tenant
```

These are diagnostic events, not domain audit logs.

---

# 21. Frontend Telemetry Is Not Audit Logging

Security/business audit remains backend responsibility.

Frontend observability cannot prove that a protected action happened canonically.

Example:

```text
frontend event:
"role_assignment_clicked"
```

does not prove:

```text
role assignment committed
```

Canonical audit belongs to backend transaction outcome.

---

# 22. Release Identity

Every production frontend build must expose a public, non-secret:

```text
releaseId
```

constructed from one or more:

```text
git SHA

semantic application version

CI build ID
```

Exact formatting remains implementation detail.

---

# 23. Release ID Is Immutable Per Artifact

A built artifact must not change release identity after deployment.

Conceptually:

```text
Build A
→ Release abc123

same Build A deployed to another node
→ still abc123
```

This allows runtime errors to map back to exact source.

---

# 24. Release Metadata

Safe baseline:

```text
releaseId

build time/version

environment
```

No production secret may be embedded.

ADR-030 browser-environment-is-public rule remains authoritative.

---

# 25. Route Identity

Telemetry should use:

```text
routeId
```

rather than raw URL where possible.

Example:

```text
dormitory.residents.view
```

instead of:

```text
/dormitory/residents/
2e95b4...
```

This reduces unnecessary record-ID leakage.

---

# 26. Module Identity

Telemetry may safely classify:

```text
platform

academic

hr

dormitory
```

for operational grouping.

Business payload is not required.

---

# 27. Authentication Identity Privacy

By default frontend telemetry MUST NOT attach:

```text
name

email

phone

password

Person profile
```

to every event.

---

# 28. User Identifiers

Raw internal:

```text
user_id

person_id
```

should not automatically become global telemetry dimensions.

If a provider needs user-level debugging, it requires an allowlisted operational/privacy decision.

Prefer:

```text
minimal pseudonymous/opaque context
```

where sufficient.

---

# 29. Tenant Metadata

Likewise:

```text
tenant name

school name
```

should not become a default global telemetry field.

Tenant identifiers have both privacy and high-cardinality implications.

They may be included only on explicitly approved events where operational value justifies them.

---

# 30. Workspace Metadata

Do not attach:

```text
organization label

unit label
```

globally.

Prefer safe context category:

```text
workspaceType:
TENANT | ORGANIZATION | ORGANIZATION_UNIT
```

where sufficient.

---

# 31. Privacy Model

Telemetry metadata uses:

```text
ALLOWLIST
```

rather than:

```text
collect everything
then redact known secrets
```

Canonical direction:

```text
unknown field
→ not sent
```

This is safer than blacklist-based telemetry.

---

# 32. Forbidden Telemetry Data

Never send:

```text
Authorization

bearer credential

browser session cookie

CSRF secret

password

raw sensitive form

full API payload

raw student record

raw employee record

raw resident record

future financial payload
```

---

# 33. Query Parameters

Raw:

```text
location.href
```

should not be sent automatically.

Query parameters may contain:

```text
search

resource IDs

filters

potentially sensitive values
```

Use route IDs and allowlisted navigation metadata instead.

---

# 34. Console Logging

Production application should not rely on:

```text
console.log
```

as observability infrastructure.

Debug logging must be bounded and must respect the same redaction rules.

---

# 35. Observability Must Never Break Product Flow

Telemetry transport failure:

```text
MUST NOT
```

break:

```text
login

Tenant switch

Workspace switch

business mutation

route navigation
```

Observability is secondary infrastructure.

---

# 36. Telemetry Retry

Observability transport may use a bounded provider-controlled retry strategy.

It must never create:

```text
infinite telemetry retry
```

or significantly compete with application traffic.

---

# 37. No Persistent Offline Telemetry Queue

Foundation v1 does not persist telemetry to:

```text
IndexedDB

localStorage

Service Worker queues
```

for later upload.

This follows ADR-030's no-Service-Worker and sensitive-browser-storage direction.

---

# 38. Sampling

Sampling is controlled centrally in:

```text
platform/observability
```

not independently per module.

Different signals may use different policies.

Example direction:

```text
critical runtime failures
→ high capture rate

RUM performance
→ sampled

high-volume informational events
→ more aggressively sampled
```

Exact percentages depend on provider/cost/traffic and remain implementation configuration.

---

# 39. Core Web Vitals

Canonical Core Web Vitals:

```text
LCP
INP
CLS
```

Current good-experience thresholds remain:

```text
LCP ≤ 2.5 seconds

INP ≤ 200 milliseconds

CLS ≤ 0.1
```

with the project objective assessed at the 75th percentile. ([web.dev][1])

---

# 40. Why Field Metrics Matter

Bundle size and synthetic tests cannot fully represent:

```text
real devices

real networks

real browser scheduling

actual user interaction
```

Therefore RUM is required once production traffic exists.

CI remains complementary.

---

# 41. Web Vitals Collector

Preferred implementation:

```text
web-vitals package
```

behind the observability port.

The library supports all Core Web Vitals and additional diagnostic metrics useful for real-user performance analysis. ([GitHub][2])

Business modules MUST NOT import it directly.

---

# 42. Core Web Vitals Are Not Route Timings

EduCore is a long-lived SPA.

Therefore:

```text
route navigation duration
```

must not be mislabeled:

```text
LCP
```

or:

```text
INP
```

Custom SPA timings are separate metrics.

---

# 43. Custom Performance Measurements

EduCore records safe measurements for important frontend transitions.

Minimum direction:

```text
application_bootstrap

route_navigation

tenant_switch

workspace_switch
```

W3C User Timing exists specifically to provide application-defined high-resolution timestamps and measurements, while Performance Timeline/`PerformanceObserver` provides observation infrastructure. ([W3C][5])

---

# 44. Application Bootstrap Timing

Conceptually measure:

```text
SPA boot start
↓
browser session resolution
↓
/auth/me
↓
Membership/Tenant ready
↓
Workspace ready
↓
capabilities ready
↓
application usable
```

The exact measurement start/end definitions must be stable and documented during TDD.

---

# 45. Route Navigation Timing

Measure user-visible transition between:

```text
navigation initiated
```

and:

```text
route usable
```

for selected critical routes.

Do not simply measure:

```text
router callback execution
```

because that does not represent user-perceived readiness.

---

# 46. Tenant Switch Timing

ADR-023 transition:

```text
prepare
→ verify
→ commit
```

can emit duration without exposing Tenant identity.

Example:

```text
tenant_switch_duration_ms
```

with outcome:

```text
success
denied
network_failure
```

---

# 47. Workspace Switch Timing

Likewise:

```text
workspace_switch_duration_ms
```

can classify:

```text
TENANT → ORGANIZATION

ORGANIZATION → ORGANIZATION_UNIT

ORGANIZATION → TENANT
```

without transmitting organization names.

---

# 48. API Timing

API transport may record:

```text
operationId

duration

status class

machine code
```

in sampled metrics.

Do not instrument every request with high-cardinality payload metadata.

---

# 49. Performance Data Must Be Context-Safe

Performance events do not need:

```text
student ID

resident ID

employee ID
```

to diagnose latency.

Route/module/operation identity is preferred.

---

# 50. Core Performance Objective

Frontend PRD remains authoritative:

```text
p75 LCP ≤ 2.5s

p75 INP ≤ 200ms

p75 CLS ≤ 0.1
```

These are **production experience objectives**.

They are not promises that every single device/session meets the threshold.

---

# 51. RUM Segmentation

Performance should be evaluated by useful low-cardinality dimensions such as:

```text
release

environment

route/module category

device class where provider supports it
```

rather than one undifferentiated global number.

Minimum sample sizes must be considered before treating a segment as conclusive.

---

# 52. Build-Time Performance Budgets

FE-8 budgets remain:

```text
Initial critical JS
target ≤ 300 KB gzip

Normal route incremental chunk
target ≤ 150 KB gzip
```

These are engineering budgets.

---

# 53. Bundle Budget CI

Production build CI must calculate compressed output sizes.

A budget regression must:

```text
fail CI
or
require explicit documented override
```

according to implementation governance.

A route being technically lazy does not exempt it from its incremental budget.

---

# 54. No Silent Budget Drift

Rejected:

```text
300 KB
↓
330 KB
↓
400 KB
↓
600 KB
```

through repeated small dependency additions without review.

Bundle regression must remain visible in pull requests/CI.

---

# 55. Bundle Attribution

When budget fails, engineering must be able to determine:

```text
which chunk grew

which dependency contributed

which module owns the increase
```

Exact bundle-analyzer tool is implementation detail.

---

# 56. Lazy Loading Remains Primary

ADR-028 remains authoritative:

```text
business modules
→ lazy-loaded
```

Observability must help detect when shared/eager dependencies accidentally move large module code into initial bundle.

---

# 57. Performance CI vs RUM

CI answers:

```text
What did this build contain?
```

RUM answers:

```text
How did real users experience it?
```

Neither replaces the other.

---

# 58. No Hard RUM Gate on Individual PR

A pull request cannot know its future production p75 accurately.

Therefore production RUM thresholds should drive:

```text
alerts

regression investigation

release health decisions
```

rather than pretending a local unit test can prove production Web Vitals.

Build budgets remain immediate CI gates.

---

# 59. Performance Regression Policy

A release exhibiting a sustained significant regression should support:

```text
investigation

feature rollback

artifact rollback
```

using ADR-020 immutable deployment strategy.

Exact alert windows remain operational policy.

---

# 60. Long Tasks

Long-main-thread-task diagnostics may be added when useful.

A current W3C Long Tasks specification defines browser observability for tasks that monopolize the main thread long enough to affect responsiveness. ([W3C][6])

However:

```text
Long Task telemetry
= optional diagnostic
```

not mandatory Foundation metric.

---

# 61. Browser Performance APIs

Browser-native APIs are preferred for custom performance measurements instead of adding a separate heavy performance framework.

Observability provider adapters may consume these measurements.

---

# 62. Backend Correlation

Frontend should preserve backend-provided:

```text
request_id

trace_id

correlation_id
```

when a canonical response/header contract exists.

These values may accompany a related frontend error report if safe.

---

# 63. No Invented Distributed Trace Authority

Until backend establishes a canonical correlation contract:

```text
frontend MUST NOT
```

pretend that an arbitrary generated UUID is a backend trace ID.

Frontend may maintain local:

```text
navigationId
operationId
```

for browser correlation, but that is not backend distributed-trace authority.

---

# 64. Correlation Standardization Follow-Up

A future backend/platform observability improvement may standardize:

```text
request correlation header
```

or distributed tracing.

That does not require reopening ADR-031 as long as:

```text
platform/api
↓
platform/observability
```

can consume it without business-module changes.

---

# 65. OpenTelemetry

ADR-031 does not require OpenTelemetry browser SDK in Foundation v1.

OpenTelemetry remains a valid future adapter/protocol option.

Reason:

```text
vendor-neutral architecture
```

does not require committing immediately to a specific telemetry implementation.

---

# 66. Error Boundary Integration

ADR-028 error boundaries must call the observability port.

Conceptually:

```text
Route Error Boundary
↓
captureException
↓
safe route/module/release context
```

No boundary directly knows provider API.

---

# 67. Global Error Capture

Application composition should capture:

```text
unhandled error

unhandled promise rejection
```

through one controlled infrastructure boundary where practical.

Duplicated global listeners are forbidden.

---

# 68. Duplicate Reporting

The same exception should not be reported multiple times through:

```text
global handler
+
route error boundary
+
component catch
```

without an intentional reason.

Observability adapter should support deduplication/provider semantics.

---

# 69. React Strict/Development Noise

Development-only framework behavior must not be mistaken for production error rate.

Environment and release metadata must distinguish:

```text
development

test

staging

production
```

---

# 70. Staging Telemetry

Staging should use:

```text
separate environment/dataset
```

from production.

Staging failures must not pollute production reliability metrics.

---

# 71. Test Environment

Unit/component/integration tests should normally use:

```text
no-op
or
test observability adapter
```

instead of transmitting telemetry externally.

Tests assert:

```text
correct observability call
```

without sending real production telemetry.

---

# 72. Playwright Telemetry

Critical E2E may verify that required observability integration exists, but test telemetry must remain separated from production data.

---

# 73. Source Maps

Production diagnostics need readable stack traces.

Selected direction:

```text
Vite production build
↓
hidden source maps
↓
private observability upload
↓
remove/exclude .map files
from public static deployment
```

Vite's `hidden` sourcemap mode generates separate maps without adding the source-map reference to the built bundle. ([vitejs][4])

---

# 74. Hidden Does Not Mean Private

Important:

```text
hidden source map
≠ automatically private
```

The `.map` file still exists in build output.

Therefore deployment pipeline must explicitly exclude it from the public CDN artifact after private upload.

---

# 75. Source Map Release Matching

Source maps must be indexed by the same:

```text
releaseId
```

embedded in the application artifact.

Otherwise minified production stack traces cannot reliably map to exact source.

---

# 76. Source Map Failure

Failure to upload source maps should be visible in release CI.

Exact policy for whether it blocks production deploy may depend on deployment criticality, but silent failure is not acceptable.

---

# 77. Public Source Maps

Public production:

```text
*.map
```

files are not a Foundation requirement.

Default:

```text
NOT PUBLICLY DEPLOYED
```

---

# 78. Security Observability

ADR-030 security-related events may include:

```text
CSP violations

invalid redirect attempts

contract-security mismatch

repeated stale-context recovery
```

when useful.

Frontend security telemetry is diagnostic, not an intrusion-detection guarantee.

---

# 79. CSP Violation Reporting

Production CSP may use a reporting mechanism routed through the observability architecture.

Exact:

```text
reporting endpoint

sampling

retention
```

remains implementation/provider policy.

CSP reports must follow the same privacy allowlist.

---

# 80. No Raw CSP URL Dumping by Default

CSP violation payloads can contain URLs.

Before forwarding/storing them, sensitive path/query data should be normalized/redacted.

---

# 81. Metrics Cardinality

High-cardinality dimensions such as:

```text
user ID

Tenant ID

record ID

arbitrary URL
```

must not become default metric labels.

They can make operational systems expensive and privacy-sensitive.

Prefer bounded values:

```text
routeId

module

operationId

status class

error code

workspace type

release
```

---

# 82. Operational Event Naming

Event names should be:

```text
stable

machine-readable

low-cardinality
```

Example:

```text
workspace_recovery_failed
```

rather than dynamically constructed:

```text
workspace_123_school_A_failed
```

---

# 83. Navigation Performance

Route navigation metrics should use:

```text
routeId
```

not arbitrary destination URL.

This aligns routing observability with ADR-028.

---

# 84. Error Severity

Observability adapter may classify:

```text
debug

info

warning

error

fatal
```

or equivalent provider semantics.

Exact mapping remains implementation detail.

Canonical application code should not depend heavily on provider-specific severity names.

---

# 85. Error Noise Control

Do not capture routine:

```text
404 user typo

422 validation

expected denied action
```

as full exception stack traces by default.

Important abnormal transitions remain reportable.

---

# 86. Availability Signals

Frontend should distinguish:

```text
application boot failure

API unavailable

network unavailable

session invalid

context invalid
```

rather than reporting all as:

```text
"app down"
```

This follows FE-8 resilience requirements.

---

# 87. Frontend Availability Is Not Backend Availability

Example:

```text
CDN chunk missing
```

means browser application failure even if Laravel is healthy.

Conversely:

```text
API 503
```

can occur while SPA assets load correctly.

Observability must preserve that distinction.

---

# 88. Release Health

A frontend release should be identifiable through combined signals such as:

```text
runtime-error rate

chunk-load failure rate

bootstrap success rate

Web Vitals

critical transition failure rates
```

Exact release-health formula is operational policy.

---

# 89. Bootstrap Success Signal

One useful application-level metric:

```text
application_bootstrap_success
```

or equivalent.

Failure categories may distinguish:

```text
authentication

network

contract

server

context
```

without exposing identity payload.

---

# 90. Tenant Switch Metrics

Useful aggregate:

```text
success rate

failure category

duration
```

not:

```text
which school a specific person switched into
```

by default.

---

# 91. Workspace Recovery Metrics

Repeated:

```text
ORGANIZATIONAL_CONTEXT_DENIED
```

recovery can reveal operational churn or assignment lifecycle issues.

Aggregate event tracking is valuable without storing sensitive workspace labels.

---

# 92. No Business Analytics Through Error Pipeline

Product analytics such as:

```text
which reports users prefer

which student screen is most popular
```

are separate from operational observability.

ADR-031 does not establish a general behavioral analytics platform.

If analytics is introduced later, it must obey the same privacy/security baseline.

---

# 93. Performance Alerts

Operational alerts should focus on actionable regressions.

Examples:

```text
Web Vital degradation

bootstrap failure spike

chunk-load failure spike

frontend runtime-error spike
```

Exact thresholds/windows are deployment policy.

---

# 94. No Alert Per User Error

Creating an alert for every:

```text
VALIDATION_FAILED
```

would generate noise.

Alerts should represent system/service health, not normal expected application outcomes.

---

# 95. Build Artifact Metrics

CI should record at minimum:

```text
initial JS gzip size

route/module chunk gzip sizes

total production asset summary
```

for regression comparison.

---

# 96. Bundle Report Retention

Bundle reports may be retained as CI artifacts or release metadata.

They do not need to be shipped into the browser runtime.

---

# 97. Performance Test Ownership

ADR-029 test strategy remains:

```text
build/CI
→ bundle regression

browser/RUM
→ real performance

Playwright
→ selected critical performance smoke
```

Playwright timing must not replace production RUM.

---

# 98. Performance Smoke

Critical E2E may verify gross failures such as:

```text
application becomes usable

lazy route loads

no infinite bootstrap
```

but exact CI timing thresholds must be used cautiously because shared CI hardware is noisy.

---

# 99. Observability Tests

Implementation must prove at minimum:

```text
1. business modules depend only on observability port.

2. provider SDK cannot leak through module APIs.

3. release ID exists in production build.

4. route errors produce one normalized event.

5. unhandled errors are captured.

6. expected validation failures are not reported
   as fatal exceptions.

7. ContractFailure is observable.

8. chunk load failure is observable.

9. API event uses operationId rather than raw payload.

10. Authorization/cookie/password are redacted.

11. raw sensitive forms are not sent.

12. route telemetry prefers routeId over raw URL.

13. Core Web Vitals are collected.

14. LCP/INP/CLS retain canonical units.

15. Tenant switch duration is measurable.

16. Workspace switch duration is measurable.

17. SPA route timing is not mislabeled as Web Vital.

18. telemetry failure does not break product flow.

19. no persistent offline telemetry queue exists.

20. staging/test telemetry remains separated.

21. hidden source maps are generated when configured.

22. source maps are excluded from public deployment.

23. source maps match release ID.

24. bundle budgets are checked.

25. old-context identifiers are not dumped
    into telemetry payloads.
```

---

# 100. Privacy Tests

Dedicated tests should verify allowlist behavior.

Given an object containing:

```text
password
authorization
cookie
studentName
studentPayload
routeId
releaseId
```

the serialized telemetry must retain only explicitly permitted metadata.

---

# 101. Source Map Deployment Test

Release pipeline should verify:

```text
public artifact
```

contains no unintended:

```text
*.map
```

files.

This must be an automated deployment check where practical.

---

# 102. Bundle Budget Tests

CI must prove:

```text
initial critical JS
≤ configured budget
```

and evaluate route chunk budgets against FE-8 targets.

Override must be explicit and visible.

---

# 103. Architecture Enforcement

Lint/architecture tests should reject where practical:

```text
direct vendor observability imports
inside modules

raw console-based production telemetry

raw sensitive telemetry payloads

business-module Web Vitals collection

business-module source-map configuration
```

---

# 104. Architectural Invariants

If ADR-031 is accepted:

```text
Frontend observability owner
= platform/observability

Observability vendor
= NOT LOCKED

Direct vendor SDK in modules
= FORBIDDEN

Runtime errors
= OBSERVED

Contract failures
= OBSERVED

Chunk failures
= OBSERVED

API diagnostics
= operationId + safe metadata

Raw API payload telemetry
= FORBIDDEN

Bearer/cookie/password telemetry
= FORBIDDEN

Telemetry metadata
= ALLOWLIST

Raw URL as default event dimension
= REJECTED

Route ID
= preferred navigation dimension

Release ID
= REQUIRED

Environment
= REQUIRED

Core Web Vitals
= LCP + INP + CLS

RUM targets
= p75 LCP ≤2.5s
  p75 INP ≤200ms
  p75 CLS ≤0.1

Core Web Vitals collector
= web-vitals preferred direction

SPA navigation timing
= custom metric

Tenant switch timing
= custom metric

Workspace switch timing
= custom metric

Performance APIs
= browser-native direction

Initial critical JS target
= ≤300 KB gzip

Normal route chunk target
= ≤150 KB gzip

Bundle budget CI
= REQUIRED

Source maps
= hidden/private

Public production .map
= FORBIDDEN by default

Telemetry failure
= MUST NOT break application

Persistent offline telemetry queue
= NOT Foundation v1

Backend correlation metadata
= consume when canonical

Invented frontend backend-trace authority
= FORBIDDEN

Business analytics
= separate concern
```

---

# 105. Consequences

## Positive

- Production frontend failures become diagnosable.
- Real-user performance is measurable instead of inferred from build size.
- Vendor choice remains replaceable.
- Business modules stay infrastructure-independent.
- Security/privacy enforcement occurs centrally.
- Source-map debugging remains available without intentionally publishing source.
- Frontend errors can correlate with exact release artifacts.
- Bundle regressions become visible before deployment.
- SPA-specific latency becomes measurable independently from Core Web Vitals.
- High-cardinality Tenant/business data is kept out of routine telemetry.
- Observability remains compatible with ADR-030 CSP/security requirements.

## Costs

- A provider adapter still needs to be selected during implementation.
- CI requires bundle analysis and source-map handling.
- Privacy allowlists require maintenance.
- RUM produces operational data that needs sampling/retention governance.
- Custom SPA timing definitions must remain stable.
- Source-map upload must be integrated with releases.
- Correlation with backend remains partial until a canonical backend correlation contract exists.

These costs are accepted because a production application serving large numbers of users cannot be operated safely from user reports and server logs alone.

---

# 106. Explicit Non-Decisions

ADR-031 does not decide:

```text
exact observability vendor

exact analytics vendor

exact OpenTelemetry adoption

exact sampling percentages

exact event-retention period

exact alert thresholds

exact RUM ingestion endpoint

exact CSP reporting endpoint

exact bundle-analyzer package

exact source-map upload provider

exact backend correlation header

exact release naming convention

exact User Timing mark names

exact long-task collection policy
```

These belong to implementation/TDD or an explicit backend/platform observability workstream.

---

# 107. Follow-Up / Architecture Gate

ADR-031 is the final planned ADR in the current Frontend Foundation sequence.

After acceptance, the sequence becomes:

```text
ADR-020 Framework & Rendering
ADR-021 Modular Architecture
ADR-022 Browser Authentication
ADR-023 Tenant Switching
ADR-024 Workspace Context
ADR-025 API / OpenAPI / Errors
ADR-026 State Ownership
ADR-027 Authorization UX
ADR-028 Routing / Code Splitting
ADR-029 Testing
ADR-030 Security
ADR-031 Observability / Performance
```

The correct next architecture activity after ADR-031 is **not automatically ADR-032**.

Instead, the Frontend Foundation should enter a:

```text
PRD ↔ ADR
Final Alignment / Architecture Gate
```

to verify:

```text
PRD FE-0 → FE-9
        ↕
ADR-020 → ADR-031
```

for gaps, conflicts, deferred backend workstreams, and implementation prerequisites before TDD begins.

---

# ADR-031 Proposed State

```text
ADR-031 — Frontend Observability
& Performance Strategy

Status:
🔒 ACCEPTED / LOCKED

Observability architecture:
VENDOR-NEUTRAL PORT

Owner:
platform/observability

Direct provider SDK in modules:
❌ FORBIDDEN

Runtime errors:
✅ OBSERVED

Contract/chunk errors:
✅ OBSERVED

Sensitive payload telemetry:
❌ FORBIDDEN

Telemetry metadata:
ALLOWLIST

Release ID:
✅ REQUIRED

Core Web Vitals:
LCP
INP
CLS

Targets:
p75 LCP ≤ 2.5s
p75 INP ≤ 200ms
p75 CLS ≤ 0.1

SPA route timing:
CUSTOM METRIC

Tenant switch timing:
CUSTOM METRIC

Workspace switch timing:
CUSTOM METRIC

Initial JS budget:
≤ 300 KB gzip

Route chunk target:
≤ 150 KB gzip

Bundle CI:
✅ REQUIRED

Source maps:
HIDDEN + PRIVATE UPLOAD

Public .map:
❌ FORBIDDEN by default

Telemetry failure:
MUST NOT BREAK PRODUCT FLOW

Persistent telemetry queue:
❌ FOUNDATION v1

Backend correlation:
consume canonical metadata when available

Observability vendor:
⚪ DEFERRED TO IMPLEMENTATION

Next after lock:
PRD ↔ ADR FINAL ALIGNMENT GATE
```
