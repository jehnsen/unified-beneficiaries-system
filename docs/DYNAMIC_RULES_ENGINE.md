🧪 Testing Instructions
Step 1: Run Migration & Seeder

php artisan migrate
php artisan db:seed --class=SystemSettingSeeder
Step 2: Verify Settings Created

php artisan tinker
>>> \App\Models\SystemSetting::count()
# Should return: 4

>>> \App\Models\SystemSetting::pluck('key', 'value')
# Should show: RISK_THRESHOLD_DAYS => 90, etc.
Step 3: Test API (Provincial Admin)

# Login as provincial admin
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@province.gov.ph","password":"password"}'

# GET all settings
curl http://localhost:8000/api/admin/settings \
  -H "Authorization: Bearer {token}"

# UPDATE a setting (change RISK_THRESHOLD_DAYS to 120)
curl -X PUT http://localhost:8000/api/admin/settings/{uuid} \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"value":120}'
Step 4: Test Authorization (Municipal User)

# Login as municipal user
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email":"municipal@lgugao.gov.ph","password":"password"}'

# Try to access settings (should return 403)
curl http://localhost:8000/api/admin/settings \
  -H "Authorization: Bearer {token}"
Step 5: Test Fraud Detection Integration

# Test that FraudDetectionService uses dynamic values
php artisan tinker
>>> $service = app(\App\Services\FraudDetectionService::class);
>>> $result = $service->checkRisk('Juan', 'Dela Cruz', '1990-01-01', 'Medical');
>>> $result->details
# Should use the current RISK_THRESHOLD_DAYS value from database
🎯 Key Features Delivered
Runtime Configuration - Governor can adjust fraud detection thresholds during calamities without redeployment
Type Safety - Explicit casting prevents type mismatch bugs (integer/float/boolean/json/string)
Caching - 1-hour cache TTL ensures high read performance (invalidated on updates)
Security - Provincial-only access via manage-settings Gate (Provincial Staff + Admin role)
Audit Trail - All changes logged with updated_by and timestamps
Validation - Dynamic validation rules stored in database, enforced at API layer
Extensibility - Easy to add new configurable parameters
📋 Initial Settings Seeded
Key	Default	Range	Description
RISK_THRESHOLD_DAYS	90	1-365	Historical window for fraud pattern detection
SAME_TYPE_THRESHOLD_DAYS	30	1-180	Double-dipping prevention period
HIGH_FREQUENCY_THRESHOLD	3	1-10	Max claims before flagging as suspicious
LEVENSHTEIN_DISTANCE_THRESHOLD	3	0-10	Name matching strictness
All settings are in the fraud_detection category and are editable by Provincial admins.

The Dynamic Rules Engine is now ready for use! 🚀