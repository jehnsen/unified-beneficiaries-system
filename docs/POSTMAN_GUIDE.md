# UBIS Provincial Grid - Postman Collection Guide

## Overview
This guide will help you set up and use the Postman collection for the UBIS (Unified Beneficiary Identity System) Provincial Grid API.

## Files Included
1. **UBIS_Provincial_Grid_API.postman_collection.json** - Complete API collection with all endpoints
2. **UBIS_Provincial_Grid.postman_environment.json** - Environment variables for development
3. **POSTMAN_GUIDE.md** - This guide

---

## Quick Start

### Step 1: Import Collection and Environment

1. Open Postman
2. Click **Import** button (top left)
3. Drag and drop both JSON files:
   - `UBIS_Provincial_Grid_API.postman_collection.json`
   - `UBIS_Provincial_Grid.postman_environment.json`
4. Click **Import**

### Step 2: Select Environment

1. Click the environment dropdown (top right)
2. Select **"UBIS Provincial Grid - Development"**

### Step 3: Generate Authentication Token

Before you can use most endpoints, you need a valid Laravel Sanctum token.

#### Option A: Using Laravel Tinker (Recommended for Testing)

```bash
# In your Laravel project directory
php artisan tinker

# Create a test user (if not exists)
$user = User::create([
    'name' => 'Test Admin',
    'email' => 'admin@test.com',
    'password' => bcrypt('password'),
    'role' => 'ADMIN',
    'municipality_id' => null, // NULL = Provincial Staff
    'is_active' => true
]);

# Generate a token
$token = $user->createToken('postman-testing')->plainTextToken;
echo $token;

# Copy the token output
```

#### Option B: Using an Authentication Endpoint (If Available)

If you have a login endpoint:
```http
POST /api/login
Content-Type: application/json

{
    "email": "admin@test.com",
    "password": "password"
}
```

### Step 4: Configure Environment Variables

1. Click the **Environment quick look** icon (eye icon, top right)
2. Click **Edit** on "UBIS Provincial Grid - Development"
3. Update the following variables:
   - `api_token` - Paste your token from Step 3
   - `base_url` - Default is `http://localhost:8000/api` (change if different)
   - `municipality_id` - Default is `5` (San Fernando)
4. Click **Save**

---

## Testing Workflow

### Scenario 1: Create a New Claim (Happy Path)

**Goal:** Create a claim for a new beneficiary with no fraud risk.

1. **Check for Duplicates**
   - Request: `POST /intake/check-duplicate`
   - Body: Provide beneficiary details
   - Expected: `"can_proceed": true` (no duplicates)

2. **Assess Fraud Risk**
   - Request: `POST /intake/assess-risk`
   - Body: Same beneficiary details
   - Expected: `"risk_level": "LOW"`

3. **Create Claim**
   - Request: `POST /intake/claims`
   - Body: Complete beneficiary + claim details
   - Expected: Status `201`, `"status": "PENDING"`, `"is_flagged": false`
   - **Save the `claim_id` from response** (you'll need it)

4. **Approve Claim**
   - Request: `POST /disbursement/claims/{id}/approve`
   - Replace `{id}` with your `claim_id`
   - Expected: Status `200`, `"status": "APPROVED"`

5. **Upload Disbursement Proof**
   - Request: `POST /disbursement/claims/{id}/proof`
   - Body: Upload photo and signature files
   - Expected: Status `201`, `"status": "DISBURSED"`

6. **View Disbursement Proofs**
   - Request: `GET /disbursement/claims/{id}/proofs`
   - Expected: List of proofs with photo URLs and GPS data

---

### Scenario 2: High-Risk Claim (Double-Dipping)

**Goal:** Simulate fraud detection when a beneficiary tries to claim twice within 30 days.

1. **Create First Claim** (Follow Scenario 1, steps 1-5)
   - Make sure it's DISBURSED

2. **Wait or Backdate** (In real scenario, wait 1-20 days)
   - For testing, create another claim immediately

3. **Create Second Claim**
   - Request: `POST /intake/claims`
   - Body: Same beneficiary, same assistance_type (e.g., "Medical")
   - Expected: `"status": "UNDER_REVIEW"`, `"is_flagged": true`
   - Note the `flag_reason` in response

4. **View Flagged Claims**
   - Request: `GET /intake/flagged-claims`
   - Expected: Your claim appears in the list

5. **Get Risk Report**
   - Request: `GET /intake/beneficiaries/{id}/risk-report`
   - Expected: Detailed risk analysis showing recent claims

6. **Reject the Claim**
   - Request: `POST /disbursement/claims/{id}/reject`
   - Body: Provide detailed rejection reason
   - Expected: Status `200`, `"status": "REJECTED"`

---

### Scenario 3: Inter-LGU Search (Golden Record)

**Goal:** Test cross-municipality beneficiary search.

**Setup:** You need tokens for two different municipalities:
- Municipal User 1 (Municipality ID: 5 - San Fernando)
- Municipal User 2 (Municipality ID: 8 - Angeles City)

1. **Create Beneficiary in Municipality A**
   - Use `municipal_user_token` for San Fernando
   - Create claim for beneficiary "Juan Dela Cruz"

2. **Search from Municipality B**
   - Switch token to Angeles City user
   - Request: `POST /intake/check-duplicate`
   - Body: Search for "Juan Dela Cruz" (phonetic variations work too)
   - Expected: Duplicate found, but sensitive fields are MASKED:
     - `contact_number`: `"***-****-****"`
     - `address`: `"[Hidden - Different Municipality]"`

3. **As Provincial Staff**
   - Use `provincial_user_token` (municipality_id = NULL)
   - Search for same beneficiary
   - Expected: Full details visible (no masking)

---

## Environment Variables Reference

| Variable | Description | Example |
|----------|-------------|---------|
| `base_url` | API base URL | `http://localhost:8000/api` |
| `api_token` | Current user token (auto-switched) | `1\|abc123...` |
| `provincial_user_token` | Token for Provincial Staff | `2\|xyz789...` |
| `municipal_user_token` | Token for Municipal Staff | `3\|def456...` |
| `claim_id` | Last created claim ID | `789` |
| `beneficiary_id` | Last created beneficiary ID | `456` |
| `municipality_id` | Current municipality | `5` |

### Switching Between User Types

**To test as Provincial Staff:**
1. Set `api_token` to `{{provincial_user_token}}`
2. Can view ALL claims across municipalities

**To test as Municipal Staff:**
1. Set `api_token` to `{{municipal_user_token}}`
2. Can only view claims from their municipality

---

## API Endpoint Summary

### Health Check (No Auth Required)
- `GET /health` - Check if API is running

### Intake Module (Authentication Required)
- `POST /intake/check-duplicate` - Search for existing beneficiary
- `POST /intake/assess-risk` - Check fraud risk before creating claim
- `POST /intake/claims` - Create new claim with auto fraud detection
- `GET /intake/beneficiaries/{id}/risk-report` - Detailed risk analysis
- `GET /intake/flagged-claims` - Review queue for flagged claims

### Disbursement Module (Authentication Required)
- `POST /disbursement/claims/{id}/approve` - Approve claim (ADMIN/REVIEWER only)
- `POST /disbursement/claims/{id}/reject` - Reject claim (ADMIN/REVIEWER only)
- `POST /disbursement/claims/{id}/proof` - Upload disbursement proof (final step)
- `GET /disbursement/claims/{id}/proofs` - View audit trail

---

## Common HTTP Status Codes

| Code | Meaning | Common Causes |
|------|---------|---------------|
| 200 | OK | Request succeeded |
| 201 | Created | Resource created successfully |
| 400 | Bad Request | Malformed JSON or invalid data type |
| 401 | Unauthorized | Missing or invalid token |
| 403 | Forbidden | No permission (wrong role or municipality) |
| 404 | Not Found | Resource doesn't exist |
| 422 | Unprocessable Entity | Validation failed (check `errors` in response) |
| 500 | Server Error | Backend error (check Laravel logs) |

---

## Tips & Best Practices

### 1. Use Pre-request Scripts for Dynamic IDs

In Postman, you can automatically extract IDs from responses:

**Example: Save claim_id after creating a claim**
```javascript
// In "Tests" tab of "Create Claim" request
if (pm.response.code === 201) {
    const response = pm.response.json();
    pm.environment.set("claim_id", response.data.id);
    pm.environment.set("beneficiary_id", response.data.beneficiary.id);
}
```

### 2. Authorization Inheritance

The collection uses **Collection-level Bearer Token auth**, so you don't need to set it per request. Just update `{{api_token}}` in the environment.

### 3. File Upload Requirements

For `/disbursement/claims/{id}/proof`:
- Photo: Max 5MB, JPG/PNG
- Signature: Max 2MB, JPG/PNG
- ID Photo: Optional, Max 5MB, JPG/PNG

**How to upload in Postman:**
1. Select request
2. Go to **Body** tab
3. Select **form-data**
4. For file fields, change type from "Text" to "File"
5. Click "Select Files" and choose your image

### 4. GPS Coordinates Format

```json
{
    "latitude": 15.1447,      // -90 to 90
    "longitude": 120.5964,    // -180 to 180
    "location_accuracy": "10 meters"
}
```

**Philippines GPS Reference:**
- San Fernando, Pampanga: `15.0294° N, 120.6897° E`
- Angeles City, Pampanga: `15.1447° N, 120.5964° E`
- Manila: `14.5995° N, 120.9842° E`

---

## Troubleshooting

### Issue: "Unauthenticated" Error

**Solution:**
1. Check if `api_token` environment variable is set
2. Verify token format: Should be like `1|abc123xyz...`
3. Check if user's `is_active` is true
4. Regenerate token using tinker

### Issue: "This action is unauthorized"

**Solution:**
1. Check user's role (needs ADMIN or REVIEWER for approve/reject)
2. For Municipal Staff: Verify claim belongs to their municipality
3. Use Provincial Staff token for cross-municipality actions

### Issue: "Cannot upload proof. Claim must be in APPROVED status"

**Solution:**
1. Check claim status first
2. Approve claim before uploading proof
3. Cannot upload proof for PENDING, REJECTED, or DISBURSED claims

### Issue: "The given data was invalid" (422 Error)

**Solution:**
1. Read `errors` object in response
2. Check field requirements in request description
3. Common issues:
   - Missing required fields
   - Invalid date format (use `YYYY-MM-DD`)
   - Amount out of range (0.01 to 999,999.99)
   - Invalid assistance_type value

### Issue: "Beneficiary not found" (404 Error)

**Solution:**
1. Verify beneficiary ID exists
2. Check if beneficiary was soft-deleted
3. For Municipal Staff: Check if beneficiary belongs to accessible municipalities

---

## Advanced: Running Tests Automatically

You can use Postman's Collection Runner to test the entire workflow:

1. Click on collection name → **Run**
2. Select environment
3. Click **Run UBIS Provincial Grid API**
4. Postman will execute all requests in sequence

**Pro Tip:** Use the "Tests" tab in each request to add assertions:

```javascript
// Example test for "Create Claim" request
pm.test("Status code is 201", function () {
    pm.response.to.have.status(201);
});

pm.test("Claim is created with correct status", function () {
    const response = pm.response.json();
    pm.expect(response.data.status).to.be.oneOf(['PENDING', 'UNDER_REVIEW']);
});

pm.test("Risk assessment is present", function () {
    const response = pm.response.json();
    pm.expect(response.data.risk_assessment).to.have.property('risk_level');
});
```

---

## Production Environment Setup

To create a production environment:

1. Duplicate the development environment
2. Rename to "UBIS Provincial Grid - Production"
3. Update variables:
   ```
   base_url: https://ubis.province.gov.ph/api
   api_token: <production token>
   ```
4. **Important:** Never commit production tokens to version control

---

## Support & Documentation

- **API Documentation:** See `API_DOCUMENTATION.md` in project root
- **Architecture Guide:** See `ARCHITECTURE.md` in project root
- **Project Instructions:** See `CLAUDE.md` in project root
- **Laravel Sanctum Docs:** https://laravel.com/docs/11.x/sanctum

---

## Example Request Bodies

### Check Duplicate
```json
{
    "first_name": "Juan",
    "last_name": "Dela Cruz",
    "birthdate": "1985-06-15",
    "assistance_type": "Medical"
}
```

### Create Claim (Complete)
```json
{
    "home_municipality_id": 5,
    "first_name": "Pedro",
    "last_name": "Gonzales",
    "middle_name": "Santos",
    "suffix": "Jr.",
    "birthdate": "1988-11-25",
    "gender": "Male",
    "contact_number": "09171234567",
    "address": "456 Market St, Barangay San Isidro",
    "barangay": "San Isidro",
    "id_type": "National ID",
    "id_number": "1234-5678-9012",
    "municipality_id": 5,
    "assistance_type": "Medical",
    "amount": 5000.00,
    "purpose": "Hospital bills for dengue treatment",
    "notes": "Patient is currently admitted at provincial hospital"
}
```

### Approve Claim
```json
{
    "notes": "Verified all documents. Beneficiary qualifies for medical assistance."
}
```

### Reject Claim
```json
{
    "rejection_reason": "Double-dipping confirmed. Beneficiary already received Medical assistance from San Fernando municipality 15 days ago. Per Provincial policy, beneficiaries must wait 30 days between same-type assistance claims."
}
```

---

## Assistance Types Reference

Valid values for `assistance_type` field:
- `Medical` - Medical assistance, hospital bills, medicines
- `Cash` - Cash assistance for emergencies
- `Burial` - Burial assistance for deceased family members
- `Educational` - School supplies, tuition fees
- `Food` - Food packs, groceries
- `Disaster Relief` - Post-disaster assistance

---

## User Roles Reference

| Role | Permissions |
|------|-------------|
| `ADMIN` | Full access: approve, reject, create, view all |
| `REVIEWER` | Can approve/reject claims, view all |
| `STAFF` | Can create claims, upload proofs, view own municipality |
| `VIEWER` | Read-only access to own municipality |

---

## Next Steps

1. ✅ Import collection and environment
2. ✅ Generate authentication token
3. ✅ Test "Health Check" endpoint
4. ✅ Run through Scenario 1 (Happy Path)
5. ✅ Test fraud detection with Scenario 2
6. ✅ Explore inter-LGU features with Scenario 3
7. ✅ Customize tests and scripts for your workflow

---

**Happy Testing! 🚀**

For issues or questions, contact the UBIS Development Team.
