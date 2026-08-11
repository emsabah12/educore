<?php

declare(strict_types=1);

namespace Modules\Core\Person\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Core\Person\Models\PersonModel;

/**
 * @extends Factory<PersonModel>
 */
final class PersonFactory extends Factory
{
    /**
     * @var class-string<PersonModel>
     */
    protected $model = PersonModel::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $givenName = fake()->firstName();
        $familyName = fake()->lastName();

        return [
            'name' => trim($givenName . ' ' . $familyName),
            'given_name' => $givenName,
            'middle_name' => null,
            'family_name' => $familyName,
            'birth_date' => null,
            'birth_place_name' => null,
            'birth_country_code' => null,
            'legal_sex' => null,
            'civil_status' => null,
            'status' => 'ACTIVE',
        ];
    }
}
