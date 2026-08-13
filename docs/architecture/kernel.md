# EduCore Platform Module Kernel

- **Version**: 3.0
- **Status**: Current / Locked
- **Updated**: 2026-08-13
- **Baseline**: Phase 4A Module Kernel Runtime Hardening

## Purpose

Dokumen ini menjelaskan **Platform Module Kernel**, yaitu subsystem di dalam `Modules/Core` yang menangani physical module discovery, manifest validation, dependency validation/order, registry metadata, deterministic provider activation, dan module bootstrap.

`Platform Module Kernel` **bukan sinonim untuk seluruh `Modules/Core`**.

Current Core juga memiliki foundation lain:

```text
Modules/Core
├── Platform Module Kernel
├── Human Identity
├── Tenancy
├── Authorization / RBAC
├── Governance / Audit
└── Shared Platform Infrastructure
```

Business logic Academic, HR, Finance, Dormitory, dan domain lain tidak boleh dipindahkan ke Module Kernel.

---

# 1. Current Ownership

Current source utama Module Kernel:

```text
Modules/Core/
├── Manifest/
│   ├── ModuleManifestLoader.php
│   ├── ModuleManifestParser.php
│   ├── ModuleManifestValidator.php
│   └── ModuleDefinitionFactory.php
│
├── Platform/
│   ├── Console/
│   ├── Dependency/DependencyResolver.php
│   ├── Discovery/ModuleDiscovery.php
│   ├── Module/
│   │   ├── Domain/ModuleDefinition.php
│   │   └── Services/ModuleLoader.php
│   └── Registry/ModuleRegistry.php
│
└── Services/
    ├── ModuleBootstrapService.php
    ├── ModuleProviderRegistrar.php
    └── ModuleRepository.php
```

Physical folder placement masih merupakan hasil evolusi bertahap. Ownership dan dependency direction lebih penting daripada memaksa symmetry namespace hanya demi kosmetik folder.

---

# 2. Current Bootstrap Flow

Normal bootstrap flow:

```text
Modules/
  ↓
ModuleDiscovery
  ↓
sorted module.yaml paths
  ↓
ModuleManifestLoader
  ↓
ModuleManifestParser
  ↓
ModuleDefinitionFactory
  └── ModuleManifestValidator
  ↓
ModuleDefinition[]
  ↓
DependencyResolver
  ├── missing dependency → fail
  └── circular dependency → fail
  ↓
dependency-ordered ModuleDefinition[]
  ├── ModuleLoader → ModuleRegistry
  └── ModuleProviderRegistrar → non-Core providers
```

`ModuleRegistry` adalah in-memory metadata store. `ModuleRepository` menjadi read/query facade untuk application-facing metadata operations.

Core adalah mandatory bootstrap root dan tidak diregistrasikan sebagai downstream module oleh generic non-Core provider registrar.

---

# 3. Module Manifest Contract

Setiap current module menggunakan:

```text
Modules/<Module>/module.yaml
```

Current required fields:

```text
schema
display_name
name
version
description
providers
dependencies
metadata
extra
```

Processing:

```text
raw YAML
  ↓
parsed array
  ↓
validated manifest
  ↓
immutable ModuleDefinition
```

Manifest adalah **static module metadata**.

Manifest tidak boleh menyimpan:

```text
enabled / disabled
request context
environment-specific dynamic state
runtime mutable configuration
```

Declared provider classes divalidasi agar:

- dapat di-autoload;
- exist;
- merupakan Laravel `ServiceProvider`.

---

# 4. Module Identity

Current runtime identity menggunakan exact manifest field:

```text
name
```

Current physical manifest keys:

```text
core
Auth
User
Academic
HR
PPDB
```

`ModuleRegistry` dan dependency lookup menggunakan exact module name sebagai key.

Canonical target technical key adalah lowercase slug:

```regex
^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$
```

Physical lowercase cutover belum dilakukan. Sampai migration tersebut dilakukan:

- current exact manifest names tetap digunakan;
- tidak ada silent normalization;
- tidak ada permanent alias system.

Jangan membuat UUID untuk `ModuleDefinition`. ModuleDefinition adalah metadata object, bukan persisted business entity.

---

# 5. Dependency Resolution

`DependencyResolver`:

- membaca `ModuleDefinition.dependencies`;
- fail-fast pada missing dependency;
- fail-fast pada circular dependency;
- menghasilkan topological order.

Current dependency graph:

```text
Core      → []
Auth      → Core
User      → Core, Auth
HR        → Core, Auth
Academic  → Core, HR, Auth
PPDB      → Core
```

Arrows berarti **depends on**.

Resolved topological order adalah runtime contract untuk module loading dan non-Core provider registration.

Core tidak boleh memiliki reverse dependency ke Auth atau business module.

---

# 6. Registry and Read Model

## ModuleRegistry

Responsibility:

```text
register ModuleDefinition
has(name)
get(name)
all()
count()
```

Registry adalah in-memory source of truth untuk metadata yang berhasil di-bootstrap pada application process.

## ModuleRepository

`ModuleRepository` adalah concrete query/read facade di atas registry.

Responsibility:

```text
all()
find(name)
has(name)
count()
```

Current IoC binding menggunakan lazy/JIT bootstrap guard:

```text
if registry empty
    bootstrap modules
    populate singleton registry
```

Tidak ada `ModuleRepositoryInterface` pada current contract.

---

# 7. Bootstrap Participation

EduCore tidak memiliki persisted Module Kernel enable/disable state.

Bootstrap participation berasal dari deployment composition:

```text
physically present
      ↓
discovered
      ↓
manifest valid
      ↓
dependencies valid
      ↓
bootstrappable
```

Komponen berikut **bukan** bagian dari current contract:

```text
ModuleStateRepository
storage/framework/modules.json
ModuleManager
module:enable
module:disable
```

Module yang physically present dan lolos manifest/dependency validation berpartisipasi dalam application bootstrap.

---

# 8. Current Console Query Boundary

Current module commands bersifat read-only:

```text
module:list
module:status {name}
```

Keduanya membaca module metadata melalui `ModuleRepository`.

Thin-command rules:

- command tidak scan filesystem;
- command tidak parse/validate manifest;
- command tidak resolve dependency graph;
- command tidak register provider;
- command tidak memutasi module bootstrap state.

---

# 9. Provider Activation

`bootstrap/providers.php` memuat application/bootstrap providers, termasuk:

```text
AppServiceProvider
CoreServiceProvider
```

Business-module providers tidak didaftarkan statis di sana.

Non-Core provider activation:

```text
validated module.yaml providers
        ↓
dependency-ordered ModuleDefinition[]
        ↓
ModuleProviderRegistrar
        ↓
Laravel provider registration
```

Canonical rules:

- manifest `providers` adalah sole non-Core provider activation source;
- tidak ada provider guessing berdasarkan module name/folder;
- provider registration mengikuti dependency order;
- provider registration failure dipropagasikan.

---

# 10. Event Registration

Global event auto-discovery telah dihapus.

Module Kernel tidak melakukan:

```text
EventDiscoveryService
ModuleEventRegistry
reflection listener discovery
automatic Listeners/ scanning
```

Event listener/integration didaftarkan secara eksplisit oleh provider/component yang memiliki integration tersebut.

Folder `Listeners/` boleh digunakan untuk organisasi source code, tetapi folder tersebut tidak memiliki automatic runtime semantics.

---

# 11. Separation of Concerns

Current contract membedakan:

```text
module bootstrap composition
≠ tenant feature / entitlement availability
≠ authorization
```

Module Kernel menentukan application code/dependency/provider bootstrap.

Tenant feature availability dan authorization harus menggunakan boundary masing-masing dan tidak boleh direpresentasikan sebagai mutable Module Kernel activation state.

---

# 12. Fail-Fast Rules

Current Module Kernel fail-fast pada:

```text
invalid YAML
missing required manifest field
invalid field type
missing/invalid Service Provider class
missing module dependency
circular dependency
duplicate module registration
provider registration failure
```

Configuration/bootstrap error tidak boleh ditelan atau diubah menjadi partial unknown state.

---

# 13. Current Guarantees and Explicit Non-Contracts

## Current guarantees

```text
automatic physical manifest discovery
deterministic sorted discovery
manifest validation
immutable ModuleDefinition
in-memory ModuleRegistry
ModuleRepository read facade
missing/circular dependency fail-fast
dependency-ordered module loading
manifest-driven non-Core provider activation
dependency-ordered provider registration
explicit provider-owned event registration
read-only module console boundary
```

## Explicit non-contracts

```text
persisted runtime module enable / disable state
hot provider load / unload
global reflection event discovery
tenant-specific provider bootstrap
provider naming-convention guessing
external/plugin marketplace
```

Future work yang mengubah invariant ini membutuhkan concrete requirement dan explicit ADR.

---

# 14. Relationship to the Rest of Core

The Module Kernel manages **module mechanics**.

It does not own:

```text
Person semantics
User authentication identity
Membership tenant ownership
RBAC role/permission semantics
Student/Guardian/Employee business rules
Organization/Branch topology
Dormitory business rules
```

---

# 15. Related Documents

- [`README.md`](README.md)
- [`current-architecture.md`](current-architecture.md)
- [`folder-structure.md`](folder-structure.md)
- [`architecture-principles.md`](architecture-principles.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-manager.md`](module-manager.md) — historical compatibility note
- [`module-lifecycle.md`](module-lifecycle.md)
- [`adr/README.md`](adr/README.md)
- ADR-017 — Module Runtime & Bootstrap Contract
