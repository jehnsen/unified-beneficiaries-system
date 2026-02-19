<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Municipality;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'uuid'              => (string) Str::uuid(),
            'municipality_id'   => null,              // Provincial staff by default
            'name'              => $this->faker->name(),
            'email'             => $this->faker->unique()->safeEmail(),
            'password'          => Hash::make('password'),
            'role'              => 'ADMIN',
            'is_active'         => true,
            'email_verified_at' => now(),
        ];
    }

    /**
     * Provincial staff: no municipality, global access.
     */
    public function provincial(): static
    {
        return $this->state(['municipality_id' => null]);
    }

    /**
     * Municipal staff: scoped to the given (or a newly created) municipality.
     */
    public function municipal(Municipality|int|null $municipality = null): static
    {
        return $this->state(function () use ($municipality) {
            if ($municipality instanceof Municipality) {
                return ['municipality_id' => $municipality->id];
            }

            if (is_int($municipality)) {
                return ['municipality_id' => $municipality];
            }

            return ['municipality_id' => Municipality::factory()->create()->id];
        });
    }

    /**
     * Reviewer role (lower privilege than ADMIN).
     */
    public function reviewer(): static
    {
        return $this->state(['role' => 'REVIEWER']);
    }
}
