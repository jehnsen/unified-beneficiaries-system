# UBIS Provincial Grid - Composer Guide

## 📦 Composer Configuration Files

You have two `composer.json` options:

### 1. **composer.json** (Basic - Recommended to Start)
Minimal Laravel 11.x setup with core dependencies only:
- ✅ Laravel Framework 11.x
- ✅ Laravel Sanctum (API authentication)
- ✅ Laravel Tinker (REPL)
- ✅ Guzzle HTTP (API client)
- ✅ Development tools (PHPUnit, Pint, Sail)

**Use this if:** You want to start lean and add packages as needed.

### 2. **composer.enhanced.json** (Full-Featured)
Production-ready setup with additional packages for government systems:
- ✅ All basic packages PLUS:
- ✅ **Intervention Image** - Image processing for disbursement proofs
- ✅ **Laravel Excel** - Generate reports and exports
- ✅ **Spatie Activity Log** - Audit trail for compliance
- ✅ **Spatie Permissions** - Role-based access control
- ✅ **Spatie Backup** - Automated database backups
- ✅ **Spatie Query Builder** - Advanced API filtering
- ✅ **Laravel Debugbar** - Development debugging
- ✅ **Laravel Telescope** - Advanced debugging and monitoring
- ✅ **PHPStan/Larastan** - Static analysis for code quality

**Use this if:** You want a production-ready setup with all recommended packages.

---

## 🚀 Quick Start

### Option A: Basic Setup (Recommended for New Projects)

```bash
# Install dependencies
composer install

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Start server
php artisan serve
```

### Option B: Enhanced Setup (Production-Ready)

```bash
# Rename enhanced file to composer.json
mv composer.enhanced.json composer.json

# Install all dependencies (this will take a few minutes)
composer install

# Generate app key
php artisan key:generate

# Publish package configurations
php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"
php artisan vendor:publish --provider="Spatie\Backup\BackupServiceProvider"
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider"

# Run migrations
php artisan migrate

# Start server
php artisan serve
```

---

## 📚 Package Documentation

### Core Packages (Included in Both)

#### Laravel Framework ^11.0
The foundation of UBIS Provincial Grid.
- **Documentation:** https://laravel.com/docs/11.x
- **Features:** Routing, Eloquent ORM, Validation, Queue, Cache

#### Laravel Sanctum ^4.0
API token authentication for the Provincial Grid.
- **Documentation:** https://laravel.com/docs/11.x/sanctum
- **Use in UBIS:** Token-based auth for Municipal/Provincial users
- **Setup:** Already configured in routes/api.php

#### Guzzle HTTP ^7.8
HTTP client for external API calls.
- **Documentation:** https://docs.guzzlephp.org/
- **Use in UBIS:** Calling SMS gateways, third-party verification APIs

#### Laravel Tinker ^2.9
REPL (Read-Eval-Print Loop) for Laravel.
- **Documentation:** https://laravel.com/docs/11.x/artisan#tinker
- **Use in UBIS:** Generate tokens, test queries, seed data
```bash
php artisan tinker
>>> $user = User::first();
>>> $token = $user->createToken('test')->plainTextToken;
```

---

### Enhanced Packages (Only in composer.enhanced.json)

#### Intervention Image ^3.5
Image processing for disbursement proof photos.
- **Documentation:** https://image.intervention.io/v3
- **Use in UBIS:**
  - Resize uploaded photos (optimize storage)
  - Generate thumbnails
  - Add watermarks for authenticity
  - EXIF data extraction (camera, GPS)

**Example Usage:**
```php
use Intervention\Image\ImageManager;

$image = ImageManager::gd()->read($request->file('photo'));
$image->resize(1200, 1200);
$image->save(storage_path('app/public/disbursement_proofs/photo.jpg'));
```

#### Maatwebsite Excel ^3.1
Export reports to Excel for auditing.
- **Documentation:** https://docs.laravel-excel.com/
- **Use in UBIS:**
  - Export flagged claims report
  - Generate monthly disbursement summaries
  - Beneficiary lists for municipalities
  - Fraud risk analysis reports

**Example Usage:**
```php
use Maatwebsite\Excel\Facades\Excel;

Excel::download(new ClaimsExport, 'flagged_claims.xlsx');
```

#### Spatie Activity Log ^4.8
Comprehensive audit trail for compliance.
- **Documentation:** https://spatie.be/docs/laravel-activitylog/
- **Use in UBIS:**
  - Track who approved/rejected claims
  - Log beneficiary record changes
  - Monitor fraud report access
  - Compliance auditing

**Example Usage:**
```php
activity()
    ->causedBy(auth()->user())
    ->performedOn($claim)
    ->withProperties(['status' => 'APPROVED', 'amount' => 5000])
    ->log('Claim approved');
```

#### Spatie Permission ^6.4
Role and permission management.
- **Documentation:** https://spatie.be/docs/laravel-permission/
- **Use in UBIS:**
  - Define roles (ADMIN, REVIEWER, STAFF, VIEWER)
  - Assign permissions (approve-claims, reject-claims, view-reports)
  - Municipal vs Provincial access control

**Example Usage:**
```php
// Assign role to user
$user->assignRole('REVIEWER');

// Check permission in controller
$this->authorize('approve-claims');

// In Blade/API check
if ($user->can('approve-claims')) { ... }
```

#### Spatie Backup ^9.0
Automated database and file backups.
- **Documentation:** https://spatie.be/docs/laravel-backup/
- **Use in UBIS:**
  - Daily automated backups
  - Backup to cloud storage (Google Drive, S3)
  - Critical for government data safety

**Setup:**
```bash
# Run backup manually
php artisan backup:run

# Schedule in app/Console/Kernel.php
protected function schedule(Schedule $schedule)
{
    $schedule->command('backup:clean')->daily()->at('01:00');
    $schedule->command('backup:run')->daily()->at('02:00');
}
```

#### Spatie Query Builder ^6.0
Advanced API filtering and sorting.
- **Documentation:** https://spatie.be/docs/laravel-query-builder/
- **Use in UBIS:**
  - Filter claims by municipality, status, date range
  - Sort beneficiaries by name, age, claim count
  - Paginate large datasets efficiently

**Example API:**
```http
GET /api/intake/flagged-claims?filter[municipality_id]=5&filter[status]=UNDER_REVIEW&sort=-created_at
```

#### Laravel Debugbar ^3.13
Debug toolbar for development.
- **Documentation:** https://github.com/barryvdh/laravel-debugbar
- **Use in UBIS:**
  - Monitor SQL queries (optimize fraud detection)
  - Track API response times
  - Debug authentication issues

**Enable in .env:**
```env
DEBUGBAR_ENABLED=true
```

#### Laravel Telescope ^5.0
Advanced application monitoring.
- **Documentation:** https://laravel.com/docs/11.x/telescope
- **Use in UBIS:**
  - Monitor API requests and responses
  - Track slow queries
  - Debug exceptions
  - View scheduled jobs

**Install:**
```bash
php artisan telescope:install
php artisan migrate
```

Access at: http://localhost:8000/telescope

#### PHPStan / Larastan ^2.9
Static analysis for code quality.
- **Documentation:** https://phpstan.org/
- **Use in UBIS:**
  - Catch bugs before runtime
  - Enforce strict types
  - Improve code quality

**Run Analysis:**
```bash
composer analyse
```

---

## 🔧 Custom Composer Scripts

The composer.json includes custom scripts for common tasks:

### Development Scripts

```bash
# Fresh migration with seeding
composer fresh

# Run tests
composer test

# Run tests with coverage report
composer test-coverage

# Format code (Laravel Pint)
composer format

# Run static analysis (PHPStan)
composer analyse

# Clear all caches
composer clear-all
```

### Setup Scripts

```bash
# Complete project setup (fresh install)
composer setup
# Equivalent to:
# - composer install
# - php artisan key:generate
# - php artisan migrate:fresh --seed
# - php artisan storage:link
```

### Production Scripts

```bash
# Optimize for production
composer optimize
# Equivalent to:
# - php artisan config:cache
# - php artisan route:cache
# - php artisan view:cache

# Full production deployment
composer production-deploy
# Equivalent to:
# - composer install --no-dev --optimize-autoloader
# - php artisan config:cache
# - php artisan route:cache
# - php artisan view:cache
# - php artisan migrate --force
```

---

## 📁 Autoloading

### PSR-4 Autoloading

The composer.json is configured to autoload:

```json
"autoload": {
    "psr-4": {
        "App\\": "app/",
        "Database\\Factories\\": "database/factories/",
        "Database\\Seeders\\": "database/seeders/"
    },
    "files": [
        "app/helpers.php"
    ]
}
```

**This means:**
- Classes in `app/` namespace use `App\` prefix
- Custom helper functions in `app/helpers.php` are globally available

### Available Helper Functions

The `app/helpers.php` file includes UBIS-specific helpers:

```php
// Fraud Detection
phonetic_hash('Dela Cruz')              // Returns SOUNDEX hash

// Formatting
format_amount(5000)                      // Returns "₱5,000.00"
mask_contact_number('09171234567')       // Returns "***-****-4567"
mask_id_number('1234-5678-9012')         // Returns "****-****-9012"

// User Context
is_provincial_staff()                    // Returns bool
is_municipal_staff()                     // Returns bool
current_municipality_id()                // Returns int|null

// Calculations
calculate_levenshtein_similarity('Juan', 'Huan')  // Returns 75.0
calculate_age('1985-06-15')              // Returns 40

// Utilities
get_assistance_types()                   // Returns array of valid types
get_claim_statuses()                     // Returns array of valid statuses
generate_google_maps_url(15.1447, 120.5964)
sanitize_file_name('Photo (1).jpg')     // Returns "Photo_1.jpg"

// Audit Logging
log_audit('Claim Approved', 'Claim', 123, ['amount' => 5000]);
```

**Regenerate Autoload After Adding Files:**
```bash
composer dump-autoload
```

---

## 🔒 Production Best Practices

### 1. Use `--no-dev` in Production

```bash
# Don't install development dependencies in production
composer install --no-dev --optimize-autoloader
```

This excludes:
- Laravel Debugbar
- Laravel Telescope
- PHPStan
- Testing packages

### 2. Optimize Autoloader

```bash
composer install --optimize-autoloader
```

This generates an optimized class map for faster autoloading.

### 3. Lock Dependencies

Always commit `composer.lock` to ensure consistent versions across environments.

```bash
# Don't add this to .gitignore
composer.lock
```

### 4. Security Audits

Check for known vulnerabilities:

```bash
composer audit
```

---

## 🐛 Troubleshooting

### Issue: "Class not found"

**Solution:** Regenerate autoload files
```bash
composer dump-autoload
php artisan optimize:clear
```

### Issue: "Your requirements could not be resolved"

**Solution:** Check PHP version and extensions
```bash
php -v                      # Should be 8.2+
php -m | grep -i mysql      # Should show mysql/pdo_mysql
```

### Issue: Composer is slow

**Solution:** Use Composer 2 with optimized repos
```bash
composer self-update
composer config --global repo.packagist composer https://packagist.org
```

### Issue: Memory limit exceeded

**Solution:** Increase PHP memory limit
```bash
php -d memory_limit=-1 /path/to/composer install
```

---

## 📦 Recommended Additional Packages (Optional)

### For SMS Notifications
```bash
composer require twilio/sdk
# or
composer require semaphore-sms/sdk
```

### For PDF Reports
```bash
composer require barryvdh/laravel-dompdf
```

### For API Documentation
```bash
composer require darkaonline/l5-swagger
```

### For Testing
```bash
composer require --dev pestphp/pest
composer require --dev pestphp/pest-plugin-laravel
```

---

## 🔄 Updating Dependencies

### Update All Packages
```bash
composer update
```

### Update Specific Package
```bash
composer update laravel/framework
```

### Update to Latest Laravel 11.x
```bash
composer require laravel/framework:^11.0 --with-all-dependencies
```

---

## 📊 Comparing Basic vs Enhanced

| Feature | Basic | Enhanced |
|---------|-------|----------|
| Laravel Framework | ✅ | ✅ |
| Sanctum Auth | ✅ | ✅ |
| Image Processing | ❌ | ✅ |
| Excel Exports | ❌ | ✅ |
| Audit Logging | ❌ | ✅ |
| Permissions | ❌ | ✅ |
| Automated Backups | ❌ | ✅ |
| Query Builder | ❌ | ✅ |
| Telescope | ❌ | ✅ |
| Static Analysis | ❌ | ✅ |
| **Size** | ~50 MB | ~120 MB |
| **Install Time** | ~2 min | ~5 min |

---

## 🎯 Recommendation

**For Development/Testing:**
- Start with **composer.json** (basic)
- Add packages as needed
- Faster initial setup

**For Production Government System:**
- Use **composer.enhanced.json**
- Includes audit logging (required for compliance)
- Automated backups (data safety)
- Better error tracking (Telescope)

---

## 📝 Next Steps

1. ✅ Choose your composer.json version
2. ✅ Run `composer install`
3. ✅ Configure packages (see SETUP_GUIDE.md)
4. ✅ Start coding!

---

**Need Help?**

- Laravel Docs: https://laravel.com/docs/11.x
- Composer Docs: https://getcomposer.org/doc/
- Spatie Packages: https://spatie.be/open-source

---

**Generated for UBIS Provincial Grid** | Laravel 11.x | PHP 8.2+
