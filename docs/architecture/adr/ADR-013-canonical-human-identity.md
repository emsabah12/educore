# ADR-013 — Canonical Human Identity

**Version** : 1.0
**Status** : Accepted
**Date** : 2026-08-12
**Scope** : Core Canonical Foundation 2G + Phase 3A

---

> **Decision Summary**
>
> EduCore menggunakan `Person` sebagai canonical global human identity. `User` adalah optional digital/authentication account milik `Person`, sedangkan Student, Guardian, dan Employee adalah downstream domain profiles yang memperoleh human identity melalui `Membership`. Human identity tidak boleh kembali diduplikasi ke User atau downstream profiles untuk alasan kompatibilitas legacy.

---

# Related ADR

- ADR-002 — Modular Monolith Architecture
- ADR-014 — Membership & Tenant Boundary
- ADR-015 — Authentication Token & Request Context
- ADR-016 — Database-Backed Tenant RBAC

---

# 1. Context

Sebelum canonicalization, identitas manusia tersebar pada beberapa model. `User` pernah diperlakukan sebagai sumber identity sekaligus tenant ownership, sedangkan downstream profiles seperti Student, Guardian, dan Employee menyimpan atau mengasumsikan data manusia secara terpisah.

Pendekatan tersebut menimbulkan beberapa masalah:

- satu manusia dapat direpresentasikan berkali-kali;
- login account bercampur dengan human identity;
- domain profiles terdorong membuat User hanya agar memiliki identity;
- data kontak dan biodata sulit dijaga konsistensinya;
- cross-module integration menjadi rapuh;
- multi-tenant participation sulit dipisahkan dari authentication account.

EduCore membutuhkan satu canonical human identity yang dapat digunakan oleh semua modul tanpa memaksa setiap manusia memiliki login account.

---

# 2. Decision

## Person adalah canonical human identity

Canonical graph:

```text
Person
  │
  ├── User (optional digital account)
  │
  └── Membership
        │
        ├── Student
        ├── Guardian
        └── Employee
```

`Person` merepresentasikan manusia global dan menjadi canonical owner untuk human data.

Human data ditempatkan pada `Person` atau Person-owned supporting records, termasuk bila relevan:

```text
persons
person_contacts
person_addresses
person_identifiers
person_citizenships
person_lifecycle_events
```

---

## User adalah digital account

Canonical relation:

```text
User
  ↓
Person
```

`User` digunakan untuk authentication/digital account dan bukan sebagai canonical human profile.

`User` tidak menjadi tenant-owned entity dan tidak menjadi sumber canonical display name manusia.

Login email pada User merupakan credential account. Personal/contact email, jika diperlukan sebagai human contact data, berada pada Person-owned contact model.

---

## Domain profiles bukan human identity

Student, Guardian, dan Employee adalah domain profiles.

Canonical relations:

```text
Person
  ↓
Membership
  ↓
Student
```

```text
Person
  ↓
Membership
  ↓
Guardian
```

```text
Person
  ↓
Membership
  ↓
Employee
```

Profiles tersebut tidak boleh kembali menyimpan `person_id` apabila identity sudah dapat diturunkan secara canonical melalui `membership_id`.

Profiles juga tidak membuat User secara otomatis hanya untuk menyediakan identity.

---

# 3. Rationale

Keputusan ini memberikan satu identity graph yang dapat digunakan lintas modul.

Keuntungan utama:

- satu manusia memiliki satu canonical identity;
- login account menjadi optional;
- Academic, HR, Finance, Attendance, Dormitory, dan modul lain dapat merujuk manusia yang sama;
- human data tidak diduplikasi pada domain profiles;
- tenant participation dapat dimodelkan terpisah melalui Membership;
- downstream module tidak perlu mengetahui authentication internals;
- future multi-lembaga/multi-cabang dapat dibangun tanpa membuat human identity baru.

---

# 4. Architectural Rules

Seluruh implementasi harus mengikuti aturan berikut.

- `Person` adalah canonical human identity.
- `User` merepresentasikan digital/authentication account.
- Satu User terkait ke satu Person canonical.
- Person dapat ada tanpa User.
- Student, Guardian, dan Employee bukan pengganti Person.
- Domain profile tidak boleh membuat User/default password secara otomatis tanpa explicit account-provisioning requirement.
- Human name tidak boleh kembali dijadikan canonical field pada User/Student/Guardian/Employee.
- Person-owned supporting data digunakan untuk data manusia seperti contacts/addresses/identifiers sesuai kebutuhan domain.
- Downstream module mengonsumsi canonical identity contract; Core tidak menambah legacy compatibility identity fields.

---

# 5. Consequences

## Positive

- Human identity konsisten lintas module.
- Login account tidak dipaksakan untuk seluruh manusia.
- Duplicate identity berkurang.
- Integration lintas Academic/HR/domain lain menjadi lebih sederhana.
- Tenant membership tidak bergantung pada keberadaan User account.
- Domain profile dapat berkembang tanpa mengambil alih responsibility Person.

## Negative

- Read-side domain profile sering membutuhkan join melalui Membership ke Person.
- Legacy code yang membaca nama/contact langsung dari profile harus direfactor.
- Account provisioning dan profile provisioning menjadi concern yang berbeda dan harus diorkestrasi secara explicit ketika keduanya dibutuhkan.

---

# 6. Alternatives Considered

## Option A — User sebagai canonical human identity

**Rejected**, karena User adalah authentication account dan tidak semua manusia membutuhkan login.

Pendekatan ini juga mendorong tenant ownership dan domain profile coupling ke User.

---

## Option B — Setiap domain profile memiliki human identity sendiri

**Rejected**, karena menghasilkan duplicate people dan menyulitkan cross-module integration.

---

## Option C — Person sebagai canonical human identity (**Accepted**)

Person menjadi identity manusia global, User menjadi optional account, dan domain profiles memperoleh identity melalui Membership.

---

# 7. Canonical Identity Flow

```text
                    Person
                  /        \
               User      Membership
              account       │
                            ├── Student
                            ├── Guardian
                            └── Employee
```

Authentication dan domain-profile identity bertemu pada Person, bukan pada duplicated profile data.

---

# 8. Current Implementation

Current canonical foundation mencakup:

- `persons` sebagai human identity persistence;
- `users.person_id` sebagai User → Person relationship;
- `memberships.person_id` sebagai Person → Tenant participation relationship;
- Student/Guardian/Employee profiles melalui Membership;
- Person-owned supporting identity/contact records;
- removal of legacy User/profile human-identity coupling from the canonical downstream flows.

---

# 9. Validation / Regression Contract

Keputusan ini telah divalidasi melalui regression coverage untuk:

- Person persistence;
- User persistence;
- Membership ownership;
- Student provisioning/read model;
- Guardian provisioning/read model;
- Employee provisioning/read model;
- Guardian ↔ Student relationship;
- grading actor identity;
- multi-tenancy isolation.

`migrate:fresh --seed` juga menjadi schema/bootstrap gate pada current development baseline.

---

# 10. Impact

ADR ini membekukan responsibility human identity EduCore.

Future Organization, Branch, Dormitory, Finance, Attendance, dan modul lain harus mengonsumsi `Person`/`Membership` identity graph dan tidak membuat parallel human identity model kecuali terdapat domain entity yang secara eksplisit bukan representasi manusia canonical.
