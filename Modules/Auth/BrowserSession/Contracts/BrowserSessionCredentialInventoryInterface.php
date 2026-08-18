<?php

declare(strict_types=1);

namespace Modules\Auth\BrowserSession\Contracts;

/**
 * Internal revocation-only view of BrowserSession-held bearer credentials.
 *
 * This capability is intentionally separated from the browser-facing vault
 * contract so ordinary login/context/switch collaborators cannot enumerate
 * every credential stored in the Browser Session Broker.
 */
interface BrowserSessionCredentialInventoryInterface
{
    /**
     * Return a snapshot of membership-scoped bearer credentials that must be
     * considered during whole-browser-session logout.
     *
     * @return array<string, string>
     */
    public function credentialsForRevocation(): array;
}
