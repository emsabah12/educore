<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Bus;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Modules\Core\Shared\Contracts\QueryHandlerInterface;
use Modules\Core\Shared\Contracts\QueryInterface;
use RuntimeException;

final class QueryHandlerResolver
{
    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * Resolve handler berdasarkan query yang diberikan.
     *
     * Convention:
     *
     * FindUserQuery
     *      ↓
     * FindUserQueryHandler
     */
    public function resolve(QueryInterface $query): QueryHandlerInterface
    {
        $queryClass = $query::class;

        $handlerClass = $this->resolveHandlerClass($queryClass);

        if (!class_exists($handlerClass)) {
            throw new RuntimeException(
                sprintf(
                    'Query handler [%s] untuk query [%s] tidak ditemukan.',
                    $handlerClass,
                    $queryClass,
                )
            );
        }

        $handler = $this->container->make($handlerClass);

        if (!$handler instanceof QueryHandlerInterface) {
            throw new InvalidArgumentException(
                sprintf(
                    'Query handler [%s] harus mengimplementasikan [%s].',
                    $handlerClass,
                    QueryHandlerInterface::class,
                )
            );
        }

        return $handler;
    }

    /**
     * Tentukan FQCN handler berdasarkan FQCN query.
     */
    private function resolveHandlerClass(string $queryClass): string
    {
        if (!str_ends_with($queryClass, 'Query')) {
            throw new InvalidArgumentException(
                sprintf(
                    'Query class [%s] harus memiliki suffix "Query".',
                    $queryClass,
                )
            );
        }

        return $queryClass . 'Handler';
    }
}
