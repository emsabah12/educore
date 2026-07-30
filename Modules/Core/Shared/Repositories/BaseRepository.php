<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Modules\Core\Tenancy\Contracts\TenantContextInterface;
use Modules\Core\Tenancy\Exceptions\TenantContextNotResolvedException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Shared\Repositories\Contracts\RepositoryInterface;

/**
 * @template TModel of Model
 *
 * @implements RepositoryInterface<TModel>
 */
abstract class BaseRepository implements RepositoryInterface
{
    /**
     * @var TModel
     */
    protected Model $model;

    /**
     * @param TModel $model
     */
    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    protected function ensureTenantResolved(): void
    {
        $tenantContext = app(TenantContextInterface::class);

        if ($tenantContext->getCurrentTenantId() === null) {
            throw new TenantContextNotResolvedException();
        }
    }

    protected function newQuery(): Builder
    {
        $this->ensureTenantResolved();

        return $this->model->newQuery();
    }

    /**
     * @return TModel|null
     */
    public function findById(string $id): ?Model
    {
        return $this->newQuery()
            ->find($id);
    }

    /**
     * @return TModel
     */
    public function findOrFail(string $id): Model
    {
        /** @var TModel */
        return $this->newQuery()
            ->findOrFail($id);
    }

    /**
     * @return Collection<int, TModel>
     */
    public function findAll(): Collection
    {
        return $this->newQuery()
            ->get();
    }

    /**
     * @return TModel|null
     */
    public function first(): ?Model
    {
        return $this->newQuery()
            ->first();
    }

    /**
     * @return TModel
     */
    public function firstOrFail(): Model
    {
        /** @var TModel */
        return $this->newQuery()
            ->firstOrFail();
    }

    public function exists(): bool
    {
        return $this->newQuery()
            ->exists();
    }

    public function count(): int
    {
        return $this->newQuery()
            ->count();
    }

    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->newQuery()
            ->paginate($perPage);
    }

    /**
     * @param array<string,mixed> $attributes
     *
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        /** @var TModel */
        return $this->model
            ->newQuery()
            ->create($attributes);
    }

    /**
     * @param TModel $model
     * @param array<string,mixed> $attributes
     */
    public function update(Model $model, array $attributes): bool
    {
        return $model->update($attributes);
    }

    /**
     * @param TModel $model
     */
    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * Terapkan filter sederhana berdasarkan pasangan field => value.
     */
    protected function applyFilters(
        Builder $query,
        array $criteria
    ): Builder {
        foreach ($criteria as $column => $value) {
            $query->where($column, $value);
        }

        return $query;
    }

    public function findBy(array $criteria)
    {
        $this->ensureTenantResolved();

        return $this
            ->applyFilters(
                $this->newQuery(),
                $criteria,
            )
            ->get();
    }

    public function findOneBy(array $criteria)
    {
        $this->ensureTenantResolved();

        return $this
            ->applyFilters(
                $this->newQuery(),
                $criteria,
            )
            ->first();
    }

    public function findOneByOrFail(array $criteria)
    {
        $this->ensureTenantResolved();

        return $this
            ->applyFilters(
                $this->newQuery(),
                $criteria,
            )
            ->firstOrFail();
    }

    public function existsBy(array $criteria): bool
    {
        $this->ensureTenantResolved();

        return $this
            ->applyFilters(
                $this->newQuery(),
                $criteria,
            )
            ->exists();
    }

    public function countBy(array $criteria): int
    {
        $this->ensureTenantResolved();

        return $this
            ->applyFilters(
                $this->newQuery(),
                $criteria,
            )
            ->count();
    }
}
