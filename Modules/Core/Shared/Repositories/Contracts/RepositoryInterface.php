<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Repositories\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * @template TModel of Model
 */
interface RepositoryInterface
{
    /**
     * @return TModel|null
     */
    public function findById(string $id): ?Model;

    /**
     * @return TModel
     */
    public function findOrFail(string $id): Model;

    /**
     * @return Collection<int, TModel>
     */
    public function findAll(): Collection;

    /**
     * @return TModel|null
     */
    public function first(): ?Model;

    /**
     * @return TModel
     */
    public function firstOrFail(): Model;

    public function exists(): bool;

    public function count(): int;

    public function paginate(int $perPage = 15): LengthAwarePaginator;

    /**
     * @param array<string,mixed> $attributes
     *
     * @return TModel
     */
    public function create(array $attributes): Model;

    /**
     * @param TModel $model
     * @param array<string,mixed> $attributes
     */
    public function update(Model $model, array $attributes): bool;

    /**
     * @param TModel $model
     */
    public function delete(Model $model): bool;

    public function findBy(array $criteria);

    public function findOneBy(array $criteria);

    public function findOneByOrFail(array $criteria);

    public function existsBy(array $criteria): bool;

    public function countBy(array $criteria): int;
}
