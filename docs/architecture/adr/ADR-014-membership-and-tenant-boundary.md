# ADR-014 — Membership & Tenant Boundary

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-12
**Scope** : Core Canonical Foundation 2G + Phase 3A
**Supersedes** : ADR-011 implementation mechanics for tenant context/ownership

---

> **Decision Summary**
>
> EduCore menggunakan `Tenant` sebagai customer/security/data-isolation boundary dan `Membership` sebagai canonical participation of a `Person` in a `Tenant`. Membership dimiliki oleh Person, bukan User. Tenant authorization tidak boleh berasal hanya dari tenant locator yang dikirim client; canonical tenant context harus diverifikasi melalui User → Person → Membership → Tenant ownership. Shared-schema single-database strategy tetap dipertahankan.

---

# Related ADR

- ADR-011 — Multi-Tenancy Architecture Strategy (**Superseded**)
- ADR-013 — Canonical Human Identity
- ADR-015 — Authentication Token & Request Context
- ADR-016 — Database-Backed Tenant RBAC

---

# 1. Context

EduCore adalah aplikasi multi-tenant dan membutuhkan hard tenant boundary untuk mencegah data leakage antar customer/security domain.

Model awal tenancy pernah mengaitkan tenant selection langsung ke User atau tenant locator seperti header/subdomain. Setelah canonical identity refactor, pendekatan tersebut tidak lagi cukup karena:

- User adalah global digital account;
- satu Person dapat memiliki participation pada tenant melalui Membership;
- client-provided tenant locator tidak membuktikan authorization;
- downstream domain profiles membutuhkan tenant boundary yang konsisten;
- tenant context harus aman pada HTTP, service, repository, dan queued/background execution boundaries.

---

# 2. Decision

## Tenant adalah security/data-isolation boundary

Current persistence strategy tetap:

```text
Single Database
+ Shared Schema
+ explicit tenant ownership
```

`Tenant` merepresentasikan current customer/security/data-isolation boundary.

Organization/Lembaga dan Branch/Unit belum menjadi current locked contract dan tidak boleh disamakan dengan Tenant sebelum dedicated topology decision diterima.

---

## Membership adalah Person participation in Tenant

Canonical graph:

```text
Person
  ↓
Membership
  ↓
Tenant
```

Membership bukan child dari User.

Canonical uniqueness:

```text
UNIQUE(person_id, tenant_id)
```

Legacy relationships berikut tidak canonical:

```text
memberships.user_id
memberships.role
```

---

## Tenant locator bukan authorization authority

Header, host, subdomain, atau request identifier dapat digunakan sebagai locator/routing hint bila dibutuhkan, tetapi tidak memberikan authorization dengan sendirinya.

Canonical tenant authorization harus memverifikasi ownership path:

```text
Authenticated User
  ↓
User.person_id
  ↓
Membership.person_id
Membership.tenant_id
Membership.status
  ↓
Tenant.status
  ↓
Verified TenantContext
```

Cross-tenant or corrupted ownership projections harus fail closed.

---

## Tenant-aware persistence menggunakan defense in depth

Eloquent domain models yang tenant-owned dapat menggunakan `BelongsToTenant`.

Query Builder/repository paths yang tidak menggunakan model scope harus memiliki explicit tenant predicates.

Dengan demikian architectural invariant adalah **tenant isolation**, bukan kewajiban menggunakan satu implementation mechanism untuk seluruh persistence.

---

# 3. Rationale

Keputusan ini memisahkan empat konsep yang sebelumnya mudah tercampur:

```text
Human Identity    → Person
Digital Account   → User
Tenant Participation → Membership
Security Boundary → Tenant
```

Keuntungan:

- tenant ownership tidak bergantung pada User schema;
- satu Person dapat berpartisipasi pada tenant secara explicit;
- tenant locator tidak dapat digunakan untuk melewati membership authorization;
- downstream profiles memiliki tenant projection yang dapat diverifikasi;
- future Organization/Branch dapat ditambahkan di dalam Tenant tanpa merombak canonical Membership ownership.

---

# 4. Architectural Rules

- `Tenant` adalah current security/data-isolation boundary.
- Membership merepresentasikan Person participation in Tenant.
- Membership dimiliki Person, bukan User.
- Membership harus tenant-scoped dan ownership-nya harus dapat diverifikasi.
- `memberships.user_id` tidak boleh diperkenalkan kembali.
- `memberships.role` tidak boleh digunakan sebagai authorization source.
- Client-provided tenant locator tidak cukup untuk authorization.
- Inactive Membership/Tenant tidak boleh menghasilkan active TenantContext.
- Tenant-aware persistence harus menggunakan model scope dan/atau explicit tenant predicates.
- Cross-tenant relationships harus fail closed.
- Organization/Branch tidak boleh diasumsikan identik dengan Tenant sebelum ADR khusus diterima.

---

# 5. Consequences

## Positive

- Tenant isolation menjadi explicit dan testable.
- User tetap global dan reusable.
- Membership menjadi canonical participant boundary.
- Domain profiles dapat memiliki tenant projection tanpa menjadi tenant-authority sendiri.
- Foundation siap diperluas menuju Organization/Branch tanpa mengubah identity ownership.

## Negative

- Repository/service harus disiplin membawa/verifikasi tenant identifier.
- Read queries dengan duplicated tenant projections perlu consistency checks.
- Additional joins/checks diperlukan untuk beberapa authorization paths.

---

# 6. Alternatives Considered

## Option A — User belongs directly to Tenant

**Rejected**, karena mencampur authentication account dengan tenant participation dan menghambat multi-membership/person-centric architecture.

---

## Option B — Trust tenant header/subdomain after authentication

**Rejected**, karena requested tenant bukan proof of Membership ownership.

---

## Option C — Person-owned Membership + verified TenantContext (**Accepted**)

Membership menjadi canonical participation record dan TenantContext hanya dibuat setelah ownership/status verification.

---

# 7. Tenant Context Flow

```text
Bearer-authenticated User
          │
          ▼
        Person
          │
          ▼
      Membership
      │        │
   ACTIVE   tenant_id
      │        │
      └───┬────┘
          ▼
        Tenant
          │
       ACTIVE
          │
          ▼
   Verified TenantContext
```

---

# 8. Current Implementation

Current implementation includes:

- Person-owned `memberships`;
- tenant membership uniqueness by Person/Tenant;
- canonical tenant context verification;
- `BelongsToTenant` foundation for tenant-aware models;
- explicit tenant predicates on repository/query paths where model scopes are not used;
- cross-tenant regression coverage in Core and downstream profile/grading flows;
- tenant-aware job/context lifecycle support.

---

# 9. Validation / Regression Contract

Tenant boundary is covered by regression tests including:

- membership repository isolation;
- membership context resolution;
- tenant context lifecycle;
- multi-tenancy isolation;
- authenticated tenant context middleware;
- Student/Guardian/Employee tenant projections;
- Guardian ↔ Student cross-tenant protection;
- Academic grading target isolation;
- tenant-aware jobs.

---

# 10. Impact

ADR ini membekukan Tenant sebagai current top-level security boundary dan Membership sebagai Person-to-Tenant participation contract.

Future multi-lembaga/multi-cabang work harus **extend inside this boundary** unless a new explicit ADR proves that a different security boundary is required.
