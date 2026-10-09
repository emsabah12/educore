<?php

namespace Modules\Core\Application\Context;

use Modules\Core\Domain\Tenancy\WorkContext;

/**
 * Hasil penentuan konteks kerja: berhasil (ada konteks) atau harus diarahkan ke halaman lain.
 */
final readonly class ContextResolution
{
    private function __construct(
        public ?WorkContext $context,
        public ?string $redirectRoute,
    ) {}

    public static function resolved(WorkContext $context): self
    {
        return new self($context, null);
    }

    public static function redirectTo(string $routeName): self
    {
        return new self(null, $routeName);
    }
}
