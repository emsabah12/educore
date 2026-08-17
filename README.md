# EduCore

EduCore is a Laravel-based modular monolith that provides a shared foundation for education-domain applications and modules.

The project is designed around explicit module boundaries, canonical identity and tenancy contracts, database-backed authorization, and documented API contracts.

## Architecture

EduCore uses a modular monolith architecture.

Application capabilities are separated into modules while shared technical concerns remain centralized in the application foundation and Core layer.

Current foundation contracts include:

- canonical human identity separated from authentication identity;
- tenant membership as the boundary between a person and a tenant;
- database-backed role and permission authorization;
- authenticated request context derived from validated current persistence state;
- organizational topology and scoped authorization;
- explicit module runtime bootstrap and dependency contracts;
- OpenAPI-backed HTTP API documentation and discoverability.

Role and permission information is not treated as authoritative when supplied by bearer-token claims. Authorization decisions are resolved against the current application state through the canonical authorization boundary.

Legacy identity and authorization models must not be reintroduced as current architecture.

## Documentation

Start with the documentation index:

- [Documentation](docs/README.md)
- [Architecture](docs/architecture/README.md)
- [Current Architecture](docs/architecture/current-architecture.md)
- [Architecture Decision Records](docs/architecture/adr/README.md)
- [Product Requirements](docs/prd/README.md)
- [Sprint Documentation](docs/sprint/README.md)

Architecture Decision Records contain the rationale and historical context behind important architectural decisions. Current-state documentation should be used when determining how EduCore behaves today.

## Development

Install PHP dependencies:

```bash
composer install
```

Create the local environment file when one does not already exist:

```bash
cp .env.example .env
php artisan key:generate
```

Configure the required database and environment settings in .env, then run the application migrations:

```bash
php artisan migrate
```

Run the test suite with:

```bash
php artisan test
```

## Documentation Contract

Documentation changes should preserve the canonical architecture and current HTTP contracts.

When implementation and historical documentation differ, update current-state documentation explicitly rather than silently treating superseded architecture as current behavior.

Historical or superseded terminology may remain in Architecture Decision Records when it is clearly identified as historical, rejected, amended, or superseded context.
