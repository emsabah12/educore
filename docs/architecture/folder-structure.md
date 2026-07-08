# Core Folder Structure

Version : 1.0
Status : Locked
Updated : 2026-07-02
Sprint : CORE-001 Sprint-1

## Overview

Direktori `Modules/Core` merupakan implementasi Platform Kernel pada EduCore. Seluruh komponen inti kernel ditempatkan di dalam direktori ini dan dipisahkan berdasarkan tanggung jawab (responsibility).

Struktur ini dirancang untuk mendukung prinsip:

- Single Responsibility Principle (SRP)
- Separation of Concerns (SoC)
- Clean Architecture
- Modular Monolith

Perubahan struktur direktori hanya dilakukan apabila terdapat kebutuhan arsitektur yang kuat dan harus didokumentasikan melalui Architecture Decision Record (ADR).

---

# Directory Structure

```text
Modules/
└── Core/
    ├── Config/
    ├── Console/
        ├── KernelHealthCheckCommand.php
        ├── ModuleDisableCommand.php
        ├── ModuleEnableCommand.php
        ├── ModuleListCommand.php
        ├── ModuleStatusCommand.php
        └── TestModuleLoaderCommand.php
    ├── Contracts/
        └── ModuleRepository.php
    ├── Database/
        ├── Factories/
        ├── Migrations/
            └── 2026_07_06_000000_create_mock_students_table.php
        └── Seeders/
    ├── Discovery/
        └── ModuleDiscovery.php
    ├── Entities/
        ├── BaseEntity.php
        ├── MockStudent.php
        └── ModuleDefinition.php
    ├── Exceptions/
        ├── InvalidModuleManifestException.php
        ├── ModuleAlreadyRegisteredException.php
        └── ModuleNotFoundException.php
    ├── Http/
        ├── Controllers
        ├── Middleware
        └── Requests
    ├── Manifest/
        ├── ModuleDefinitionFactory.php
        ├── ModuleManifestLoader.php
        ├── ModuleManifestParser.php
        └── ModuleManifestValidator.php
    ├── Models/
    ├── Providers/
        └── CoreServiceProvider.php
    ├── Registry/
        └── ModuleRegistry.php
    ├── Resources/
        ├── lang
        └── Views
    ├── Routes
    ├── Services/
        ├── Health
            └── DatabaseHealthChecker.php
        ├── ModuleBootstrapService.php
        ├── ModuleLoader.php
        ├── ModuleManager.php
        ├── ModuleRepository.php
        └── ModuleStateRepository.php
    ├── Support/
        ├── Uuid
            ├── HasUuidV7.php
            ├── UuidBlueprintMacro.php
            └── UuidV7.php
    ├── Tests/
        ├── Assertions/
            └── ManifestAssertions.php
        ├── Builders/
            ├── ManifestBuilder.php
            ├── ModuleDefinitionBuilder.php
            └── ModuleFixtureBuilder.php
        ├── Feature/
        ├── Filesystem/
            └── TemporaryFilesystem.php
        ├── Fixtures/
            └── ModuleFixture.php
        ├── Integration/
            ├── Database/
                ├── UuidV7IntegrationTest.php
            ├── Health/
                ├── HealthCheckIntegrationTest.php
            └── Services/
                └── ModuleBootstrapServiceTest.php
        ├── Support/
            ├── CreatesManifest.php
            ├── CreatesModule.php
            ├── Filesystem.php
            ├── ManifestFactory.php
            └── StandardModuleStructure.php
        ├── Unit/
            ├── Builders/
                 └── ModuleDefinitionBuilderTest.php
            ├── Console
            ├── Discovery/
                └── ModuleDiscoveryTest.php
            ├── Filesystem/
                └── TemporaryFilesystemTest.php
            ├── Manifest/
                ├── ModuleDefinitionFactoryTest.php
                ├── ModuleManifestParserTest.php
                └── ModuleManifestValidatorTest.php
            ├── Registry/
                └── ModuleRegistryTest.php
            ├── Services/
                ├── ModuleLoaderTest.php
                ├── ModuleManagerTest.php
                └── ModuleRepositoryTest.php
            └── Support
        ├── CreatesModules.php
        └── TestCase.php
    └── module.yaml
```

---

# Directory Responsibilities

## Config/

Menyimpan konfigurasi bawaan yang digunakan oleh Platform Kernel.

Contoh isi:

- konfigurasi discovery
- konfigurasi cache
- konfigurasi internal kernel

Folder ini tidak digunakan untuk menyimpan konfigurasi runtime.

---

## Console/

Berisi seluruh Laravel Artisan Command milik Platform Kernel.

Contoh:

```text
ModuleListCommand
ModuleStatusCommand
ModuleEnableCommand
ModuleDisableCommand
KernelTestLoaderCommand
```

Command hanya bertugas:

- menerima input
- memanggil service
- menampilkan output

Command tidak boleh berisi business rule.

---

## Contracts/

Berisi interface yang digunakan oleh komponen kernel.

Contoh:

```text
ModuleRepositoryInterface

ManifestParserInterface

DiscoveryInterface
```

Seluruh implementasi bergantung pada abstraction, bukan implementation.

---

## Discovery/

Berisi komponen yang bertugas menemukan modul.

Contoh:

```text
ModuleDiscovery
```

Tanggung jawabnya terbatas pada proses pencarian direktori modul.

Folder ini tidak membaca atau memvalidasi manifest.

---

## Entities/

Berisi objek domain yang merepresentasikan konsep inti Platform Kernel.

Contoh:

```text
ModuleDefinition
```

Entity bersifat framework-independent dan immutable sejauh memungkinkan.

`ModuleDefinition` merupakan immutable metadata object yang hanya dapat dibuat melalui `ModuleDefinitionFactory`.

---

## Exceptions/

Berisi exception khusus Platform Kernel.

Contoh:

```text
InvalidManifestException

ModuleNotFoundException

ModuleAlreadyRegisteredException
```

Exception membantu menghasilkan penanganan error yang lebih jelas dan konsisten.

---

## Manifest/

Berisi seluruh komponen yang bertanggung jawab mengelola _Module Manifest_ (`module.yaml`).

Contoh:

```text
ModuleManifestLoader
ModuleManifestParser
ModuleManifestValidator
ModuleDefinitionFactory
```

Tanggung jawab setiap komponen dipisahkan sesuai prinsip Single Responsibility Principle (SRP):

- **ModuleManifestLoader** membaca isi berkas `module.yaml`.
- **ModuleManifestParser** mengubah isi YAML menjadi struktur data.
- **ModuleManifestValidator** memvalidasi struktur dan skema manifest menggunakan pendekatan _Fail Fast_.
- **ModuleDefinitionFactory** membangun immutable `ModuleDefinition` dari data yang telah tervalidasi.

Folder ini tidak menangani proses discovery maupun penyimpanan metadata.

---

## Providers/

Berisi Laravel Service Provider.

Contoh:

```text
CoreServiceProvider
```

Provider menjadi titik masuk bootstrap Platform Kernel.

---

## Registry/

Berisi penyimpanan metadata modul.

Contoh:

```text
ModuleRegistry
```

Registry merupakan Single Source of Truth untuk metadata modul.

Registry tidak menyimpan status runtime.

---

## Services/

Berisi service yang mengimplementasikan business rule Platform Kernel.

Contoh:

```text
ModuleLoader

ModuleManager
```

Service menjadi pusat orkestrasi antar komponen kernel.

---

## Support/

Berisi utilitas umum yang dapat digunakan oleh berbagai komponen.

Contoh:

```text
PathHelper

ArrayHelper

StringHelper
```

Utility tidak boleh mengandung business rule.

---

## Tests/

Berisi unit test dan integration test untuk seluruh komponen Core.

Struktur pengujian sebaiknya mengikuti struktur source code agar mudah ditelusuri.

Contoh:

```text
Tests/
├── Discovery/
├── Manifest/
├── Registry/
├── Services/
└── Console/
```

---

## module.yaml

Merupakan manifest modul Core.

Manifest ini menjelaskan metadata modul, seperti:

- nama modul
- versi
- provider
- dependency
- deskripsi

Manifest dibaca saat proses discovery.

---

# Dependency Rules

Untuk menjaga arsitektur tetap bersih, setiap direktori mengikuti aturan dependensi berikut:

| Directory  | Boleh Bergantung Pada                    | Tidak Boleh Bergantung Pada   |
| ---------- | ---------------------------------------- | ----------------------------- |
| Config     | -                                        | Semua komponen kernel         |
| Console    | Services                                 | Discovery, Manifest, Registry |
| Contracts  | -                                        | Semua implementasi            |
| Discovery  | Contracts, Support                       | Console                       |
| Entities   | -                                        | Laravel Framework             |
| Exceptions | -                                        | Business Rule                 |
| Manifest   | Contracts, Entities, Exceptions          | Console                       |
| Providers  | Services                                 | Console                       |
| Registry   | Entities                                 | Console                       |
| Services   | Contracts, Registry, Manifest, Discovery | Presentation Layer            |
| Support    | -                                        | Business Rule                 |
| Tests      | Semua komponen                           | -                             |

---

# Architectural Guidelines

Saat menambahkan komponen baru, gunakan panduan berikut:

- Parser baru ditempatkan di `Manifest/`.
- Discovery baru ditempatkan di `Discovery/`.
- Business rule baru ditempatkan di `Services/`.
- Metadata baru ditempatkan di `Entities/`.
- Kontrak baru ditempatkan di `Contracts/`.
- Helper umum ditempatkan di `Support/`.
- Exception baru ditempatkan di `Exceptions/`.
- Command baru ditempatkan di `Console/`.

Hindari membuat folder baru apabila tanggung jawabnya masih sesuai dengan direktori yang sudah ada.

---

# Related Documents

- `README.md`
- `kernel.md`
- `discovery-flow.md`
- `module-manager.md`
- `module-lifecycle.md`
- `architecture-principles.md`

---

# Related ADR

## Related ADR

- ADR-001 — Kernel Architecture Overview
- ADR-002 — Modular Monolith Architecture
- ADR-005 — Module Registry as Source of Truth
- ADR-007 — Module Manager as Kernel Facade
- ADR-009 — Separation of Infrastructure and Kernel Domain
