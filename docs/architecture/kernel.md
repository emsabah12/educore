# EduCore Platform Module Kernel

- **Version**: 2.0
- **Status**: Current / Revalidated
- **Updated**: 2026-08-12
- **Baseline**: Current repository after Core Canonical Foundation 2G + Phase 3A

## Purpose

Dokumen ini menjelaskan **Platform Module Kernel**, yaitu subsystem di dalam `Modules/Core` yang menangani discovery, manifest, dependency validation, registry metadata, runtime activation state, dan module bootstrap.

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
│   │   ├── Events/ModuleEventRegistry.php
│   │   └── Services/
│   │       ├── ModuleLoader.php
│   │       └── ModuleManager.php
│   └── Registry/ModuleRegistry.php
│
└── Services/
    ├── EventDiscoveryService.php
    ├── ModuleBootstrapService.php
    ├── ModuleRepository.php
    └── ModuleStateRepository.php
```

Physical folder placement masih merupakan hasil evolusi bertahap. Ownership dan dependency direction lebih penting daripada memaksa symmetry namespace hanya demi kosmetik folder.

---

# 2. Current Metadata Bootstrap Flow

Normal module metadata flow saat `ModuleRepository` membutuhkan registry yang belum terisi:

```text
ModuleRepository resolve
        │
        ▼
registry empty?
        │ yes
        ▼
ModuleBootstrapService
        │
        ▼
ModuleDiscovery
        │
        ▼
sorted module.yaml paths
        │
        ▼
ModuleManifestLoader
        │
        ▼
ModuleManifestParser
        │
        ▼
ModuleDefinitionFactory
        │
        └── ModuleManifestValidator
        │
        ▼
ModuleDefinition[]
        │
        ├── DependencyResolver
        │     ├── missing dependency → fail
        │     └── circular dependency → fail
        │
        ├── EventDiscoveryService
        │
        ▼
ModuleLoader
        │
        ▼
ModuleRegistry
```

`ModuleRegistry` adalah in-memory metadata store. `ModuleRepository` menjadi read/query facade yang digunakan application-facing code untuk membaca registry tanpa menyentuh filesystem lagi.

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

Manifest adalah **static module metadata**, bukan runtime state storage.

Tidak boleh menyimpan:

```text
enabled / disabled
request context
environment-specific dynamic state
runtime mutable configuration
```

---

# 4. Module Identity

Module identity menggunakan exact manifest field:

```text
name
```

Contoh current names:

```text
core
Auth
User
Academic
HR
PPDB
```

`ModuleRegistry`, dependency lookup, dan runtime state lookup menggunakan module name sebagai key.

Karena current implementation menggunakan exact string lookup, casing pada manifest name harus diperlakukan sebagai bagian dari technical identity.

Jangan membuat UUID untuk `ModuleDefinition` hanya demi menyeragamkan dengan entity persistence. ModuleDefinition adalah metadata object, bukan persisted business entity.

---

# 5. Dependency Resolution

`DependencyResolver` saat ini:

- membaca `ModuleDefinition.dependencies`;
- fail-fast pada dependency yang tidak ditemukan;
- fail-fast pada circular dependency;
- menghasilkan topological order.

Contoh current dependency:

```text
Academic
├── core
└── HR
```

Namun terdapat boundary penting pada current implementation:

> Dependency resolution saat ini **belum boleh dianggap sebagai jaminan final provider boot order**.

`ModuleBootstrapService` menggunakan resolved order untuk event discovery, tetapi current `ModuleLoader` masih menerima collection definition hasil discovery. Provider activation juga dilakukan melalui flow terpisah di `CoreServiceProvider`.

Karena itu, dependency validation sudah implemented, tetapi provider boot ordering masih merupakan **kernel hardening candidate**, bukan public contract yang frozen.

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

Registry adalah in-memory source of truth untuk metadata yang sudah berhasil di-bootstrap pada application process tersebut.

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

Tidak ada `ModuleRepositoryInterface` pada current contract karena abstraction tersebut sebelumnya dinilai premature untuk kebutuhan yang tersedia.

---

# 7. Runtime Activation State

Runtime activation preference disimpan oleh:

```text
ModuleStateRepository
```

Current persistence:

```text
storage/framework/modules.json
```

Shape:

```json
{
  "Academic": {
    "enabled": true
  }
}
```

`ModuleStateRepository` menangani:

```text
all()
isEnabled(name)
enable(name)
disable(name)
```

Invalid/missing state defaults secara defensif ke disabled/not-enabled untuk key yang tidak tersedia.

## Important semantic

Current `enabled/disabled` adalah **bootstrap activation state**, bukan hot module lifecycle.

```text
module:enable Foo
        ↓
persist enabled=true
        ↓
future application bootstrap observes new state
```

Demikian juga `module:disable` tidak dapat meng-unregister provider/listener yang sudah ter-load pada process yang sedang berjalan.

Dokumentasi tidak boleh menjanjikan hot plug/unplug behavior.

---

# 8. ModuleManager Current Role

`ModuleManager` adalah command/mutation service untuk lifecycle state.

Current operations:

```text
isEnabled(name)
enable(name)
disable(name)
getEnabledModules()
```

Manager mengorkestrasi:

```text
ModuleRepository
+
ModuleStateRepository
```

Current architecture menggunakan lightweight CQS:

```text
READ
module:list / module:status
        ↓
ModuleRepository + ModuleStateRepository

MUTATION
module:enable / module:disable
        ↓
ModuleManager
        ↓
ModuleRepository + ModuleStateRepository
```

Karena itu, `ModuleManager` bukan lagi facade wajib untuk setiap read query.

---

# 9. Current Console Commands

Current commands:

```text
module:list
module:status {name}
module:enable {name}
module:disable {name}
kernel:test-loader
```

Principle:

- commands tetap tipis;
- filesystem/manifest parsing tidak dilakukan oleh command;
- read commands boleh menggunakan query facade;
- mutation commands menggunakan ModuleManager.

---

# 10. Provider Activation

Current `CoreServiceProvider` melakukan activation check berdasarkan `ModuleStateRepository` saat application bootstrap.

Namun provider activation **belum menjadi clean/frozen manifest-driven contract**.

Current source masih mempunyai transitional behavior:

1. dynamic registration menggunakan naming convention `Modules\\<Name>\\Providers\\<Name>ServiceProvider`;
2. manifest `providers` divalidasi tetapi bukan satu-satunya runtime registration path;
3. `bootstrap/providers.php` masih memiliki direct provider registration untuk Core dan Academic.

Akibatnya:

> `enabled=false` belum boleh didokumentasikan sebagai jaminan bahwa seluruh code path suatu module tidak akan di-bootstrap.

Ini adalah **known kernel hardening item**, bukan alasan untuk mengubah Identity/Tenancy/RBAC foundation.

Jangan menyalin transitional provider wiring ini ke module baru sebagai public architecture pattern sebelum provider boot strategy diaudit dan di-lock.

---

# 11. Event Discovery

`ModuleBootstrapService` memanggil `EventDiscoveryService` terhadap module definitions hasil dependency resolution.

`CoreServiceProvider::boot()` kemudian memasang listener yang terkumpul ke Laravel Event dispatcher melalui `ModuleEventRegistry`.

Current caveat:

- event discovery terjadi pada bootstrap metadata flow;
- filtering berdasarkan runtime enabled state belum menjadi explicit invariant di `ModuleBootstrapService`.

Karena itu, semantics event activation juga perlu ikut diverifikasi pada future Module Kernel Hardening.

---

# 12. Fail-Fast Rules

Current Module Kernel fail-fast pada kondisi seperti:

```text
invalid YAML
missing required manifest field
invalid field type
missing Service Provider class declared in manifest
missing module dependency
circular dependency
duplicate module registration
unknown module mutation target
```

Fail-fast validation harus terjadi sedekat mungkin dengan bootstrap/configuration error.

---

# 13. Current Guarantees vs Non-Guarantees

## Current guarantees

```text
automatic manifest discovery
sorted discovery result
manifest validation
immutable ModuleDefinition
in-memory registry
concrete metadata query facade
missing/circular dependency detection
JSON activation-state persistence
thin command boundary
```

## Not yet frozen guarantees

```text
hot enable / hot disable
provider unload
manifest.providers as sole activation mechanism
dependency-ordered provider registration
enabled-state-gated event discovery
external/plugin module marketplace
```

Future module work must not assume these non-guarantees already exist.

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

Those concerns have their own architecture contracts.

---

# 15. Related Documents

- [`README.md`](README.md)
- [`current-architecture.md`](current-architecture.md)
- [`folder-structure.md`](folder-structure.md)
- [`architecture-principles.md`](architecture-principles.md)
- [`discovery-flow.md`](discovery-flow.md)
- [`module-manager.md`](module-manager.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- [`adr/README.md`](adr/README.md)
