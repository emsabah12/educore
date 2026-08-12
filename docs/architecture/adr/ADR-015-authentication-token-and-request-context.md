# ADR-015 — Authentication Token & Request Context

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-12
**Scope** : Core/Auth Canonical Foundation
**Supersedes** : ADR-012

---

> **Decision Summary**
>
> EduCore menggunakan encrypted deterministic bearer token melalui canonical Token Manager. Token membawa `user_id`, `tenant_id`, `membership_id`, dan `expires_at`. Role, Permission, dan Person identity tidak dipercaya sebagai authorization claims. Middleware memverifikasi current User → Person → Membership → Tenant ownership sebelum menginjeksi authenticated request context. Request-dependent resolvers harus membaca current Request instance dan tidak mempertahankan stale Request object lintas lifecycle.

---

# Related ADR

- ADR-012 — Tenant-Aware Authentication Guard (**Superseded**)
- ADR-013 — Canonical Human Identity
- ADR-014 — Membership & Tenant Boundary
- ADR-016 — Database-Backed Tenant RBAC

---

# 1. Context

Authentication architecture awal mengasumsikan tenant-aware User lookup dan `users.tenant_id`. Canonical identity refactor menghapus assumption tersebut.

Current system membutuhkan authentication flow yang:

- menggunakan User sebagai global digital account;
- mengikat tenant participation melalui Membership;
- menghasilkan deterministic bearer context;
- tidak mempercayai stale role/permission claims;
- melakukan ownership/status verification sebelum TenantContext dibuat;
- aman pada repeated HTTP request lifecycle di long-running/test application scope.

---

# 2. Decision

## Encrypted bearer token

Current authentication menggunakan encrypted deterministic bearer token yang diterbitkan/dibaca melalui canonical Token Manager.

JWT tidak menjadi current token strategy.

Canonical token claims:

```text
user_id
tenant_id
membership_id
expires_at
```

Authorization claims berikut tidak canonical:

```text
role
permission
```

`person_id` juga tidak diperlukan sebagai token claim karena canonical ownership dapat diturunkan dari User persistence.

---

## Authentication dan tenant-context verification dipisahkan

Canonical flow:

```text
Login
  ↓
Token Manager
  ↓
Bearer Token
  ↓
InjectAuthenticatedUser
  ↓
InjectTenantContext
```

User authentication membuktikan account identity.

Tenant-context middleware kemudian memverifikasi:

```text
User is ACTIVE
User.person_id exists
Membership.id == token membership_id
Membership.person_id == User.person_id
Membership.tenant_id == token tenant_id
Membership is ACTIVE
Tenant is ACTIVE
```

Hanya setelah itu request memperoleh verified context.

---

## Canonical request attributes

Verified request context menggunakan canonical identifiers seperti:

```text
authenticated_user_id
authenticated_membership_id
authenticated_tenant_id
```

Downstream code tidak boleh menganggap arbitrary header/request tenant value sebagai already-authorized context.

---

## Request lifecycle safety

Request-dependent context resolver harus membaca current Request instance saat resolve dilakukan.

Tidak boleh menyimpan `Illuminate\Http\Request` lama pada long-lived/scoped service dengan asumsi instance tersebut selalu current.

Canonical behavior:

```text
request #1
  ↓
current request context #1

request #2
  ↓
current request context #2
```

---

# 3. Rationale

Token dibuat minimal dan hanya membawa identifiers yang dibutuhkan untuk deterministic context verification.

Tidak membawa Role/Permission memberikan beberapa keuntungan:

- authorization state tidak menjadi stale di dalam token;
- role changes berlaku berdasarkan database state;
- token tidak menjadi second source of truth untuk RBAC;
- authorization tetap berada pada AuthorizationService.

Separasi middleware juga menjaga distinction:

```text
Authentication
≠
Tenant Authorization
```

---

# 4. Architectural Rules

- User adalah canonical authenticated account.
- Bearer token harus diproses melalui canonical Token Manager.
- Canonical token claims adalah `user_id`, `tenant_id`, `membership_id`, `expires_at`.
- Role/Permission claims tidak menjadi authorization source.
- Tenant context hanya dibuat setelah canonical ownership/status verification.
- Missing/malformed/unknown/inactive identity context harus fail closed.
- Raw bearer token tidak boleh dipersist/log sebagai ordinary diagnostic data.
- Request-dependent resolvers harus membaca current request instance.
- Downstream module harus menggunakan verified request attributes/context, bukan merekonstruksi authentication rules sendiri.

---

# 5. Consequences

## Positive

- Token contract kecil dan stabil.
- RBAC changes tidak memerlukan token role refresh untuk correctness.
- User/Person/Membership/Tenant responsibilities terpisah.
- Middleware dapat diuji end-to-end.
- Stale Request lifecycle bug dapat dicegah secara architectural.

## Negative

- Setiap authorized request membutuhkan persistence/context verification sesuai middleware contract.
- Role/permission tidak dapat dibaca langsung dari token tanpa AuthorizationService lookup.
- Context-dependent services harus disiplin terhadap application/request lifecycle.

---

# 6. Alternatives Considered

## Option A — JWT dengan embedded role/permission

**Rejected for current architecture**, karena belum ada interoperability requirement yang membenarkan additional token/claim complexity dan embedded authorization state berisiko stale.

---

## Option B — Tenant-aware User lookup (`users.tenant_id`)

**Rejected/Superseded**, karena User adalah global account dan tenant participation berada pada Membership.

---

## Option C — Encrypted deterministic bearer token + verified Membership context (**Accepted**)

Token membawa canonical identifiers, sementara ownership/authorization state diselesaikan dari persistence.

---

# 7. Authentication / Context Flow

```text
Credentials
   │
   ▼
  User
   │
   ▼
Token Manager
   │
   ▼
Bearer Token
   │
   ├── user_id
   ├── tenant_id
   ├── membership_id
   └── expires_at
   │
   ▼
Authenticated User
   │
   ▼
User → Person → Membership → Tenant
   │
   ▼
Verified Request / Tenant Context
```

---

# 8. Current Implementation

Current implementation includes:

- deterministic encrypted Token Manager;
- bearer-token authentication flow;
- token revocation persistence using non-raw token fingerprinting;
- `InjectAuthenticatedUser` middleware;
- `InjectTenantContext` middleware;
- canonical request attributes for user/membership/tenant;
- Membership context resolver reading the current Request instance;
- explicit regression for malformed/missing/cross-tenant/inactive contexts.

---

# 9. Validation / Regression Contract

Current regression coverage includes:

- complete login → bearer token → tenant context flow;
- invalid credentials/token rejection;
- token lifetime behavior;
- token revocation;
- unknown/suspended User rejection;
- wrong/cross-person/cross-tenant Membership rejection;
- inactive Tenant/Membership rejection;
- missing/malformed claim rejection;
- second-request/current-Request context regression.

---

# 10. Impact

ADR-012 must no longer be used as current implementation guidance.

Any future authentication provider, SSO integration, or token format change must preserve the canonical separation between:

```text
User authentication
Membership tenant participation
Database-backed authorization
```

unless a new ADR explicitly replaces this contract.
