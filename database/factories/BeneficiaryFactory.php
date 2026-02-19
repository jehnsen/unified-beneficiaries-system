<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BeneficiaryFactory extends Factory
{
    protected $model = Beneficiary::class;

    public function definition(): array
    {
        $lastName = $this->faker->lastName();

        return [
            'uuid'                 => (string) Str::uuid(),
            'home_municipality_id' => Municipality::factory(),
            'first_name'           => $this->faker->firstName(),
            'last_name'            => $lastName,
            // Pre-compute so tests that bypass the boot() hook still get a valid phonetic.
            'last_name_phonetic'   => soundex($lastName),
            'birthdate'            => $this->faker->dateTimeBetween('-70 years', '-18 years')->format('Y-m-d'),
            'gender'               => $this->faker->randomElement(['Male', 'Female']),
            'is_active'            => true,
        ];
    }
}
