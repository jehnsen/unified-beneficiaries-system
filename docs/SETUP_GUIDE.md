# UBIS Provincial Grid - Setup Guide

## 🚀 Quick Setup (5 Minutes)

Follow these steps to get your UBIS Provincial Grid API up and running.

---

## Prerequisites

Ensure you have these installed:
- ✅ **PHP 8.2+** (Check: `php -v`)
- ✅ **Composer** (Check: `composer -V`)
- ✅ **MySQL 8.0+** (Check: `mysql --version`)
- ✅ **Node.js & NPM** (Optional, for frontend)

---

## Step 1: Environment Configuration

### 1.1 Copy Environment File
```bash
# The .env file has already been created
# If you need to reset it, copy from example:
cp .env.example .env
```

### 1.2 Generate Application Key
```bash
php artisan key:generate
```

This will automatically populate `APP_KEY` in your `.env` file.

### 1.3 Configure Database

Open `.env` and update the database settings:

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ubis_provincial_grid
DB_USERNAME=root
DB_PASSWORD=your_password_here
```

---

## Step 2: Create Database

### Option A: Using MySQL Command Line
```bash
mysql -u root -p
```

```sql
CREATE DATABASE ubis_provincial_grid CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
EXIT;
```

### Option B: Using phpMyAdmin (XAMPP Users)
1. Open http://localhost/phpmyadmin
2. Click "New" in the left sidebar
3. Database name: `ubis_provincial_grid`
4. Collation: `utf8mb4_unicode_ci`
5. Click "Create"

---

## Step 3: Install Dependencies

```bash
# Install PHP dependencies
composer install

# Install Node dependencies (if you have frontend)
npm install
```

---

## Step 4: Run Migrations

```bash
# Run all migrations to create database tables
php artisan migrate

# If you encounter errors, you can reset:
php artisan migrate:fresh
```

This will create the following tables:
- `users` - System users (Provincial/Municipal staff)
- `municipalities` - Tenant definitions (LGUs)
- `beneficiaries` - Shared global records with phonetic indexing
- `claims` - Assistance claims with fraud flags
- `disbursement_proofs` - Audit trail with photos/GPS
- `personal_access_tokens` - Sanctum authentication tokens
- And other Laravel system tables

---

## Step 5: Seed Database (Optional but Recommended)

```bash
# Seed municipalities and test users
php artisan db:seed

# Or seed specific seeders
php artisan db:seed --class=MunicipalitySeeder
php artisan db:seed --class=UserSeeder
```

**Default Test Users:**
- **Provincial Admin**
  - Email: `admin@ubis.gov.ph`
  - Password: `password`
  - Role: ADMIN
  - Municipality: NULL (Provincial access)

- **Municipal User (San Fernando)**
  - Email: `sanfernando@ubis.gov.ph`
  - Password: `password`
  - Role: STAFF
  - Municipality: San Fernando

---

## Step 6: Setup Storage

### 6.1 Create Storage Symlink
```bash
php artisan storage:link
```

This creates a symbolic link from `public/storage` to `storage/app/public`.

### 6.2 Set Storage Permissions (Linux/Mac)
```bash
chmod -R 775 storage
chmod -R 775 bootstrap/cache
```

### 6.3 For Windows/XAMPP
- Right-click `storage` folder → Properties → Security
- Ensure IUSR and IIS_IUSRS have Full Control

---

## Step 7: Start the Server

### Option A: Laravel Development Server (Recommended for Testing)
```bash
php artisan serve
```

Access at: http://localhost:8000

### Option B: XAMPP/Apache
1. Ensure your document root points to `/public` directory
2. Access at: http://localhost/ubis/public

**Apache Configuration Example:**
```apache
<VirtualHost *:80>
    DocumentRoot "d:/xampp/apache/bin/FE/ubis/public"
    ServerName ubis.local

    <Directory "d:/xampp/apache/bin/FE/ubis/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

---

## Step 8: Verify Installation

### 8.1 Test API Health
```bash
curl http://localhost:8000/api/health
```

Expected response:
```json
{
    "status": "OK",
    "timestamp": "2026-02-03T12:00:00Z"
}
```

### 8.2 Generate Test Token

```bash
php artisan tinker
```

```php
// Get or create a test user
$user = User::first();

// Generate API token
$token = $user->createToken('test-token')->plainTextToken;
echo $token;

// Copy the token for Postman
```

### 8.3 Test Authenticated Endpoint

```bash
curl -H "Authorization: Bearer YOUR_TOKEN_HERE" \
     -H "Accept: application/json" \
     http://localhost:8000/api/intake/flagged-claims
```

---

## Step 9: Import Postman Collection

1. Open Postman
2. Click **Import**
3. Select these files:
   - `UBIS_Provincial_Grid_API.postman_collection.json`
   - `UBIS_Provincial_Grid.postman_environment.json`
4. Select environment: "UBIS Provincial Grid - Development"
5. Update `api_token` variable with your token from Step 8.2
6. Test endpoints!

See [POSTMAN_GUIDE.md](POSTMAN_GUIDE.md) for detailed testing scenarios.

---

## Step 10: Optional Configuration

### Enable Queue Workers (For Background Jobs)
```bash
php artisan queue:work
```

### Enable Task Scheduling (For Periodic Tasks)
Add to crontab (Linux/Mac):
```cron
* * * * * cd /path-to-ubis && php artisan schedule:run >> /dev/null 2>&1
```

### Enable Laravel Telescope (Debug Tool)
```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

Update `.env`:
```env
TELESCOPE_ENABLED=true
```

Access at: http://localhost:8000/telescope

---

## Common Issues & Solutions

### Issue 1: "Base table or view not found"
**Solution:** Run migrations
```bash
php artisan migrate
```

### Issue 2: "SQLSTATE[HY000] [1045] Access denied"
**Solution:** Check database credentials in `.env`
```env
DB_USERNAME=root
DB_PASSWORD=your_actual_password
```

### Issue 3: "Class 'X' not found"
**Solution:** Regenerate autoload files
```bash
composer dump-autoload
php artisan optimize:clear
```

### Issue 4: "The stream or file could not be opened"
**Solution:** Fix storage permissions
```bash
# Linux/Mac
chmod -R 775 storage bootstrap/cache

# Windows: Give full control to IUSR and IIS_IUSRS
```

### Issue 5: "No application encryption key has been specified"
**Solution:** Generate app key
```bash
php artisan key:generate
```

### Issue 6: Storage link not working
**Solution:**
```bash
# Remove old link (if exists)
rm public/storage

# Create new link
php artisan storage:link
```

---

## Environment Variables Reference

### Critical Variables (Must Configure)

| Variable | Description | Example |
|----------|-------------|---------|
| `APP_KEY` | Application encryption key | (auto-generated) |
| `DB_DATABASE` | Database name | `ubis_provincial_grid` |
| `DB_USERNAME` | Database user | `root` |
| `DB_PASSWORD` | Database password | `your_password` |
| `APP_URL` | Application URL | `http://localhost:8000` |

### UBIS-Specific Variables

| Variable | Description | Default |
|----------|-------------|---------|
| `FRAUD_RISK_THRESHOLD_DAYS` | Lookback period for fraud detection | `90` |
| `FRAUD_SAME_TYPE_THRESHOLD_DAYS` | Double-dipping detection window | `30` |
| `FRAUD_HIGH_FREQUENCY_THRESHOLD` | Max claims before flagging | `3` |
| `MAX_CLAIM_AMOUNT` | Maximum claim amount (PHP) | `999999.99` |
| `PHONETIC_ALGORITHM` | Search algorithm (soundex/metaphone) | `soundex` |

### File Upload Limits

| Variable | Description | Default (KB) |
|----------|-------------|--------------|
| `MAX_PHOTO_SIZE` | Disbursement photo max size | `5120` (5MB) |
| `MAX_SIGNATURE_SIZE` | Signature max size | `2048` (2MB) |
| `MAX_ID_PHOTO_SIZE` | ID photo max size | `5120` (5MB) |

---

## Production Deployment Checklist

Before deploying to production, update these in `.env`:

```env
# Application
APP_ENV=production
APP_DEBUG=false

# Security
SESSION_SECURE_COOKIE=true

# Performance
CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

# Logging
LOG_LEVEL=warning

# Database
DB_HOST=your_production_host
DB_DATABASE=ubis_production
DB_USERNAME=ubis_user
DB_PASSWORD=strong_password_here
```

**Additional Steps:**
1. ✅ Run `php artisan config:cache`
2. ✅ Run `php artisan route:cache`
3. ✅ Run `php artisan view:cache`
4. ✅ Set up SSL certificate (HTTPS)
5. ✅ Configure firewall rules
6. ✅ Set up automated backups
7. ✅ Enable error monitoring (Sentry, Bugsnag)
8. ✅ Configure CORS properly
9. ✅ Review file upload limits on server
10. ✅ Test disaster recovery plan

---

## Next Steps

1. ✅ Complete setup (Steps 1-8)
2. ✅ Import Postman collection (Step 9)
3. ✅ Read [POSTMAN_GUIDE.md](POSTMAN_GUIDE.md) for API testing
4. ✅ Review [CLAUDE.md](CLAUDE.md) for architectural guidelines
5. ✅ Start developing!

---

## Useful Commands

### Artisan Commands
```bash
# Clear all caches
php artisan optimize:clear

# Create a new controller
php artisan make:controller Api/YourController --api

# Create a new model with migration
php artisan make:model ModelName -m

# Create a new request (validation)
php artisan make:request StoreRequestName

# Create a new resource (JSON transformer)
php artisan make:resource ResourceName

# Create a new seeder
php artisan make:seeder SeederName

# List all routes
php artisan route:list

# Enter tinker (REPL)
php artisan tinker
```

### Database Commands
```bash
# Run migrations
php artisan migrate

# Rollback last migration batch
php artisan migrate:rollback

# Reset and re-run all migrations
php artisan migrate:fresh

# Reset and seed
php artisan migrate:fresh --seed

# Check migration status
php artisan migrate:status
```

### Testing Commands
```bash
# Run all tests
php artisan test

# Run specific test
php artisan test --filter TestName

# Generate code coverage
php artisan test --coverage
```

---

## Support & Documentation

- **API Documentation:** [POSTMAN_GUIDE.md](POSTMAN_GUIDE.md)
- **Architecture Guide:** [CLAUDE.md](CLAUDE.md)
- **Laravel Docs:** https://laravel.com/docs/11.x
- **Sanctum Docs:** https://laravel.com/docs/11.x/sanctum

---

**🎉 Setup Complete!**

Your UBIS Provincial Grid API is ready for development and testing.

For questions or issues, contact the UBIS Development Team.
