# Module Lifecycle

- **Version**: 3.0
- **Status**: Current / Locked
- **Updated**: 2026-08-13

## Overview

Current module lifecycle adalah deployment/bootstrap lifecycle untuk modular monolith:

```text
1. Physical Discovery
2. Manifest Validation
3. Dependency Validation & Ordering
4. Metadata Registration
5. Dependency-Ordered Provider Registration
6. Laravel Provider Boot
```

Ini **bukan hot plugin lifecycle** dan tidak memiliki persisted module enable/disable state.

---

# 1. High-Level Flow

```text
Filesystem: Modules/
      │
      ▼
discover module.yaml
      │
      ▼
parse + validate manifest
      │
      ▼
build ModuleDefinition
      │
      ▼
validate + order dependency graph
      │
      ├── populate ModuleRegistry
      │
      ▼
register non-Core providers
in dependency order
      │
      ▼
Laravel provider boot
      │
      ▼
Laravel Application Runtime
```

---

# 2. Phase A — Module Exists on Filesystem

A direct child directory under:

```text
Modules/
```

menjadi discovery candidate hanya jika memiliki:

```text
module.yaml
```

Directory tanpa manifest diabaikan oleh `ModuleDiscovery`.

Physical presence adalah deployment/application composition concern, bukan tenant feature flag.

---

# 3. Phase B — Metadata Bootstrap

Metadata bootstrap dipicu ketika `ModuleRepository` membutuhkan registry yang belum terisi.

```text
ModuleRepository
      ↓
registry empty
      ↓
ModuleBootstrapService
```

Pipeline:

```text
ModuleDiscovery
      ↓
ModuleManifestLoader
      ↓
ModuleManifestParser
      ↓
ModuleDefinitionFactory
      └── ModuleManifestValidator
      ↓
ModuleDefinition
```

Semua valid definitions kemudian diperiksa oleh `DependencyResolver`.

---

# 4. Phase C — Dependency Validation & Ordering

```text
ModuleDefinition[]
       ↓
DependencyResolver
       ↓
resolved topological order
```

Fail-fast:

```text
missing dependency
circular dependency
```

Resolved dependency order adalah runtime contract:

```text
dependency graph validity       ✅
module loading order            ✅
non-Core provider registration  ✅
```

Core adalah mandatory bootstrap root.

---

# 5. Phase D — Metadata Registration

`ModuleLoader` menerima dependency-ordered definitions dan memasukkan `ModuleDefinition` ke:

```text
ModuleRegistry
```

`ModuleRepository` menjadi read facade untuk application-facing metadata queries.

---

# 6. Phase E — Provider Registration

Non-Core providers berasal hanya dari validated manifest declarations.

```text
dependency-ordered ModuleDefinition[]
      ↓
ModuleProviderRegistrar
      ↓
module.yaml providers
      ↓
Laravel provider registration
```

Provider rules:

- provider class harus valid Laravel `ServiceProvider`;
- tidak ada provider naming-convention guessing;
- registration mengikuti dependency order;
- failure dipropagasikan.

Core tidak diperlakukan sebagai downstream module oleh generic registrar.

---

# 7. Phase F — Explicit Event Registration

Event activation bukan phase global Module Kernel.

Tidak ada:

```text
EventDiscoveryService
ModuleEventRegistry
reflection listener discovery
automatic Listeners/ scanning
```

Event listener/integration didaftarkan secara eksplisit oleh provider/component owner.

---

# 8. Bootstrap Participation Has No Mutable State

Current Module Kernel tidak menyimpan:

```text
enabled
disabled
modules.json
```

dan tidak menyediakan:

```text
module:enable
module:disable
ModuleManager
ModuleStateRepository
```

Module yang physically present dan lolos manifest/dependency validation berpartisipasi dalam application bootstrap.

Mengubah deployed module composition adalah deployment/configuration concern, bukan mutable runtime command.

---

# 9. Tenant Feature Availability Is Separate

Bootstrap participation tidak menentukan apakah tenant/customer boleh menggunakan capability tertentu.

```text
module bootstrap composition
≠ tenant feature / entitlement
≠ authorization
```

Feature/entitlement layer future harus dibangun sebagai concern terpisah ketika concrete requirement dikunci.

---

# 10. Metadata Immutability

Selama application process:

```text
ModuleDefinition
→ immutable metadata object
```

Tidak ada mutable Module Kernel activation state yang dipasangkan ke definition.

Jangan menambahkan lifecycle state baru tanpa concrete requirement dan explicit ADR.

---

# 11. Relationship to Business Module Lifecycle

Module lifecycle tidak menentukan lifecycle business records.

Contoh:

```text
Academic module deployment/bootstrap
≠ Student status

HR module deployment/bootstrap
≠ Employee status
```

Business lifecycle tetap dimiliki domain masing-masing.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-manager.md`](module-manager.md) — historical compatibility note
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry
- ADR-006 — Runtime Module State Repository (**Superseded**)
- ADR-007 — ModuleManager (**Superseded**)
- ADR-017 — Module Runtime & Bootstrap Contract
