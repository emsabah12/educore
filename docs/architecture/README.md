# EduCore Architecture Documentation

**Version**: 4.3
**Status**: Current Baseline
**Updated**: 2026-08-29

Dokumentasi ini adalah entry point arsitektur EduCore setelah penyelesaian **Core Canonical Foundation (2G)**, **Downstream Human/Profile Canonicalization (3A)**, **Phase 4A Module Kernel Runtime Hardening**, **Phase 4B Organizational Topology Foundation**, **Foundation 6D HTTP/OpenAPI Contract**, dan **Frontend Foundation FEI-1 sampai FEI-12**. Lifecycle seluruh koleksi dokumentasi dijelaskan di [`../README.md`](../README.md).

Dokumen lama dari Sprint `CORE-001` tetap dipertahankan sebagai histori keputusan, tetapi tidak semuanya lagi menjadi current implementation contract. Gunakan status setiap dokumen/ADR sebelum menjadikannya acuan implementasi.

---

## 1. Current Source of Truth

Urutan baca untuk memahami arsitektur yang berlaku saat ini:

1. [`current-architecture.md`](current-architecture.md) — baseline arsitektur yang sudah locked.
2. [`folder-structure.md`](folder-structure.md) — current repository map dan ownership boundary.
3. [`architecture-principles.md`](architecture-principles.md) — current architecture principles dan guardrails untuk evolusi platform.
4. [`adr/README.md`](adr/README.md) — indeks dan lifecycle Architecture Decision Records.
5. [`../api/openapi.yaml`](../api/openapi.yaml) — executable public HTTP transport contract untuk foundation routes; Academic/HR coverage yang belum hardened tetap explicit deferred.
6. [`../prd/PRD-001-frontend-foundation.md`](../prd/PRD-001-frontend-foundation.md) — locked Frontend Foundation product contract dan implementation-resolution record.
7. [`../tdd/TDD-001-frontend-foundation.md`](../tdd/TDD-001-frontend-foundation.md) — implemented/locked Frontend Foundation FEI-1 sampai FEI-12 verification contract.
8. Dokumentasi subsystem module kernel:
   - [`kernel.md`](kernel.md)
   - [`discovery-flow.md`](discovery-flow.md)
   - [`module-manager.md`](module-manager.md) — historical compatibility note; `ModuleManager` retired
   - [`module-lifecycle.md`](module-lifecycle.md)


---

## 2. Current Architectural Baseline

EduCore adalah **Modular Monolith** dengan Core sebagai stable platform foundation.

```text
EduCore
│
├── Core
│   ├── Platform Module Kernel
│   ├── Human Identity
│   ├── Tenancy
│   ├── Organizational Topology / Context
│   ├── Authorization / RBAC
│   ├── Governance / Audit
│   └── Shared Platform Services
│
├── Auth
├── User
├── Academic
├── HR
├── Dormitory
└── other downstream modules
```

Canonical human/tenant identity:

```text
Person
  │
  ├── User (optional digital account)
  │
  └── Membership
        │
        ├── Tenant
        ├── Roles / Permissions
        ├── Student
        ├── Guardian
        └── Employee
```

Responsibility boundaries:

| Concept | Canonical responsibility |
| --- | --- |
| `Person` | Global human identity |
| `User` | Authentication/digital account |
| `Membership` | Participation of a Person in a Tenant |
| `Tenant` | Customer/security/data-isolation boundary |
| `Organization` | Lembaga/institution inside a Tenant |
| `OrganizationUnit` | Branch/campus/operational unit inside an Organization |
| `OrganizationalAssignment` | Operational participation of a Membership in Organization/Unit |
| Role / Permission | Global database-backed authorization catalog |
| Student / Guardian / Employee | Downstream domain profiles |

---

## 3. Locked Foundation

The following contracts are considered stable and must not be reopened for legacy compatibility hacks:

- `Person` is the canonical human identity.
- `User` belongs to `Person`, not to `Tenant`.
- `Membership` belongs to `Person` and `Tenant`.
- `memberships.user_id` is not part of the canonical model.
- `memberships.role` is not an authorization source.
- Authentication token context uses `user_id`, `tenant_id`, `membership_id`, and `expires_at`.
- Tenant context is verified against canonical User → Person → Membership → Tenant ownership.
- Tenant RBAC uses database-backed Role/Permission persistence through `AuthorizationService`.
- Laravel Gate remains independent from tenant RBAC.
- Student, Guardian, and Employee are domain profiles linked through Membership.
- Teacher is a role/capability, not a human entity.
- Grading actor identity resolves Membership → Employee; `student_grades.teacher_id` stores Employee identity.
- Tenant-aware persistence must be protected by `BelongsToTenant` and/or explicit tenant-scoped query predicates.
- Canonical identifiers introduced/refactored under the foundation use UUIDv7.
- Core is the mandatory Module Kernel bootstrap root.
- Non-Core providers come only from validated `module.yaml.providers` and register in dependency order.
- Missing dependency, dependency cycle, invalid provider, and provider-registration failure fail fast.
- Module Kernel has no persisted enable/disable state and no hot load/unload contract.
- `module:list` and `module:status` are read-only metadata commands.
- Event/integration registration is explicit/provider-owned; no global reflection listener discovery.
- Module bootstrap composition, tenant feature availability, and authorization are separate concerns.
- `Tenant → Organization → OrganizationUnit` is the locked organizational topology.
- Membership remains `Person × Tenant`; organizational participation is a separate `OrganizationalAssignment`.
- OrganizationalContext is subordinate to Tenant/Membership context and must be server-verified.
- Tenant-wide roles remain in `membership_roles`; scoped roles attach to `OrganizationalAssignment`.
- Organization-level scoped roles inherit downward to exact units; unit roles never inherit upward or to siblings.
- Dormitory is a downstream business module boundary, not a Core topology level.
- Foundation public HTTP transport uses the canonical `/api/v1` namespace and OpenAPI-backed route discoverability; Academic/HR operations that are not yet hardened remain explicitly deferred rather than implicitly treated as covered.
- Trusted/non-browser API clients may use canonical BearerAuth; the first-party SPA uses BrowserSessionAuth through the same-origin Laravel BFF/session broker.
- Canonical Membership-scoped bearer credentials remain server-side for the first-party browser flow and are never owned by React runtime.
- `GET /api/v1/auth/me` remains the canonical protected bootstrap resource; `/api/v1/browser/auth/me` is retired.
- `X-EduCore-Membership-Id` and `X-EduCore-Organizational-Assignment-Id` are untrusted locators, never authentication or authorization authority.
- `frontend/` is the singular production React SPA boundary with `app`, `platform`, `shared`, and `modules` ownership.
- Frontend context-sensitive responses must be fenced so superseded Membership/Tenant/Workspace responses cannot mutate the current interactive context.

See [`current-architecture.md`](current-architecture.md) for the detailed baseline.

---

## 4. Authentication & Authorization Flow

EduCore memiliki dua authentication transports yang bertemu pada canonical identity/Tenant/authorization boundary yang sama.

Trusted/non-browser API flow:

```text
BearerAuth
    ↓
canonical bearer credential
    ↓
Authenticated User
    ↓
User.person_id
    ↓
Membership / Tenant verification
    ↓
AuthorizationService
```

First-party SPA flow:

```text
BrowserSessionAuth
    ↓
HttpOnly BrowserSession cookie
    ↓
same-origin Laravel BFF / session broker
    ↓
server-held Membership-scoped canonical bearer
    ↓
canonical /api/v1 protected resources
    ↓
Membership / Tenant verification
    ↓
AuthorizationService
```

Browser control-plane operations are:

```text
GET  /api/v1/browser/session/csrf
POST /api/v1/browser/auth/login
POST /api/v1/browser/auth/logout
POST /api/v1/browser/user/memberships/{membership_id}/switch
```

Canonical authenticated bootstrap remains:

```text
GET /api/v1/auth/me
```

Role/permission claims are intentionally not trusted from bearer tokens, BrowserSession state, or client-provided locators. Capability projection is UX/read-model input only; protected backend operations authorize again from current persistence state.

---

## 5. Multi-Tenancy & Organizational Topology

The locked hierarchy is:

```text
Tenant
  └── Organization
        └── OrganizationUnit
```

`Tenant` remains the customer/security/data-isolation boundary. `Organization` and `OrganizationUnit` are subordinate organizational topology.

Membership remains `Person × Tenant`. A Membership may have zero or more `OrganizationalAssignment` records that express where the Membership participates operationally.

Scoped authorization is explicit:

```text
Organization context
= TenantRoles ∪ OrganizationRoles

Unit context
= TenantRoles ∪ OrganizationRoles ∪ ExactUnitRoles
```

Dormitory consumes this foundation from `Modules/Dormitory`; its concrete implementation remains downstream and does not redefine Tenant, Organization, OrganizationUnit, Membership, Role, or Permission.

---

## 6. ADR Status

Module-kernel ADR lifecycle after Phase 4A:

- ADR-001 — Accepted; runtime/bootstrap details amended by ADR-017.
- ADR-003 — Accepted; manifest/provider/bootstrap semantics amended by ADR-017.
- ADR-006 — **Superseded** by ADR-017.
- ADR-007 — **Superseded** by ADR-017.
- ADR-008 — Accepted; current module commands are read-only.
- ADR-010 — Accepted; exact current manifest key with lowercase canonical cutover target.
- ADR-017 — **Accepted** canonical Module Runtime & Bootstrap Contract.
- ADR-018 — **Accepted** Organizational Topology & Scoped Authorization.
- ADR-019 — **Accepted** Dormitory Integration Boundary.

ADR-011 and ADR-012 contain tenancy/authentication assumptions replaced by canonical identity/authentication work and remain **Superseded**.

Current canonical foundation decisions include:

- ADR-013 — Canonical Human Identity.
- ADR-014 — Membership & Tenant Boundary.
- ADR-015 — Authentication Token & Request Context.
- ADR-016 — Database-Backed Tenant RBAC.
- ADR-017 — Module Runtime & Bootstrap Contract.
- ADR-018 — Organizational Topology & Scoped Authorization.
- ADR-019 — Dormitory Integration Boundary.
- ADR-020 — Frontend Framework & Rendering Strategy.
- ADR-021 — Frontend Modular Application Architecture.
- ADR-022 — Authentication Credential Storage & Browser Session Isolation.
- ADR-023 — Tenant / Membership Context Switching.
- ADR-024 — Workspace / Organizational Context Management.
- ADR-025 — API Client, OpenAPI & Canonical Error Handling.
- ADR-026 — Server-State & Client-State Ownership.
- ADR-027 — Capability-Aware Navigation & Authorization UX.
- ADR-028 — Routing & Code-Splitting Strategy.
- ADR-029 — Frontend Testing Strategy.
- ADR-030 — Frontend Security Baseline.
- ADR-031 — Frontend Observability & Performance Strategy.
- ADR-032 — HR Domain Boundary & Workforce Architecture.

See [`adr/README.md`](adr/README.md) for the current index.

---

## 7. Historical Documentation

The following documents are classified **HISTORICAL** and retained in place to preserve project history and links:

- [`../prd/CORE-001.md`](../prd/CORE-001.md) — original Platform Kernel Engineering PRD/proposal.
- [`../sprint/sprint-001.md`](../sprint/sprint-001.md) — original Sprint 001 planning/review.

Each document now carries an explicit historical notice. They remain useful for architectural context, but they are not current implementation contracts. See [`../README.md`](../README.md) for documentation lifecycle semantics.

---

## 8. Current Documentation Alignment

Core/Phase-3A, Phase 4A, dan Phase 4B documentation alignment merupakan historical locked baselines. Foundation 6D menambahkan canonical `/api/v1` transport namespace serta OpenAPI-backed foundation HTTP contract/discoverability. Frontend Foundation FEI-1 sampai FEI-12 sekarang juga merupakan implemented/locked current baseline dengan BrowserSession/BFF transport, context-safe SPA runtime, capability-aware authorization UX, static routing/code splitting, security/build gates, observability, dan browser E2E verification.

Current-state documentation harus direvalidasi ketika locked implementation atau architectural contract berubah. Completion label dari alignment phase sebelumnya adalah historical evidence, bukan jaminan bahwa current documentation tidak akan memerlukan alignment lagi.

Organization/OrganizationUnit topology dan scoped authorization tetap **LOCKED / CURRENT**. Dormitory tetap downstream business module yang mengonsumsi foundation tersebut, sementara Core tidak memperoleh Dormitory topology atau ownership.

---

## 9. Documentation Consistency Guardrails

Repository-wide documentation audit harus memverifikasi bahwa:

- current documents tidak menggunakan legacy identity/tenancy contracts sebagai implementation guidance;
- legacy terms seperti `users.tenant_id`, `memberships.user_id`, `memberships.role`, `MockStudent`, atau `DiscoveredModule` hanya boleh muncul sebagai forbidden, superseded, amended, atau historical context;
- Accepted ADR memiliki lifecycle/status yang dapat dibaca secara eksplisit;
- relative Markdown links pada `docs/` resolve ke target yang ada;
- Organization/OrganizationUnit/scoped-authorization semantics match ADR-018 as **LOCKED / CURRENT**;
- Dormitory tetap dideskripsikan sebagai downstream business module/integration boundary dan tidak dipromosikan menjadi Core topology atau authorization source;
- current foundation HTTP/OpenAPI documentation mencerminkan implemented public foundation route contracts, sementara Academic/HR operations yang belum hardened tetap ditandai explicit deferred;
- documentation tidak menjanjikan hot module enable/disable atau disabled-module isolation;
- documentation mencatat manifest-driven, dependency-ordered provider activation sebagai current runtime guarantee;
- documentation tidak menghidupkan kembali `ModuleStateRepository`, `ModuleManager`, provider guessing, atau global event auto-discovery sebagai current contract;
- current mixed-case manifest keys dibedakan jelas dari lowercase canonical technical-key target;
- current frontend documentation membedakan BearerAuth untuk supported API clients dari BrowserSessionAuth/BFF untuk first-party SPA;
- `/api/v1/auth/me` tetap canonical protected bootstrap dan `/api/v1/browser/auth/me` tidak boleh dihidupkan kembali;
- Membership/organizational-assignment browser headers tetap diperlakukan sebagai locator, bukan authority;
- current frontend documentation mempertahankan `frontend/` sebagai single production SPA source of truth dan FEI-1 sampai FEI-12 sebagai locked implementation baseline.

Dengan guardrails ini, `docs/README.md` dan dokumen pada bagian **Current Source of Truth** di atas menjadi baseline dokumentasi resmi sampai ada ADR atau workstream baru yang secara eksplisit mengubahnya.
