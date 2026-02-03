# Provincial UBIS Backend - Setup Guide

## System Requirements
- PHP 8.2+
- MySQL 8.0+
- Composer 2.x
- Node.js 18+ (for asset compilation if needed)

## Installation Steps

### 1. Install Dependencies
```bash
composer install
```

### 2. Environment Configuration
Copy the example environment file and configure:
```bash
cp .env.example .env
```

Edit `.env` with your database credentials:
```env
APP_NAME="Provincial UBIS"
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ubis_provincial
DB_USERNAME=root
DB_PASSWORD=your_password

FILESYSTEM_DISK=public
```

### 3. Generate Application Key
```bash
php artisan key:generate
```

### 4. Configure Service Provider (Laravel 11)

#### Option A: Auto-Discovery (Recommended)
Laravel 11 auto-discovers service providers. Ensure `composer.json` has:
```json
"extra": {
    "laravel": {
        "dont-discover": []
    }
}
```

#### Option B: Manual Registration
If auto-discovery doesn't work, create `bootstrap/providers.php`:
```php
<?php

return [
    App\Providers\AppServiceProvider::class,
    App\Providers\RepositoryServiceProvider::class,
];
```

### 5. Run Migrations
```bash
php artisan migrate
```

### 6. Create Storage Symlink
```bash
php artisan storage:link
```

### 7. Seed Initial Data (Optional)
Create seeders for municipalities and admin users:
```bash
php artisan db:seed
```

### 8. Install Laravel Sanctum (if not already)
```bash
composer require laravel/sanctum
php artisan vendor:publish --provider="Laravel\Sanctum\SanctumServiceProvider"
php artisan migrate
```

## Post-Installation Configuration

### 1. Configure CORS (for API consumption)
Edit `config/cors.php`:
```php
'paths' => ['api/*'],
'allowed_methods' => ['*'],
'allowed_origins' => [env('FRONTEND_URL', 'http://localhost:3000')],
'allowed_headers' => ['*'],
'supports_credentials' => true,
```

### 2. Configure File Storage
For production, use S3 or similar:
```env
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=your_key
AWS_SECRET_ACCESS_KEY=your_secret
AWS_DEFAULT_REGION=ap-southeast-1
AWS_BUCKET=ubis-documents
```

### 3. Set Up Task Scheduling (for cleanup jobs)
Add to crontab:
```bash
* * * * * cd /path-to-your-project && php artisan schedule:run >> /dev/null 2>&1
```

## API Authentication Setup

### 1. User Registration/Login Flow
Create auth controllers for token generation:
```bash
php artisan make:controller Api/AuthController
```

Example login method:
```php
public function login(Request $request)
{
    $credentials = $request->validate([
        'email' => 'required|email',
        'password' => 'required',
    ]);

    if (!Auth::attempt($credentials)) {
        return response()->json(['message' => 'Invalid credentials'], 401);
    }

    $user = Auth::user();
    $token = $user->createToken('api-token')->plainTextToken;

    return response()->json([
        'user' => $user,
        'token' => $token,
    ]);
}
```

### 2. API Request Headers
All API requests must include:
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

## Database Seeding

### Sample Seeder for Municipalities
Create `database/seeders/MunicipalitySeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\Municipality;
use Illuminate\Database\Seeder;

class MunicipalitySeeder extends Seeder
{
    public function run(): void
    {
        $municipalities = [
            ['name' => 'Provincial Capitol', 'code' => 'PROV-000', 'status' => 'ACTIVE'],
            ['name' => 'Municipality A', 'code' => 'MUN-001', 'status' => 'ACTIVE'],
            ['name' => 'Municipality B', 'code' => 'MUN-002', 'status' => 'ACTIVE'],
            // Add more...
        ];

        foreach ($municipalities as $mun) {
            Municipality::create($mun);
        }
    }
}
```

### Sample Seeder for Admin User
Create `database/seeders/UserSeeder.php`:
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Provincial Admin (Global Access)
        User::create([
            'municipality_id' => null,
            'name' => 'Provincial Admin',
            'email' => 'admin@province.gov.ph',
            'password' => Hash::make('password'),
            'role' => 'ADMIN',
            'is_active' => true,
        ]);

        // Municipal Admin (Tenant-Scoped)
        User::create([
            'municipality_id' => 2, // Municipality A
            'name' => 'Municipal Admin A',
            'email' => 'admin@muna.gov.ph',
            'password' => Hash::make('password'),
            'role' => 'ADMIN',
            'is_active' => true,
        ]);
    }
}
```

## Testing the API

### 1. Health Check
```bash
curl http://localhost:8000/api/health
```

### 2. Login and Get Token
```bash
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@province.gov.ph","password":"password"}'
```

### 3. Create a Claim
```bash
curl -X POST http://localhost:8000/api/intake/claims \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "home_municipality_id": 2,
    "first_name": "Juan",
    "last_name": "Dela Cruz",
    "birthdate": "1990-01-01",
    "gender": "Male",
    "assistance_type": "Medical",
    "amount": 5000.00,
    "purpose": "Hospital bill"
  }'
```

## Production Deployment

### 1. Optimize for Production
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
composer install --optimize-autoloader --no-dev
```

### 2. Set Production Environment
```env
APP_ENV=production
APP_DEBUG=false
```

### 3. Enable HTTPS
Update `.env`:
```env
APP_URL=https://ubis.province.gov.ph
```

### 4. Database Backups
Set up automated backups using `laravel-backup` package:
```bash
composer require spatie/laravel-backup
php artisan backup:run
```

### 5. Queue Configuration
For background jobs (if needed):
```env
QUEUE_CONNECTION=redis
```

## Monitoring & Logs

### 1. Log Files
Logs are stored in `storage/logs/laravel.log`

### 2. Enable Query Logging (Development Only)
Add to `AppServiceProvider`:
```php
if (config('app.debug')) {
    DB::listen(function ($query) {
        Log::debug('SQL', [
            'query' => $query->sql,
            'bindings' => $query->bindings,
            'time' => $query->time,
        ]);
    });
}
```

## Security Checklist

- [x] **Strict Type Declarations** on all PHP files
- [x] **Form Request Validation** for all inputs
- [x] **Repository Pattern** to separate concerns
- [x] **API Resources** to mask sensitive data
- [x] **Tenant Scoping** to enforce data isolation
- [x] **Transaction Wrapping** for critical operations
- [x] **Rate Limiting** (configure in `RouteServiceProvider`)
- [ ] **HTTPS Enforcement** in production
- [ ] **API Rate Limiting** per user/municipality
- [ ] **Audit Logging** for sensitive actions

## Troubleshooting

### Issue: Service Provider Not Loading
**Solution:** Run `composer dump-autoload` and clear cache:
```bash
composer dump-autoload
php artisan clear-compiled
php artisan config:clear
php artisan cache:clear
```

### Issue: SOUNDEX Not Working
**Solution:** Ensure MySQL `soundex()` function is available:
```sql
SELECT SOUNDEX('Cruz'); -- Should return 'C620'
```

### Issue: Storage Symlink Error
**Solution:** Manually create symlink:
```bash
ln -s ../storage/app/public public/storage
```

## Support

For issues or questions, contact the development team or refer to:
- Laravel Documentation: https://laravel.com/docs/11.x
- Project Repository: [Your Git URL]
