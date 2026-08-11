<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Core\Identity\Models\User;
use Modules\Core\Person\Models\PersonModel;

/**
 * @extends Factory<User>
 */
final class UserFactory extends Factory
{
    /**
     * @var class-string<User>
     */
    protected $model = User::class;

    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'person_id' => PersonModel::factory(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'status' => 'ACTIVE',
            'is_superadmin' => false,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(
            static fn(array $attributes): array => [
                'email_verified_at' => null,
            ],
        );
    }
}
