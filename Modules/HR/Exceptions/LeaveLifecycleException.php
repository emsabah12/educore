<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika sebuah operasi lifecycle Leave/Permit (Entitlement
 * Policy resolution, Balance, Request, Approval) melanggar aturan
 * bisnis di HR-004.
 */
final class LeaveLifecycleException extends RuntimeException {}
