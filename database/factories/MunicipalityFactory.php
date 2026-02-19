<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class MunicipalityFactory extends Factory
{
    protected $model = Municipality::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'name'             => $this->faker->unique()->city() . ' Municipality',
            'code'             => strtoupper($this->faker->unique()->lexify('??')),
            'status'           => 'ACTIVE',
            'is_active'        => true,
            'allocated_budget' => 1_000_000.00,
            'used_budget'      => 0.00,
        ];
    }
}
