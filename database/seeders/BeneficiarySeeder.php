<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Generates 500+ realistic beneficiary profiles per Ifugao municipality.
 *
 * Name pools are drawn from common Ifugao/Cordilleran family names and
 * Filipino given names. Barangays are real sitios and barrios from each
 * municipality. Age distribution skews toward senior citizens (the primary
 * social welfare demographic), with younger beneficiaries for educational
 * and medical assistance.
 */
class BeneficiarySeeder extends Seeder
{
    /** Minimum beneficiaries per municipality */
    private const PER_MUNICIPALITY = 520;

    /**
     * Common Ifugao / Cordilleran family names.
     */
    private const LAST_NAMES = [
        'Baguilat', 'Bahatan', 'Baguingan', 'Balawag', 'Ballug', 'Bangibang',
        'Bautista', 'Bimmayag', 'Binwag', 'Bugan', 'Bumabag', 'Bumangil',
        'Bumayyao', 'Bumidang', 'Buyuccan', 'Caguiat', 'Calingayan', 'Cayat',
        'Chadatan', 'Chumaming', 'Daligdig', 'Dalit', 'Daluyo', 'Dictaan',
        'Dulatre', 'Dulawan', 'Dulnuan', 'Dumangeng', 'Ganhon', 'Gano',
        'Guinaang', 'Gumangan', 'Habana', 'Habbiling', 'Haddad', 'Himmayog',
        'Humiwat', 'Indunan', 'Kidlat', 'Killip', 'Kinomis', 'Lammawin',
        'Linawe', 'Lingan', 'Lunag', 'Maddela', 'Makawili', 'Mumbaki',
        'Mundina', 'Nainag', 'Nalyatan', 'Ngilin', 'Palaypay', 'Palattao',
        'Panduyos', 'Pinkihan', 'Pummangeg', 'Tindaan', 'Tubang', 'Tumibay',
        'Timmangao', 'Pudiquet', 'Bilong', 'Gumadlas', 'Chuguid', 'Inlumog',
        'Guiambangan', 'Bannawag', 'Chulibang', 'Pinugdol', 'Hungduan',
        'Gumaling', 'Buhong', 'Taguinod', 'Inambucan', 'Hannopol', 'Dingalan',
        'Pumbaheg', 'Gammod', 'Guyudan', 'Binomhon', 'Timmayog', 'Dotag',
        'Baccong', 'Dugong', 'Inhumang', 'Pahimong', 'Licyayo', 'Chaddangan',
        'Udlong', 'Dulnuwan',
    ];

    /**
     * Common Filipino male first names.
     */
    private const MALE_FIRST_NAMES = [
        'Agustin', 'Antonio', 'Benigno', 'Benito', 'Calixto', 'Carlos',
        'Crisanto', 'David', 'Delfin', 'Eduardo', 'Emilio', 'Ernesto',
        'Fernando', 'Francisco', 'Gabriel', 'Gregorio', 'Hector', 'Hilario',
        'Ignacio', 'Isidro', 'Jose', 'Juan', 'Julian', 'Leonardo',
        'Lorenzo', 'Manuel', 'Mariano', 'Narciso', 'Nicolas', 'Oscar',
        'Pablo', 'Pedro', 'Rafael', 'Ricardo', 'Roberto', 'Rodrigo',
        'Salvador', 'Santiago', 'Teodoro', 'Tomas', 'Urbano', 'Vicente',
        'Virgilio', 'Waldo', 'Wilfredo', 'Alfredo', 'Arturo', 'Bernardo',
        'Domingo', 'Efren', 'Felipe', 'Gerardo', 'Ireneo', 'Jaime',
        'Leandro', 'Mario', 'Noel', 'Prudencio', 'Ramon', 'Samuel',
        'Timoteo', 'Venancio', 'Wilson', 'Alexander', 'Bryan', 'Christian',
        'Dennis', 'Elmer', 'Frederick', 'Gerald', 'Harold', 'Ivan',
        'Jonathan', 'Kenneth', 'Mark', 'Patrick', 'Randy', 'Steven',
        'Michael', 'James', 'Robert', 'John', 'Daniel', 'William',
    ];

    /**
     * Common Filipino female first names.
     */
    private const FEMALE_FIRST_NAMES = [
        'Adelina', 'Aurora', 'Beatriz', 'Bienvenida', 'Catalina', 'Consolacion',
        'Dolores', 'Dominga', 'Epifania', 'Erlinda', 'Felicidad', 'Filomena',
        'Gloria', 'Guadalupe', 'Herminia', 'Hilaria', 'Imelda', 'Ines',
        'Josefina', 'Juliana', 'Leonida', 'Lorenza', 'Magdalena', 'Maria',
        'Natividad', 'Nena', 'Ofelia', 'Olympia', 'Paz', 'Pilar',
        'Remedios', 'Rosa', 'Soledad', 'Susana', 'Teresita', 'Trinidad',
        'Ursula', 'Valentina', 'Virginia', 'Zenaida', 'Anita', 'Bella',
        'Corazon', 'Divina', 'Esperanza', 'Francisca', 'Gregoria', 'Helen',
        'Irene', 'Juana', 'Lourdes', 'Milagros', 'Norma', 'Patricia',
        'Rosario', 'Teresa', 'Victoria', 'Wilma', 'Yolanda', 'Andrea',
        'Carmen', 'Esther', 'Flora', 'Glenda', 'Lilia', 'Lucena',
        'Myrna', 'Olive', 'Priscilla', 'Rebecca', 'Shirley', 'Thelma',
        'Michelle', 'Jennifer', 'Karen', 'Christine', 'Jessica', 'Angela',
        'Grace', 'Joy', 'Mary Ann', 'Rosemarie', 'Aileen', 'Donna',
    ];

    /**
     * Middle names - typically mother's maiden name in Filipino culture.
     */
    private const MIDDLE_NAMES = [
        'Bugan', 'Dulnuan', 'Palattao', 'Gumangan', 'Bahatan', 'Tindaan',
        'Mumbaki', 'Buyuccan', 'Haddad', 'Daluyo', 'Pinkihan', 'Ngilin',
        'Guinaang', 'Humiwat', 'Lingan', 'Mundina', 'Bangibang', 'Habana',
        'Tumibay', 'Chadatan', 'Dulawan', 'Kinomis', 'Bimmayag', 'Lammawin',
        'Santos', 'Reyes', 'Cruz', 'Garcia', 'Torres', 'Ramos',
        'Gonzales', 'Lopez', 'Martinez', 'Hernandez', 'Aquino', 'Villamor',
        'De Leon', 'Pascual', 'Mendoza', 'Soriano',
    ];

    /**
     * Barangays per municipality (real sitios/barrios of Ifugao).
     */
    private const BARANGAYS = [
        'Lagawe' => [
            'Poblacion North', 'Poblacion South', 'Poblacion East', 'Poblacion West',
            'Bomod-ok', 'Burnay', 'Banga', 'Ducligan', 'Panopdopan', 'Tungngod',
            'Olilicon', 'Abatan', 'Bolog', 'Mabbalat', 'Tupaya', 'Pucol',
            'Jucbong', 'Bayninan', 'Cudog', 'Uhaj',
        ],
        'Lamut' => [
            'Poblacion', 'Mabbalat', 'Nayon', 'Panopdopan', 'Poitan', 'Sapaan',
            'Bimpal', 'Lawig', 'Payawan', 'Sanafe', 'Hapid', 'Holowon',
            'Banaue View', 'Magulon', 'Piwong', 'Umilag',
        ],
        'Kiangan' => [
            'Poblacion', 'Nagacadan', 'Julongan', 'Tuplac', 'Duit', 'Baguinge',
            'Ambabag', 'Mungayang', 'Pindongan', 'Hucab', 'Dalligan', 'Hingyon',
            'Baguinge South', 'Dalligan East', 'Tuplac West', 'Bolog',
        ],
        'Banaue' => [
            'Poblacion', 'Batad', 'Bangaan', 'Tam-an', 'Gohang', 'Viewpoint',
            'Bocos', 'Amganad', 'Uhaj', 'Poitan', 'Ducligan', 'Balawis',
            'Cambulo', 'Pula', 'San Fernando', 'Anaba', 'Banao', 'Mayoyao View',
        ],
        'Hungduan' => [
            'Poblacion', 'Hapao', 'Bangbang', 'Abatan', 'Baang', 'Bokiawan',
            'Nungawa', 'Ba-ang', 'Maggok', 'Lubo-ong', 'Bitu', 'Hingyon View',
        ],
        'Hingyon' => [
            'Poblacion', 'Umalbong', 'Anao', 'Cababuyan', 'Mompolia', 'Piwong',
            'Umilag', 'Bitu', 'Hepang', 'Namulditan', 'O-ong', 'Tulludan',
        ],
        'Mayoyao' => [
            'Poblacion', 'Balangbang', 'Banao', 'Chaya', 'Epeng', 'Hagabon',
            'Liwo', 'Mongol', 'Nattum', 'Tucal', 'Bato', 'Mahmongan',
            'Palaad', 'Nalbu', 'Bontoc View', 'Tuyangan',
        ],
        'Alfonso Lista' => [
            'Poblacion', 'Amhang', 'Bangar', 'Cabogawan', 'Liwon', 'Namillangan',
            'Namnama', 'Pita', 'San Juan', 'Tuplac', 'Buliwao', 'Cawayan',
            'Dolowog', 'Little Tadian', 'Potia', 'San Jose', 'San Rafael',
        ],
        'Aguinaldo' => [
            'Poblacion', 'Awayan', 'Bunhian', 'Chalalo', 'Damag', 'Ekip',
            'Galachgac', 'Mayaoyao', 'Piwong', 'Tulludan', 'Banaue View',
        ],
        'Asipulo' => [
            'Poblacion', 'Amduntog', 'Cawayan', 'Camandag', 'Namal', 'Piwong',
            'Umilag', 'Panubtuban', 'Liwon', 'Hallap', 'Balag',
        ],
        'Tinoc' => [
            'Poblacion', 'Ahin', 'Ap-apid', 'Binablayan', 'Danggo', 'Eheb',
            'Gumhang', 'Impugong', 'Luhong', 'Tukucan', 'Tulludan', 'Wangwang',
            'Tinoc Central', 'Eheb South',
        ],
    ];

    /**
     * ID types commonly used in Philippine social welfare processing.
     */
    private const ID_TYPES = [
        'PhilSys National ID',
        'UMID',
        'SSS ID',
        'PhilHealth ID',
        'Voter\'s ID',
        'Barangay ID',
        'Senior Citizen ID',
        'PWD ID',
        'Postal ID',
        'Driver\'s License',
    ];

    private const SUFFIXES = [null, null, null, null, null, null, null, null, 'Jr.', 'Sr.', 'III', 'IV'];

    public function run(): void
    {
        $municipalities = DB::table('municipalities')->get();
        $adminUser = DB::table('users')->whereNull('municipality_id')->first();
        $createdById = $adminUser?->id;

        $allBeneficiaries = [];

        foreach ($municipalities as $municipality) {
            $this->command->info("Generating beneficiaries for {$municipality->name}...");

            $barangays = self::BARANGAYS[$municipality->name] ?? ['Poblacion'];
            $used = []; // Track unique combos to avoid duplicate constraint violations
            $count = 0;

            while ($count < self::PER_MUNICIPALITY) {
                $gender = $this->weightedGender();
                $firstName = $gender === 'Male'
                    ? self::MALE_FIRST_NAMES[array_rand(self::MALE_FIRST_NAMES)]
                    : self::FEMALE_FIRST_NAMES[array_rand(self::FEMALE_FIRST_NAMES)];
                $lastName = self::LAST_NAMES[array_rand(self::LAST_NAMES)];
                $birthdate = $this->generateBirthdate();

                // Ensure uniqueness per municipality
                $key = "{$firstName}|{$lastName}|{$birthdate}|{$municipality->id}";
                if (isset($used[$key])) {
                    continue;
                }
                $used[$key] = true;

                $barangay = $barangays[array_rand($barangays)];
                $middleName = rand(0, 100) < 85
                    ? self::MIDDLE_NAMES[array_rand(self::MIDDLE_NAMES)]
                    : null;
                $suffix = self::SUFFIXES[array_rand(self::SUFFIXES)];

                // Senior citizens are more likely to have Senior Citizen ID
                $age = (int) Carbon::parse($birthdate)->age;
                $idType = $this->pickIdType($age);

                $allBeneficiaries[] = [
                    'uuid'                 => Str::uuid()->toString(),
                    'home_municipality_id' => $municipality->id,
                    'first_name'           => $firstName,
                    'last_name'            => $lastName,
                    'last_name_phonetic'   => soundex($lastName),
                    'middle_name'          => $middleName,
                    'suffix'               => $suffix,
                    'birthdate'            => $birthdate,
                    'gender'               => $gender,
                    'contact_number'       => $this->generatePhone(),
                    'address'              => "Brgy. {$barangay}, {$municipality->name}, Ifugao",
                    'barangay'             => $barangay,
                    'id_type'              => $idType,
                    'id_number'            => $this->generateIdNumber($idType),
                    'fingerprint_hash'     => null,
                    'is_active'            => rand(0, 100) < 97, // 3% inactive (deceased, relocated)
                    'created_by'           => $createdById,
                    'updated_by'           => null,
                    'created_at'           => $this->randomPastDate(),
                    'updated_at'           => Carbon::now(),
                    'deleted_at'           => null,
                ];

                $count++;

                // Bulk insert every 500 records for performance
                if (count($allBeneficiaries) >= 500) {
                    DB::table('beneficiaries')->insert($allBeneficiaries);
                    $allBeneficiaries = [];
                }
            }
        }

        // Insert remaining records
        if (!empty($allBeneficiaries)) {
            DB::table('beneficiaries')->insert($allBeneficiaries);
        }

        $total = DB::table('beneficiaries')->count();
        $this->command->info("Total beneficiaries created: {$total}");
    }

    /**
     * Birthdate distribution weighted toward elderly (social welfare demographic).
     * 40% seniors (60+), 30% middle-aged (40-59), 20% young adults (18-39), 10% minors.
     */
    private function generateBirthdate(): string
    {
        $roll = rand(1, 100);

        if ($roll <= 40) {
            // Senior citizens: 60-95 years old
            $yearsAgo = rand(60, 95);
        } elseif ($roll <= 70) {
            // Middle-aged: 40-59
            $yearsAgo = rand(40, 59);
        } elseif ($roll <= 90) {
            // Young adults: 18-39
            $yearsAgo = rand(18, 39);
        } else {
            // Minors: 5-17
            $yearsAgo = rand(5, 17);
        }

        return Carbon::now()
            ->subYears($yearsAgo)
            ->subDays(rand(0, 364))
            ->format('Y-m-d');
    }

    /**
     * Slight female skew reflects real PH social welfare demographics.
     */
    private function weightedGender(): string
    {
        return rand(1, 100) <= 55 ? 'Female' : 'Male';
    }

    /**
     * Pick an ID type appropriate for the beneficiary's age.
     */
    private function pickIdType(int $age): string
    {
        if ($age >= 60) {
            $types = ['Senior Citizen ID', 'Senior Citizen ID', 'PhilSys National ID', 'Voter\'s ID', 'Barangay ID', 'PhilHealth ID'];
        } elseif ($age >= 18) {
            $types = ['PhilSys National ID', 'Voter\'s ID', 'UMID', 'PhilHealth ID', 'SSS ID', 'Barangay ID', 'Driver\'s License', 'Postal ID'];
        } else {
            $types = ['Barangay ID', 'PhilSys National ID'];
        }

        return $types[array_rand($types)];
    }

    /**
     * Generate a realistic Philippine mobile number (Globe/Smart prefixes).
     */
    private function generatePhone(): string
    {
        $prefixes = ['0917', '0918', '0919', '0920', '0921', '0927', '0928', '0929', '0930', '0935', '0936', '0945', '0946', '0950', '0955', '0956', '0977', '0978'];
        $prefix = $prefixes[array_rand($prefixes)];
        return $prefix . str_pad((string) rand(0, 9999999), 7, '0', STR_PAD_LEFT);
    }

    /**
     * Generate an ID number format that matches the ID type.
     */
    private function generateIdNumber(string $idType): string
    {
        return match ($idType) {
            'PhilSys National ID'   => 'PSN-' . rand(1000, 9999) . '-' . rand(1000, 9999) . '-' . rand(1000, 9999),
            'UMID'                  => 'CRN-' . rand(100, 999) . '-' . rand(1000000, 9999999),
            'SSS ID'               => str_pad((string) rand(0, 99), 2, '0', STR_PAD_LEFT) . '-' . rand(1000000, 9999999) . '-' . rand(0, 9),
            'PhilHealth ID'        => rand(10, 99) . '-' . rand(100000000, 999999999) . '-' . rand(0, 9),
            'Voter\'s ID'          => 'VIN-' . rand(1000, 9999) . str_pad((string) rand(0, 9999), 4, '0', STR_PAD_LEFT) . 'IFU',
            'Barangay ID'          => 'BID-IFU-' . str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
            'Senior Citizen ID'    => 'SC-IFU-' . str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
            'PWD ID'               => 'PWD-IFU-' . str_pad((string) rand(1, 99999), 5, '0', STR_PAD_LEFT),
            'Postal ID'            => 'POS-' . rand(10000000, 99999999),
            'Driver\'s License'    => 'N' . rand(10, 99) . '-' . rand(10, 99) . '-' . str_pad((string) rand(0, 999999), 6, '0', STR_PAD_LEFT),
            default                => 'ID-' . rand(100000, 999999),
        };
    }

    /**
     * Random creation date within the past 2 years (reflects gradual enrollment).
     */
    private function randomPastDate(): string
    {
        return Carbon::now()
            ->subDays(rand(1, 730))
            ->format('Y-m-d H:i:s');
    }
}
