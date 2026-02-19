<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Beneficiary;
use App\Models\Claim;
use App\Models\Municipality;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ClaimFactory extends Factory
{
    protected $model = Claim::class;

    public function definition(): array
    {
        return [
            'uuid'            => (string) Str::uuid(),
            'beneficiary_id'  => Beneficiary::factory(),
            'municipality_id' => Municipality::factory(),
            'assistance_type' => $this->faker->randomElement([
                'Medical', 'Cash', 'Burial', 'Educational', 'Food', 'Disaster Relief',
            ]),
            'amount'     => $this->faker->randomFloat(2, 500, 10_000),
            'purpose'    => $this->faker->sentence(),
            'status'     => 'PENDING',
            'is_flagged' => false,
        ];
    }

    public function pending(): static
    {
        return $this->state(['status' => 'PENDING']);
    }

    public function pendingFraudCheck(): static
    {
        return $this->state(['status' => 'PENDING_FRAUD_CHECK']);
    }

    public function underReview(): static
    {
        return $this->state(['status' => 'UNDER_REVIEW']);
    }

    public function approved(): static
    {
        return $this->state(['status' => 'APPROVED', 'approved_at' => now()]);
    }

    public function flagged(string $reason = 'Duplicate detected'): static
    {
        return $this->state(['is_flagged' => true, 'flag_reason' => $reason]);
    }

    public function forMunicipality(Municipality $municipality): static
    {
        return $this->state(['municipality_id' => $municipality->id]);
    }
}
