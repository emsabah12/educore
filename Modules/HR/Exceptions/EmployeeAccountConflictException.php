<?php

declare(strict_types=1);

namespace Modules\HR\Exceptions;

use RuntimeException;

/**
 * Dilempar ketika Person di balik sebuah Employee/Membership sudah
 * memiliki User (akun login) — `users.person_id` UNIQUE, satu Person
 * hanya boleh punya satu akun.
 */
final class EmployeeAccountConflictException extends RuntimeException {}
