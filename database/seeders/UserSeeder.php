<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Seeds provincial staff (super admins) and municipal MSWDO personnel.
 * Reflects the actual organizational structure of Ifugao's social welfare offices.
 */
class UserSeeder extends Seeder
{
    public function run(): void
    {
        $now = Carbon::now();
        $password = Hash::make('password123');

        // Provincial Capitol Staff (municipality_id = NULL = Global Access)
        $provincialUsers = [
            [
                'municipality_id'    => null,
                'name'               => 'Gov. Jerry Dalipog',
                'email'              => 'governor@ifugao.gov.ph',
                'password'           => $password,
                'role'               => 'ADMIN',
                'is_active'          => true,
                'email_verified_at'  => $now,
            ],
            [
                'municipality_id'    => null,
                'name'               => 'Maria Teresa Banaag',
                'email'              => 'pswdo.head@ifugao.gov.ph',
                'password'           => $password,
                'role'               => 'ADMIN',
                'is_active'          => true,
                'email_verified_at'  => $now,
            ],
            [
                'municipality_id'    => null,
                'name'               => 'Ricardo Bugan',
                'email'              => 'pswdo.reviewer@ifugao.gov.ph',
                'password'           => $password,
                'role'               => 'REVIEWER',
                'is_active'          => true,
                'email_verified_at'  => $now,
            ],
        ];

        foreach ($provincialUsers as $user) {
            DB::table('users')->insert(array_merge($user, [
                'uuid'           => Str::uuid()->toString(),
                'remember_token' => null,
                'created_at'     => $now,
                'updated_at'     => $now,
                'deleted_at'     => null,
            ]));
        }

        // Municipal Staff - 3 users per municipality (Admin, Reviewer, Encoder)
        $municipalities = DB::table('municipalities')->get();

        $municipalStaff = [
            'Lagawe'        => ['Elena Dulnuan', 'Pedro Bumayyao', 'Rosa Gumangan'],
            'Lamut'         => ['Antonio Bahatan', 'Felicidad Tindaan', 'Carlos Mumbaki'],
            'Kiangan'       => ['Josefina Palattao', 'Gregorio Buyuccan', 'Nena Haddad'],
            'Banaue'        => ['Lorenzo Bimmayag', 'Adelina Daluyo', 'Vicente Pinkihan'],
            'Hungduan'      => ['Beatriz Bumangil', 'Santiago Linawe', 'Teresita Ngilin'],
            'Hingyon'       => ['Emilio Guinaang', 'Soledad Humiwat', 'Fernando Kidlat'],
            'Mayoyao'       => ['Gloria Lingan', 'Delfin Mundina', 'Aurora Nainag'],
            'Alfonso Lista' => ['Hilario Baguingan', 'Pilar Bangibang', 'Manuel Habana'],
            'Aguinaldo'     => ['Catalina Baguilat', 'Narciso Tumibay', 'Erlinda Balawag'],
            'Asipulo'       => ['Julian Dumangeng', 'Remedios Chadatan', 'Tomas Binwag'],
            'Tinoc'         => ['Virginia Dulawan', 'Oscar Bumidang', 'Leonida Kinomis'],
        ];

        $roles = ['ADMIN', 'REVIEWER', 'ENCODER'];

        foreach ($municipalities as $municipality) {
            $staffNames = $municipalStaff[$municipality->name] ?? null;
            if (!$staffNames) {
                continue;
            }

            foreach ($staffNames as $index => $name) {
                $emailSlug = strtolower(str_replace(' ', '.', $name));
                $munSlug = strtolower(str_replace(' ', '', $municipality->name));

                DB::table('users')->insert([
                    'uuid'               => Str::uuid()->toString(),
                    'municipality_id'    => $municipality->id,
                    'name'               => $name,
                    'email'              => "{$emailSlug}@{$munSlug}.ifugao.gov.ph",
                    'password'           => $password,
                    'role'               => $roles[$index],
                    'is_active'          => true,
                    'email_verified_at'  => $now,
                    'remember_token'     => null,
                    'created_at'         => $now,
                    'updated_at'         => $now,
                    'deleted_at'         => null,
                ]);
            }
        }
    }
}
