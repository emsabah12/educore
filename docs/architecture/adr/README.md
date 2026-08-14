# Architecture Decision Records (ADR)

**Version**: 4.0
**Status**: Current Index
**Updated**: 2026-08-14

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

| Status | Meaning |
| --- | --- |
| **Proposed** | Masih dalam pembahasan. |
| **Accepted** | Menjadi current architecture decision. |
| **Superseded** | Keputusan telah digantikan oleh contract/decision yang lebih baru; disimpan sebagai histori. |
| **Deprecated** | Tidak digunakan lagi dan tidak memiliki replacement langsung. |

---

## ADR Index

| ADR | Title | Status | Notes |
| --- | --- | --- | --- |
| ADR-001 | Kernel Architecture Overview | Accepted | KEEP; runtime/bootstrap details amended by ADR-017 |
| ADR-002 | Modular Monolith Architecture | Accepted | KEEP; current deployment/module strategy |
| ADR-003 | Module Manifest Specification | Accepted | KEEP; manifest/provider/bootstrap semantics amended by ADR-017 |
| ADR-004 | Automatic Module Discovery | Accepted | KEEP; physical manifest discovery remains current |
| ADR-005 | Module Registry as Source of Truth | Accepted | KEEP; `ModuleRepository` is current read facade |
| ADR-006 | Runtime Module State Repository | **Superseded** | Replaced by ADR-017; persisted module activation state removed |
| ADR-007 | ModuleManager as Kernel Facade | **Superseded** | Replaced by ADR-017; lifecycle-state facade removed |
| ADR-008 | Thin Command Pattern | Accepted | KEEP; current module commands are read-only `module:list` / `module:status` |
| ADR-009 | Separation of Infrastructure and Kernel Domain | Accepted | KEEP/REFRAME: logical ownership over cosmetic folder purity |
| ADR-010 | Module Identity Strategy | Accepted | KEEP; exact current key + lowercase canonical cutover target |
| ADR-011 | Multi-Tenancy Architecture Strategy | **Superseded** | Shared-schema decision retained; tenant-context mechanics evolved |
| ADR-012 | Tenant-Aware Authentication Guard | **Superseded** | Replaced by ADR-015 |
| ADR-013 | Canonical Human Identity | **Accepted** | Person is canonical human identity |
| ADR-014 | Membership & Tenant Boundary | **Accepted** | Person-owned Membership; Tenant is security boundary |
| ADR-015 | Authentication Token & Request Context | **Accepted** | Encrypted bearer token + verified current request context |
| ADR-016 | Database-Backed Tenant RBAC | **Accepted** | AuthorizationService + Role/Permission persistence |
| ADR-017 | Module Runtime & Bootstrap Contract | **Accepted** | Canonical Module Kernel runtime contract after Phase 4A hardening |
| ADR-018 | Organizational Topology & Scoped Authorization | **Accepted** | Tenant → Organization → OrganizationUnit + Assignment/Context/scoped-role semantics |
| ADR-019 | Dormitory Integration Boundary | **Accepted** | Dormitory is downstream business domain consuming Core topology |

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

---

## Reading Order

For a new developer:

```text
1. ../README.md
2. ../current-architecture.md
3. ADR-017 (current Module Runtime & Bootstrap contract)
4. ADR-001 → ADR-005, ADR-008 → ADR-010 (supporting module-kernel decisions/history)
5. ADR-013 → ADR-016 (current identity/tenancy/auth/RBAC contracts)
6. ADR-018 (current organizational topology/scoped authorization contract)
7. ADR-019 (Dormitory integration boundary)
8. ADR-006 / ADR-007 / ADR-011 / ADR-012 only as superseded historical context
```

---

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
