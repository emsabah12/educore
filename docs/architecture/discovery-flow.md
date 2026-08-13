# Module Discovery & Bootstrap Flow

- **Version**: 3.0
- **Status**: Current / Locked
- **Updated**: 2026-08-13

## Overview

Module discovery menemukan physical `module.yaml` dan mengubahnya menjadi dependency-ordered `ModuleDefinition` yang tervalidasi.

Current implementation tidak menggunakan `DiscoveredModule` Value Object. `ModuleDiscovery` mengembalikan **sorted manifest path strings** langsung ke bootstrap pipeline.

Discovery, dependency ordering, registry loading, dan provider activation merupakan bagian dari deterministic application bootstrap.

---

# 1. Trigger

Metadata/bootstrap flow terjadi saat `ModuleRepository` di-resolve dan singleton `ModuleRegistry` masih kosong.

```text
resolve ModuleRepository
        │
        ▼
ModuleRegistry.count() === 0 ?
        │ yes
        ▼
ModuleBootstrapService.bootstrap(base_path('Modules'))
```

Pada normal application bootstrap, `CoreServiceProvider` membutuhkan dependency-ordered module metadata untuk mendaftarkan non-Core providers.

---

# 2. Discovery Pipeline

```text
Modules/
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
raw YAML string
   │
   ▼
ModuleManifestParser
   │
   ▼
parsed array
   │
   ▼
ModuleDefinitionFactory
   │
   └──── ModuleManifestValidator
   │
   ▼
ModuleDefinition[]
```

Setiap stage memiliki satu tanggung jawab.

---

# 3. ModuleDiscovery

`ModuleDiscovery`:

1. menerima root module path;
2. membaca direct child directories;
3. mengabaikan files pada module root;
4. mengabaikan directory tanpa `module.yaml`;
5. menghasilkan manifest paths;
6. mengurutkan manifest paths secara deterministic.

Current output:

```php
list<string> // module.yaml paths
```

Bukan:

```text
DiscoveredModule object
```

Historical ADR-004 pernah menetapkan `DiscoveredModule`, tetapi contract tersebut tidak terdapat pada current source.

---

# 4. Manifest Loading

`ModuleManifestLoader` menerima satu manifest path dan hanya bertanggung jawab membaca raw contents.

```text
manifest path
     ↓
ModuleManifestLoader
     ↓
raw YAML string
```

Missing/unreadable file menghasilkan failure.

Loader tidak melakukan parsing atau validation.

---

# 5. Manifest Parsing

`ModuleManifestParser` menggunakan Symfony YAML.

```text
raw YAML
   ↓
ModuleManifestParser
   ↓
PHP array
```

Invalid YAML atau YAML yang bukan object/array menghasilkan exception.

---

# 6. Manifest Validation & Definition Factory

Current responsibility:

```text
parsed array
   ↓
ModuleDefinitionFactory
   ↓
ModuleManifestValidator
   ↓
ModuleDefinition::fromArray()
```

Validator memeriksa required fields dan current field types.

Current required fields:

```text
schema
name
display_name
version
description
providers
dependencies
metadata
extra
```

Declared provider class harus dapat di-autoload, exist, dan merupakan Laravel `ServiceProvider`.

---

# 7. Dependency Validation & Ordering

Setelah seluruh definitions dibuat:

```text
ModuleDefinition[]
      ↓
DependencyResolver
      ↓
topological order
```

Resolver fail-fast untuk:

```text
missing dependency
circular dependency
```

Dependency identity menggunakan exact `ModuleDefinition.name`.

Resolved order adalah runtime ordering contract:

```text
dependency correctness validation ✅
module loading order              ✅
provider registration order       ✅
```

`ModuleProviderRegistrar` menerima dependency-ordered definitions dan mendaftarkan declared non-Core providers dalam order tersebut.

---

# 8. Events Are Not Part of Discovery

Module discovery tidak melakukan listener scanning atau reflection.

Tidak ada global:

```text
EventDiscoveryService
ModuleEventRegistry
Listeners/ auto-discovery
```

Event listener/integration didaftarkan secara eksplisit oleh provider/component owner.

Karena itu event activation tidak bergantung pada derived module path, folder name, atau filesystem listener scanning.

---

# 9. Registry Loading

`ModuleLoader` menerima dependency-ordered definitions dan mendaftarkannya ke singleton `ModuleRegistry`.

```text
dependency-ordered ModuleDefinition[]
      ↓
ModuleLoader
      ↓
ModuleRegistry
```

Duplicate module name gagal melalui `ModuleAlreadyRegisteredException`.

Setelah registry terisi, application-facing reads menggunakan `ModuleRepository`.

---

# 10. Discovery vs Bootstrap vs Tenant Availability

Current Module Kernel tidak memiliki runtime enabled/disabled state.

```text
DISCOVERY / BOOTSTRAP
module physically present?
manifest valid?
dependencies valid?
metadata registered?
providers registered?

TENANT AVAILABILITY
separate feature / entitlement concern

AUTHORIZATION
separate authorization concern
```

Module yang physically present dan valid berpartisipasi dalam application bootstrap.

Tenant availability tidak mengaktifkan atau mematikan provider registration.

---

# 11. Error Handling

Discovery/bootstrap mengikuti fail-fast untuk configuration errors.

Examples:

```text
malformed YAML
missing manifest field
wrong manifest field type
missing/invalid provider class
missing dependency
circular dependency
duplicate module registration
provider registration failure
```

Jangan melakukan silent fallback yang menghasilkan partial/unknown metadata state.

---

# 12. Extension Rules

Future discovery extension boleh menambahkan capability seperti caching atau external source hanya jika demonstrated requirement tersedia.

Jangan menambah generic plugin marketplace, recursive tree, remote registry, atau mutable runtime activation system secara spekulatif.

Current simple direct-directory discovery adalah canonical KISS baseline.

---

# Related Documents

- [`kernel.md`](kernel.md)
- [`module-lifecycle.md`](module-lifecycle.md)
- [`module-manager.md`](module-manager.md) — historical compatibility note
- ADR-003 — Module Manifest Specification
- ADR-004 — Automatic Module Discovery
- ADR-005 — Module Registry
- ADR-010 — Module Identity
- ADR-017 — Module Runtime & Bootstrap Contract
