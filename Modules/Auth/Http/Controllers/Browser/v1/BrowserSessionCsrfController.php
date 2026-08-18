<?php

declare(strict_types=1);

namespace Modules\Auth\Http\Controllers\Browser\v1;

use Illuminate\Http\Response;

final class BrowserSessionCsrfController
{
    /**
     * Initialize the Laravel browser session / anti-forgery cookie pair.
     *
     * The response intentionally contains no authentication material.
     */
    public function __invoke(): Response
    {
        return response()->noContent();
    }
}
