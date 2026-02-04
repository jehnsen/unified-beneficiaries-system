<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

/**
 * Seeds the 11 municipalities of Ifugao Province with realistic fiscal data.
 * Budget allocations reflect population size and geographic accessibility.
 */
class MunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();

        $municipalities = [
            [
                'name'             => 'Lagawe',
                'code'             => 'IFU-LAG',
                'address'          => 'Provincial Capitol Compound, Poblacion, Lagawe, Ifugao',
                'contact_phone'    => '(074) 382-2045',
                'contact_email'    => 'mswdo.lagawe@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 12500000.00,
                'used_budget'      => 8234561.50,
            ],
            [
                'name'             => 'Lamut',
                'code'             => 'IFU-LAM',
                'address'          => 'Municipal Hall, Poblacion, Lamut, Ifugao',
                'contact_phone'    => '(074) 382-2108',
                'contact_email'    => 'mswdo.lamut@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 10800000.00,
                'used_budget'      => 7456320.75,
            ],
            [
                'name'             => 'Kiangan',
                'code'             => 'IFU-KIA',
                'address'          => 'Municipal Hall, Poblacion, Kiangan, Ifugao',
                'contact_phone'    => '(074) 382-2071',
                'contact_email'    => 'mswdo.kiangan@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 9200000.00,
                'used_budget'      => 6012450.25,
            ],
            [
                'name'             => 'Banaue',
                'code'             => 'IFU-BAN',
                'address'          => 'Municipal Hall, Poblacion, Banaue, Ifugao',
                'contact_phone'    => '(074) 386-4025',
                'contact_email'    => 'mswdo.banaue@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 11000000.00,
                'used_budget'      => 7891234.00,
            ],
            [
                'name'             => 'Hungduan',
                'code'             => 'IFU-HUN',
                'address'          => 'Municipal Hall, Poblacion, Hungduan, Ifugao',
                'contact_phone'    => '(074) 382-2156',
                'contact_email'    => 'mswdo.hungduan@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 7500000.00,
                'used_budget'      => 4823100.50,
            ],
            [
                'name'             => 'Hingyon',
                'code'             => 'IFU-HIN',
                'address'          => 'Municipal Hall, Poblacion, Hingyon, Ifugao',
                'contact_phone'    => '(074) 382-2189',
                'contact_email'    => 'mswdo.hingyon@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 6800000.00,
                'used_budget'      => 4102340.00,
            ],
            [
                'name'             => 'Mayoyao',
                'code'             => 'IFU-MAY',
                'address'          => 'Municipal Hall, Poblacion, Mayoyao, Ifugao',
                'contact_phone'    => '(074) 382-2201',
                'contact_email'    => 'mswdo.mayoyao@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 8900000.00,
                'used_budget'      => 5678900.25,
            ],
            [
                'name'             => 'Alfonso Lista',
                'code'             => 'IFU-ALF',
                'address'          => 'Municipal Hall, Poblacion, Alfonso Lista, Ifugao',
                'contact_phone'    => '(074) 382-2234',
                'contact_email'    => 'mswdo.alfonsolista@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 10200000.00,
                'used_budget'      => 7234567.50,
            ],
            [
                'name'             => 'Aguinaldo',
                'code'             => 'IFU-AGU',
                'address'          => 'Municipal Hall, Poblacion, Aguinaldo, Ifugao',
                'contact_phone'    => '(074) 382-2267',
                'contact_email'    => 'mswdo.aguinaldo@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 6200000.00,
                'used_budget'      => 3456789.00,
            ],
            [
                'name'             => 'Asipulo',
                'code'             => 'IFU-ASI',
                'address'          => 'Municipal Hall, Poblacion, Asipulo, Ifugao',
                'contact_phone'    => '(074) 382-2290',
                'contact_email'    => 'mswdo.asipulo@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 5800000.00,
                'used_budget'      => 3210456.75,
            ],
            [
                'name'             => 'Tinoc',
                'code'             => 'IFU-TIN',
                'address'          => 'Municipal Hall, Poblacion, Tinoc, Ifugao',
                'contact_phone'    => '(074) 382-2312',
                'contact_email'    => 'mswdo.tinoc@ifugao.gov.ph',
                'status'           => 'ACTIVE',
                'is_active'        => true,
                'allocated_budget' => 7100000.00,
                'used_budget'      => 4567890.25,
            ],
        ];

        foreach ($municipalities as $municipality) {
            DB::table('municipalities')->insert(array_merge($municipality, [
                'logo_path'  => null,
                'created_at' => $now,
                'updated_at' => $now,
                'deleted_at' => null,
            ]));
        }
    }
}
