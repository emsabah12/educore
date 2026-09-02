# Architecture Decision Records (ADR)

**Version**: 4.3
**Status**: Current Index
**Updated**: 2026-08-29

---

## Purpose

Folder ini menyimpan Architecture Decision Records EduCore sebagai histori keputusan arsitektur dan kontrak yang telah diterima.

ADR harus dibaca bersama status lifecycle-nya. Dokumen berstatus `Superseded` tetap dipertahankan sebagai histori, tetapi tidak boleh digunakan sebagai current implementation contract.

Current implemented architecture baseline tersedia di:

[`../current-architecture.md`](../current-architecture.md)

---

## ADR Lifecycle

```text
Proposed
   ↓
Review
   ↓
Accepted
   ├────────→ Deprecated
   ↓
Superseded
```

| Status         | Meaning                                                                                      |
| -------------- | -------------------------------------------------------------------------------------------- |
| **Proposed**   | Masih dalam pembahasan.                                                                      |
| **Accepted**   | Menjadi current architecture decision.                                                       |
| **Superseded** | Keputusan telah digantikan oleh contract/decision yang lebih baru; disimpan sebagai histori. |
| **Deprecated** | Tidak digunakan lagi dan tidak memiliki replacement langsung.                                |

---

## ADR Index

| ADR     | Title                                                         | Status         | Notes                                                                                                                                                                              |
| ------- | ------------------------------------------------------------- | -------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| ADR-001 | Kernel Architecture Overview                                  | Accepted       | KEEP; runtime/bootstrap details amended by ADR-017                                                                                                                                 |
| ADR-002 | Modular Monolith Architecture                                 | Accepted       | KEEP; current deployment/module strategy                                                                                                                                           |
| ADR-003 | Module Manifest Specification                                 | Accepted       | KEEP; manifest/provider/bootstrap semantics amended by ADR-017                                                                                                                     |
| ADR-004 | Automatic Module Discovery                                    | Accepted       | KEEP; physical manifest discovery remains current                                                                                                                                  |
| ADR-005 | Module Registry as Source of Truth                            | Accepted       | KEEP; `ModuleRepository` is current read facade                                                                                                                                    |
| ADR-006 | Runtime Module State Repository                               | **Superseded** | Replaced by ADR-017; persisted module activation state removed                                                                                                                     |
| ADR-007 | ModuleManager as Kernel Facade                                | **Superseded** | Replaced by ADR-017; lifecycle-state facade removed                                                                                                                                |
| ADR-008 | Thin Command Pattern                                          | Accepted       | KEEP; current module commands are read-only `module:list` / `module:status`                                                                                                        |
| ADR-009 | Separation of Infrastructure and Kernel Domain                | Accepted       | KEEP/REFRAME: logical ownership over cosmetic folder purity                                                                                                                        |
| ADR-010 | Module Identity Strategy                                      | Accepted       | KEEP; exact current key + lowercase canonical cutover target                                                                                                                       |
| ADR-011 | Multi-Tenancy Architecture Strategy                           | **Superseded** | Shared-schema decision retained; tenant-context mechanics evolved                                                                                                                  |
| ADR-012 | Tenant-Aware Authentication Guard                             | **Superseded** | Replaced by ADR-015                                                                                                                                                                |
| ADR-013 | Canonical Human Identity                                      | **Accepted**   | Person is canonical human identity                                                                                                                                                 |
| ADR-014 | Membership & Tenant Boundary                                  | **Accepted**   | Person-owned Membership; Tenant is security boundary                                                                                                                               |
| ADR-015 | Authentication Token & Request Context                        | **Accepted**   | Encrypted bearer token + verified current request context                                                                                                                          |
| ADR-016 | Database-Backed Tenant RBAC                                   | **Accepted**   | AuthorizationService + Role/Permission persistence                                                                                                                                 |
| ADR-017 | Module Runtime & Bootstrap Contract                           | **Accepted**   | Canonical Module Kernel runtime contract after Phase 4A hardening                                                                                                                  |
| ADR-018 | Organizational Topology & Scoped Authorization                | **Accepted**   | Tenant → Organization → OrganizationUnit + Assignment/Context/scoped-role semantics                                                                                                |
| ADR-019 | Dormitory Integration Boundary                                | **Accepted**   | Dormitory is downstream business domain consuming Core topology                                                                                                                    |
| ADR-020 | Frontend Framework & Rendering Strategy                       | **Accepted**   | React SPA baseline, rendering and deployment strategy                                                                                                                              |
| ADR-021 | Frontend Modular Application Architecture                     | **Accepted**   | `app/platform/shared/modules` boundaries and explicit module public API                                                                                                            |
| ADR-022 | Authentication Credential Storage & Browser Session Isolation | **Accepted**   | HttpOnly browser session with server-side bearer custody and tab isolation                                                                                                         |
| ADR-023 | Tenant / Membership Context Switching                         | **Accepted**   | Membership switch lifecycle and atomic tenant-context commit                                                                                                                       |
| ADR-024 | Workspace / Organizational Context Management                 | **Accepted**   | Runtime workspace projection and organizational assignment locator                                                                                                                 |
| ADR-025 | API Client, OpenAPI & Canonical Error Handling                | **Accepted**   | Generated contracts, browser-BFF contract and canonical error handling                                                                                                             |
| ADR-026 | Server-State & Client-State Ownership                         | **Accepted**   | TanStack Query server-state ownership and client-state boundaries                                                                                                                  |
| ADR-027 | Capability-Aware Navigation & Authorization UX                | **Accepted**   | Permission-driven UX without role-name authorization checks                                                                                                                        |
| ADR-028 | Routing & Code-Splitting Strategy                             | **Accepted**   | React Router data-mode routing and route-level code splitting                                                                                                                      |
| ADR-029 | Frontend Testing Strategy                                     | **Accepted**   | Vitest, RTL, MSW and Playwright testing baseline                                                                                                                                   |
| ADR-030 | Frontend Security Baseline                                    | **Accepted**   | Same-origin SPA+BFF, CSP, CSRF and browser security baseline                                                                                                                       |
| ADR-031 | Frontend Observability & Performance Strategy                 | **Accepted**   | Vendor-neutral telemetry and Core Web Vitals/performance budgets                                                                                                                   |
| ADR-032 | HR Domain Boundary & Workforce Architecture                   | **Accepted**   | HR owns workforce/employment lifecycle; Core retains human identity, tenancy, organizational topology and authorization                                                            |
| ADR-034 | Billing Domain Boundary & Entitlement/Quota Architecture      | **Proposed**   | Platform-level Billing bounded context; three-layer Entitlement/Quota/Permission separation; self-service signup as new public path, not a relaxation of `RequireGlobalSuperadmin` |

---

## Current Decision Families

### Platform / Module Kernel

```text
ADR-001
ADR-002
ADR-003
ADR-004
ADR-005
ADR-008
ADR-009
ADR-010
ADR-017
```

ADR-017 is the canonical current runtime/bootstrap contract for the Modular Monolith Module Kernel. ADR-001, ADR-003, ADR-008, and ADR-010 remain accepted with amendments that point to ADR-017 where runtime details evolved. ADR-006 and ADR-007 are retained only as superseded historical context.

### Identity / Tenancy / Authentication / RBAC

The original ADR-011/012 assumptions were superseded during Core Canonical Foundation and downstream identity canonicalization.

Current canonical decisions are now formalized as:

```text
ADR-013 — Canonical Human Identity
ADR-014 — Membership & Tenant Boundary
ADR-015 — Authentication Token & Request Context
ADR-016 — Database-Backed Tenant RBAC
ADR-018 — Organizational Topology & Scoped Authorization
ADR-019 — Dormitory Integration Boundary
```

The consolidated implemented baseline remains available in [`../current-architecture.md`](../current-architecture.md).

### Frontend Foundation

Current frontend foundation decisions are formalized as:

```text
ADR-020 — Frontend Framework & Rendering Strategy
ADR-021 — Frontend Modular Application Architecture
ADR-022 — Authentication Credential Storage & Browser Session Isolation
ADR-023 — Tenant / Membership Context Switching
ADR-024 — Workspace / Organizational Context Management
ADR-025 — API Client, OpenAPI & Canonical Error Handling
ADR-026 — Server-State & Client-State Ownership
ADR-027 — Capability-Aware Navigation & Authorization UX
ADR-028 — Routing & Code-Splitting Strategy
ADR-029 — Frontend Testing Strategy
ADR-030 — Frontend Security Baseline
ADR-031 — Frontend Observability & Performance Strategy
```

These decisions implement the locked Frontend Foundation PRD in [`../../prd/PRD-001-frontend-foundation.md`](../../prd/PRD-001-frontend-foundation.md) and are realized by [`../../tdd/TDD-001-frontend-foundation.md`](../../tdd/TDD-001-frontend-foundation.md). FEI-1 sampai FEI-12 are complete/locked at `1094dad05ec4589a9e83a40fae249eef01591b94`.

The BrowserSession/BFF foundation required by ADR-022, ADR-025, and ADR-030 is implemented. First-party React uses BrowserSessionAuth with server-side canonical bearer custody; this does not reopen Core identity, tenancy, Membership, or RBAC semantics.

---

### HR / Workforce

Current Human Resources workforce boundary is formalized by:

```text
ADR-032 — HR Domain Boundary & Workforce Architecture
```

### Billing / Subscription (Proposed)

Proposed platform-level Billing boundary — not yet Accepted, no implementation exists:

```text
ADR-034 — Billing Domain Boundary & Entitlement/Quota Architecture (Proposed)
```

## Reading Order

For a new developer:

```text
1. ../README.md
2. ../current-architecture.md
3. ADR-017 (current Module Runtime & Bootstrap contract)
4. ADR-001 → ADR-005, ADR-008 → ADR-010
   (supporting module-kernel decisions/history)
5. ADR-013 → ADR-016
   (current identity/tenancy/auth/RBAC contracts)
6. ADR-018
   (current organizational topology/scoped authorization contract)
7. ADR-019
   (Dormitory integration boundary)
8. ../../prd/PRD-001-frontend-foundation.md
   (locked Frontend Foundation product contract)
9. ADR-020 → ADR-031
   (current Frontend Foundation architecture decisions)
10. ../../tdd/TDD-001-frontend-foundation.md
    (implemented/locked FEI-1 → FEI-12 verification contract)
11. ../../prd/hr/HR-001-human-resources-management.md
    (approved HR product/business requirements)
12. ADR-032
    (current HR Domain Boundary & Workforce Architecture)
13. ../../prd/hr/README.md
    (current consolidated HR specification index)
14. ADR-006 / ADR-007 / ADR-011 / ADR-012
    only as superseded historical context

---
```

## ADR Rules

Create a new ADR when changing:

- public architecture contract;
- identity ownership;
- tenant/security boundary;
- authorization semantics;
- dependency direction;
- module lifecycle;
- persistence strategy with architectural impact;
- cross-module platform contract.

An ADR is normally unnecessary for isolated implementation details such as method renames, internal refactors, or non-architectural cleanup.

---

## Documentation Integrity Rule

Do not silently rewrite historical ADR decisions to make history look consistent with current architecture.

When a decision changes:

```text
old ADR
  ↓
mark Superseded
  ↓
new ADR / current locked contract
```

This preserves both architectural history and a trustworthy current source of truth.
