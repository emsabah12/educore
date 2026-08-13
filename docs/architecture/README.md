# EduCore Architecture Documentation

**Version**: 4.0
**Status**: Current Baseline
**Updated**: 2026-08-14

Dokumentasi ini adalah entry point arsitektur EduCore setelah penyelesaian **Core Canonical Foundation (2G)**, **Downstream Human/Profile Canonicalization (3A)**, dan **Phase 4A Module Kernel Runtime Hardening**. Lifecycle seluruh koleksi dokumentasi dijelaskan di [`../README.md`](../README.md).

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
- Core is the mandatory Module Kernel bootstrap root.
- Non-Core providers come only from validated `module.yaml.providers` and register in dependency order.
- Missing dependency, dependency cycle, invalid provider, and provider-registration failure fail fast.
- Module Kernel has no persisted enable/disable state and no hot load/unload contract.
- `module:list` and `module:status` are read-only metadata commands.
- Event/integration registration is explicit/provider-owned; no global reflection listener discovery.
- Module bootstrap composition, tenant feature availability, and authorization are separate concerns.

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

Module-kernel ADR lifecycle after Phase 4A:

- ADR-001 — Accepted; runtime/bootstrap details amended by ADR-017.
- ADR-003 — Accepted; manifest/provider/bootstrap semantics amended by ADR-017.
- ADR-006 — **Superseded** by ADR-017.
- ADR-007 — **Superseded** by ADR-017.
- ADR-008 — Accepted; current module commands are read-only.
- ADR-010 — Accepted; exact current manifest key with lowercase canonical cutover target.
- ADR-017 — **Accepted** canonical Module Runtime & Bootstrap Contract.

ADR-011 and ADR-012 contain tenancy/authentication assumptions replaced by canonical identity/authentication work and remain **Superseded**.

Current canonical foundation decisions include:

- ADR-013 — Canonical Human Identity.
- ADR-014 — Membership & Tenant Boundary.
- ADR-015 — Authentication Token & Request Context.
- ADR-016 — Database-Backed Tenant RBAC.
- ADR-017 — Module Runtime & Bootstrap Contract.

See [`adr/README.md`](adr/README.md) for the current index.

---

## 7. Historical Documentation

The following documents are classified **HISTORICAL** and retained in place to preserve project history and links:

- [`../prd/CORE-001.md`](../prd/CORE-001.md) — original Platform Kernel Engineering PRD/proposal.
- [`../sprint/sprint-001.md`](../sprint/sprint-001.md) — original Sprint 001 planning/review.

Each document now carries an explicit historical notice. They remain useful for architectural context, but they are not current implementation contracts. See [`../README.md`](../README.md) for documentation lifecycle semantics.

---

## 8. Documentation Alignment Status

The original DOC STEP 1–7 alignment remains historical closure for the Core/Phase-3A baseline.

Phase 4A introduced a newer Module Kernel runtime contract, so documentation is being re-aligned through Phase 4A.9:

```text
4A.9.1  — Documentation Drift Audit
COMPLETE

4A.9.2A — ADR Runtime Contract Alignment
COMPLETE

4A.9.2B — Current Module Kernel Docs
COMPLETE

4A.9.2C — Architecture Overview Alignment
COMPLETE after this document set is committed

4A.9.3  — Documentation Consistency Regression
PENDING
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
- documentation tidak menjanjikan hot module enable/disable atau disabled-module isolation;
- documentation mencatat manifest-driven, dependency-ordered provider activation sebagai current runtime guarantee;
- documentation tidak menghidupkan kembali `ModuleStateRepository`, `ModuleManager`, provider guessing, atau global event auto-discovery sebagai current contract;
- current mixed-case manifest keys dibedakan jelas dari lowercase canonical technical-key target.

Dengan closure ini, `docs/README.md` dan dokumen pada bagian **Current Source of Truth** di atas menjadi baseline dokumentasi resmi sampai ada ADR atau workstream baru yang secara eksplisit mengubahnya.
