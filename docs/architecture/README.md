# EduCore Architecture Documentation

**Version**: 3.0
**Status**: Current Baseline
**Updated**: 2026-08-12

Dokumentasi ini adalah entry point arsitektur EduCore setelah penyelesaian **Core Canonical Foundation (2G)** dan **Downstream Human/Profile Canonicalization (3A)**. Lifecycle seluruh koleksi dokumentasi dijelaskan di [`../README.md`](../README.md).

Dokumen lama dari Sprint `CORE-001` tetap dipertahankan sebagai histori keputusan, tetapi tidak semuanya lagi menjadi current implementation contract. Gunakan status setiap dokumen/ADR sebelum menjadikannya acuan implementasi.

---

## 1. Current Source of Truth

Urutan baca untuk memahami arsitektur yang berlaku saat ini:

1. [`current-architecture.md`](current-architecture.md) — baseline arsitektur yang sudah locked.
2. [`folder-structure.md`](folder-structure.md) — current repository map dan ownership boundary.
3. [`architecture-principles.md`](architecture-principles.md) — current architecture principles dan guardrails untuk evolusi platform.
4. [`adr/README.md`](adr/README.md) — indeks dan lifecycle Architecture Decision Records.
5. Dokumentasi subsystem module kernel:
   - [`kernel.md`](kernel.md)
   - [`discovery-flow.md`](discovery-flow.md)
   - [`module-manager.md`](module-manager.md)
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
│   ├── Authorization / RBAC
│   ├── Governance / Audit
│   └── Shared Platform Services
│
├── Auth
├── User
├── Academic
├── HR
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
| Role / Permission | Authorization capability |
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

See [`current-architecture.md`](current-architecture.md) for the detailed baseline.

---

## 4. Authentication & Authorization Flow

Canonical request security flow:

```text
Bearer Token
    │
    ▼
Authenticated User
    │
    ▼
User.person_id
    │
    ▼
Membership
    │
    ├── verify membership ownership
    ├── verify tenant ownership
    ├── verify ACTIVE state
    │
    ▼
Tenant Context
    │
    ▼
AuthorizationService
    │
    ├── membership role
    └── role permission
```

Role/permission claims are intentionally not trusted from bearer tokens.

---

## 5. Multi-Tenancy Direction

Current locked boundary:

```text
Tenant
= customer / security / data-isolation boundary
```

The application target includes multi-lembaga, multi-cabang, and integrated dormitory management. The following topology is **directional only and not yet a locked Core contract**:

```text
Tenant
  └── Organization / Lembaga
        └── Organization Unit / Branch
```

Do not implement `Organization`, `Branch`, organizational membership, or scoped authorization until the dedicated **Organizational Topology Audit** is completed and accepted.

---

## 6. ADR Status

ADR-001 through ADR-010 document the original module-kernel architecture and remain broadly valid.

ADR-011 and ADR-012 contain tenancy/authentication assumptions that were replaced by the canonical identity and authentication work:

- ADR-011: multi-tenancy implementation strategy — **Superseded**.
- ADR-012: tenant-aware authentication guard based on `users.tenant_id` — **Superseded**.

Current canonical foundation decisions are formalized in:

- ADR-013 — Canonical Human Identity.
- ADR-014 — Membership & Tenant Boundary.
- ADR-015 — Authentication Token & Request Context.
- ADR-016 — Database-Backed Tenant RBAC.

See [`adr/README.md`](adr/README.md) for the current index.

---

## 7. Historical Documentation

The following documents are classified **HISTORICAL** and retained in place to preserve project history and links:

- [`../prd/CORE-001.md`](../prd/CORE-001.md) — original Platform Kernel Engineering PRD/proposal.
- [`../sprint/sprint-001.md`](../sprint/sprint-001.md) — original Sprint 001 planning/review.

Each document now carries an explicit historical notice. They remain useful for architectural context, but they are not current implementation contracts. See [`../README.md`](../README.md) for documentation lifecycle semantics.

---

## 8. Documentation Alignment Status

Current documentation alignment sequence:

```text
DOC STEP 1 — Current architecture index + superseded ADR markers
COMPLETE

DOC STEP 2 — Formalize canonical identity/tenancy/auth/RBAC ADRs
COMPLETE

DOC STEP 3 — Rewrite folder structure from current repository
COMPLETE

DOC STEP 4 — Refresh architecture principles/examples
COMPLETE

DOC STEP 5 — Revalidate module-kernel documents
COMPLETE

DOC STEP 6 — Mark/archive historical PRD and sprint documents
COMPLETE

DOC STEP 7 — Documentation consistency regression
COMPLETE
```

Documentation for Organization/Branch will only be created after its architecture is audited and locked.

---

## 9. Documentation Consistency Closure

Documentation Alignment dinyatakan complete setelah repository-wide documentation audit memverifikasi bahwa:

- current documents tidak menggunakan legacy identity/tenancy contracts sebagai implementation guidance;
- legacy terms seperti `users.tenant_id`, `memberships.user_id`, `memberships.role`, `MockStudent`, atau `DiscoveredModule` hanya boleh muncul sebagai forbidden, superseded, amended, atau historical context;
- Accepted ADR memiliki lifecycle/status yang dapat dibaca secara eksplisit;
- relative Markdown links pada `docs/` resolve ke target yang ada;
- future Organization/Branch/Dormitory direction tetap diberi label **FUTURE / NOT LOCKED**;
- documentation tidak menjanjikan hot module enable/disable, dependency-ordered provider activation, atau disabled-module isolation yang belum menjadi current runtime guarantee.

Dengan closure ini, `docs/README.md` dan dokumen pada bagian **Current Source of Truth** di atas menjadi baseline dokumentasi resmi sampai ada ADR atau workstream baru yang secara eksplisit mengubahnya.
