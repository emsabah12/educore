# ADR-017 — Module Runtime & Bootstrap Contract

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-13
**Scope** : Phase 4A — Module Kernel Runtime Hardening

---

> ## Decision Summary
>
> EduCore is a **modular monolith**, not a runtime plugin engine. Core is the mandatory bootstrap root. A physically present non-Core module participates in application bootstrap when its manifest is valid and its declared dependency graph is valid. Non-Core providers are activated only from manifest declarations, in dependency order, and invalid configuration fails fast. EduCore does not persist module enable/disable bootstrap state and does not perform global reflection-based event auto-discovery. Tenant feature availability is a separate concern from application module bootstrap.

## Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry as Source of Truth
- ADR-006 — Runtime Module State Repository (**Superseded**)
- ADR-007 — ModuleManager as Kernel Facade (**Superseded**)
- ADR-008 — Thin Command Pattern
- ADR-009 — Separation of Infrastructure and Kernel Domain
- ADR-010 — Module Identity Strategy

---

# 1. Context

Phase 4A audited the Module Kernel against the application runtime and found several competing activation concepts:

- static business-module providers in `bootstrap/providers.php`;
- manifest providers that were validated but were not the sole non-Core activation source;
- provider naming-convention guessing;
- dependency order that could be computed without being the registration order;
- persisted `enabled/disabled` state through `ModuleStateRepository` and `modules.json`;
- `module:enable` / `module:disable` commands that implied a runtime lifecycle the application did not actually support;
- global event-listener discovery through filesystem scanning and reflection;
- cross-module source dependencies that did not match the declared manifest graph.

Those mechanisms made the declared module graph differ from the effective runtime graph and made deployment-time module composition look like hot runtime plugin management.

This ADR defines the canonical runtime contract after the Phase 4A hardening work.

---

# 2. Decision

## 2.1 EduCore uses deployment-time module composition

An EduCore module is application code deployed as part of the modular monolith.

The canonical bootstrap lifecycle is:

```text
PHYSICALLY PRESENT
        ↓
DISCOVERED
        ↓
MANIFEST VALID
        ↓
DEPENDENCIES VALID
        ↓
BOOTSTRAPPABLE
        ↓
PROVIDERS REGISTERED
        ↓
BOOTED
```

Invalid manifest, dependency, or provider configuration fails the bootstrap path instead of being silently ignored.

EduCore does **not** provide:

- runtime module installation;
- hot provider load/unload;
- persisted module enable/disable bootstrap state;
- tenant-specific provider activation.

`module:list` and `module:status` are read-only metadata commands.

---

## 2.2 Core is the mandatory bootstrap root

`Core` is the application foundation and is always part of bootstrap.

`bootstrap/providers.php` contains application/bootstrap providers, including `AppServiceProvider` and `CoreServiceProvider`. Business-module providers are not statically registered there.

The generic non-Core module registrar does not treat Core as a normal downstream module.

Core must not depend on Auth or business-module implementations.

---

## 2.3 Non-Core provider activation is manifest-driven

For every non-Core module:

1. provider classes come from `module.yaml`;
2. provider declarations are validated;
3. each provider class must exist and extend Laravel `ServiceProvider`;
4. providers are registered in resolved module dependency order;
5. provider registration failures propagate.

Provider classes are **not** inferred from module names or folder naming conventions.

The manifest is therefore the sole non-Core provider activation source.

---

## 2.4 Dependency graph is explicit, closed, ordered, and fail-fast

Every direct production module dependency must be declared in `module.yaml`.

The resolver must reject:

- missing dependencies;
- circular dependencies.

The resolved topological order is used by module loading and non-Core provider registration.

Current dependency graph:

```text
Core      → []
Auth      → Core
User      → Core, Auth
HR        → Core, Auth
Academic  → Core, HR, Auth
PPDB      → Core
```

Arrows represent "depends on".

Core is a foundation dependency and therefore must not gain reverse dependencies on Auth, User, HR, Academic, or PPDB.

---

## 2.5 Runtime module state is not an activation source

`ModuleStateRepository`, `modules.json`, `ModuleManager`, `module:enable`, and `module:disable` are not part of the current Module Kernel contract.

Module presence/validity belongs to deployment/bootstrap composition.

Tenant or customer access to a capability is a different concern.

Future feature/entitlement work must not reintroduce tenant availability as mutable Module Kernel bootstrap state.

In short:

```text
Module bootstrap composition
≠
Tenant feature availability
≠
Authorization
```

---

## 2.6 Event activation is explicit and owner-controlled

The Module Kernel does not scan module listener directories, reflect on listener method signatures, or maintain a global module event registry.

Event listeners are registered explicitly by the provider/component that owns the integration.

This keeps event activation visible in source code and prevents folder naming or filesystem case-sensitivity from becoming hidden runtime contracts.

---

## 2.7 Module identity has a current state and a canonical target

Current registry and dependency lookup use the exact manifest `name`.

At this ADR's acceptance date, the physical manifest keys are:

```text
core
Auth
User
HR
Academic
PPDB
```

The canonical target for technical keys is a lowercase slug:

```regex
^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$
```

The lowercase physical-manifest cutover is a separate migration step.

Until that migration occurs:

- exact current manifest names must be used;
- no silent normalization is allowed;
- no permanent alias system is introduced.

---

# 3. Consequences

## Positive

- declared module graph matches runtime dependency direction;
- provider activation is deterministic and reviewable;
- dependency order is meaningful at runtime;
- invalid module configuration fails early;
- Core remains independent from downstream application modules;
- module commands no longer imply unsupported hot lifecycle behavior;
- event registration is explicit instead of reflection-driven;
- deployment composition, tenant feature availability, and authorization remain separate concerns.

## Negative / Trade-offs

- adding a production dependency requires an explicit manifest update;
- disabling a deployed module is a deployment/configuration change rather than an application runtime command;
- future entitlement/feature work needs its own explicit model instead of reusing Module Kernel state;
- the temporary mixed-case technical-key migration state remains visible until the lowercase cutover is completed.

---

# 4. Superseded / Amended Decisions

This ADR:

- **supersedes ADR-006** as a current runtime-state decision;
- **supersedes ADR-007** as a current ModuleManager/lifecycle-state decision;
- **amends ADR-001** where its historical implementation snapshot mentions runtime module state or unfrozen provider ordering;
- **amends ADR-003** for provider activation and the removal of separate enabled/disabled state;
- **amends ADR-008** so current module CLI is read-only metadata access;
- **clarifies ADR-010** by separating the exact current manifest key from the lowercase canonical target.

The historical bodies of superseded ADRs remain in the repository as decision history.

---

# 5. Architectural Invariants

The following invariants are locked for the current Module Kernel:

```text
Core is mandatory
Core dependencies = []

non-Core provider source = module.yaml providers
provider registration order = dependency order

missing dependency = fail
dependency cycle = fail
invalid provider = fail
provider registration failure = propagate

runtime module enable/disable state = none
hot module load/unload = unsupported

global reflection event discovery = none
event registration = explicit / owner-controlled

Core → Auth/business implementation dependency = forbidden

tenant feature availability ≠ module bootstrap activation
authorization ≠ module bootstrap activation
```

Any future design that changes these invariants requires an explicit ADR or migration strategy rather than an implicit implementation drift.

---

# 6. Validation

The contract was validated during Phase 4A through:

- dependency resolver tests;
- module bootstrap integration tests;
- manifest-driven provider activation tests;
- module console contract tests;
- Core/Auth/User/HR/Academic regression suites;
- full application test suite;
- `migrate:fresh --seed`;
- application/module/route bootstrap smoke checks;
- static cross-module dependency closure checks.

---

# 7. References

- `docs/architecture/kernel.md`
- `docs/architecture/module-lifecycle.md`
- `docs/architecture/discovery-flow.md`
- `docs/architecture/module-manager.md`
- `docs/architecture/current-architecture.md`
- `docs/architecture/architecture-principles.md`
