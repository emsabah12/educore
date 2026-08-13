<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'organizational_assignment_roles',
            function (Blueprint $table): void {
                $table->uuid('organizational_assignment_id');
                $table->uuid('role_id');

                $table->primary(
                    [
                        'organizational_assignment_id',
                        'role_id',
                    ],
                    'pk_organizational_assignment_roles',
                );

                /*
                 * Composite PK already supports lookup by assignment.
                 * A dedicated role index supports reverse lookup and FK work.
                 */
                $table->index(
                    'role_id',
                    'idx_organizational_assignment_roles_role',
                );

                /*
                 * Scoped grants are explicit relationships. Referenced
                 * assignments cannot be hard-deleted while a grant exists.
                 */
                $table->foreign(
                    'organizational_assignment_id',
                    'fk_org_assignment_roles_assignment',
                )
                    ->references('id')
                    ->on('organizational_assignments')
                    ->restrictOnDelete();

                /*
                 * Role is a global catalog entity. Deleting a role that still
                 * participates in scoped grants must also be explicit.
                 */
                $table->foreign(
                    'role_id',
                    'fk_org_assignment_roles_role',
                )
                    ->references('id')
                    ->on('roles')
                    ->restrictOnDelete();
            },
        );
    }

    public function down(): void
    {
        Schema::dropIfExists(
            'organizational_assignment_roles',
        );
    }
};
