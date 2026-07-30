<?php

declare(strict_types=1);

namespace Modules\Core\Shared\Testing\Commands;

use Illuminate\Support\Facades\DB;
use Modules\Core\Shared\Contracts\CommandHandlerInterface;
use Modules\Core\Shared\Contracts\CommandInterface;
use RuntimeException;

final class TransactionalCommandHandler implements CommandHandlerInterface
{
    public function handle(CommandInterface $command): mixed
    {
        if (! $command instanceof TransactionalCommand) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Expected [%s], received [%s].',
                    TransactionalCommand::class,
                    $command::class,
                )
            );
        }

        DB::table('memberships')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => '019fae1d-42ab-72d5-b5c5-6a7fc85d35ee',
            'tenant_id' => '019fae1d-edc3-7010-bffd-041ee1335112',
            'role' => $command->marker,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        throw new RuntimeException('TRANSACTIONAL_COMMAND_FAILURE');
    }
}
