# UBIS Provincial Grid API - Postman Collection Guide

## 📦 Collection Overview

**File**: `UBIS_Provincial_Grid_API.postman_collection.json`
**Version**: 2026
**Total Endpoints**: 47
**Schema**: Postman Collection v2.1.0
**File Size**: 88KB

## 🚀 Quick Start

### 1. Import the Collection

1. Open Postman Desktop or Web
2. Click **Import** button (top left)
3. Select **File** tab
4. Choose: `docs/UBIS_Provincial_Grid_API.postman_collection.json`
5. Click **Import**

### 2. Configure Variables

The collection uses two variables:

| Variable | Default Value | Description |
|----------|--------------|-------------|
| `base_url` | `http://localhost:8000/api` | API base URL |
| `api_token` | (auto-set) | Bearer authentication token |

To modify:
1. Right-click the collection → **Edit**
2. Go to **Variables** tab
3. Update `base_url` if needed (e.g., for production)
4. Save changes

### 3. Authenticate

1. Navigate to **Authentication** folder
2. Run the **Login** request
3. The `api_token` will be **automatically saved** via test script
4. All subsequent requests will use this token

**Default Credentials** (from seeders):
```json
{
  "email": "governor@ifugao.gov.ph",
  "password": "password123",
  "device_name": "postman"
}
```

**Other Test Users**:
- Provincial Admin: `pswdo.head@ifugao.gov.ph` / `password123`
- Municipal Admin (Lagawe): `elena.dulnuan@lagawe.ifugao.gov.ph` / `password123`

## 📂 Collection Structure

The collection is organized into 12 logical folders:

### 1. **Health Check** (1 endpoint)
- `GET /health` - Public endpoint to verify API is running

### 2. **Authentication** (3 endpoints)
- `POST /auth/login` - Login and get bearer token
- `GET /auth/me` - Get current authenticated user
- `POST /auth/logout` - Revoke current token

### 3. **Municipalities** (5 endpoints)
- `GET /municipalities` - List all municipalities
- `POST /municipalities` - Create municipality (Admin only)
- `GET /municipalities/{uuid}` - Get municipality details
- `PUT /municipalities/{uuid}` - Update municipality
- `DELETE /municipalities/{uuid}` - Delete municipality

### 4. **Users** (5 endpoints)
- `GET /users` - List users (filtered by scope)
- `POST /users` - Create user
- `GET /users/{uuid}` - Get user details
- `PUT /users/{uuid}` - Update user
- `DELETE /users/{uuid}` - Delete user

### 5. **Assistance Types** (1 endpoint)
- `GET /assistance-types` - List all assistance types

### 6. **Beneficiaries** (5 endpoints)
- `GET /beneficiaries` - List beneficiaries (scoped)
- `POST /beneficiaries` - Create beneficiary (**Golden Record**)
- `GET /beneficiaries/{uuid}` - Get beneficiary details
- `PUT /beneficiaries/{uuid}` - Update beneficiary
- `DELETE /beneficiaries/{uuid}` - Soft delete beneficiary

### 7. **Claims** (2 endpoints)
- `GET /claims` - List claims (scoped by municipality)
- `GET /claims/{uuid}` - Get claim details

### 8. **Intake** (5 endpoints)
- `POST /intake/check-duplicate` - Check for duplicate beneficiaries
- `POST /intake/assess-risk` - Assess fraud risk before creating claim
- `POST /intake/claims` - Create new claim
- `GET /intake/flagged-claims` - List flagged claims
- `GET /intake/beneficiaries/{uuid}/risk-report` - Generate fraud risk report

### 9. **Disbursement** (4 endpoints)
- `POST /disbursement/claims/{uuid}/approve` - Approve claim
- `POST /disbursement/claims/{uuid}/reject` - Reject claim
- `POST /disbursement/claims/{uuid}/proof` - Upload disbursement proof (multipart/form-data)
- `GET /disbursement/claims/{uuid}/proofs` - Get disbursement proofs

### 10. **Fraud Alerts** (3 endpoints)
- `GET /fraud-alerts/{uuid}` - Get fraud alert details
- `POST /fraud-alerts/{uuid}/assign` - Assign investigator to alert
- `POST /fraud-alerts/{uuid}/notes` - Add investigation notes

### 11. **Dashboard** (10 endpoints)
- `GET /dashboard/summary` - Overview statistics
- `GET /dashboard/metrics-cards` - Key performance indicators
- `GET /dashboard/fraud-alerts` - Recent fraud alerts
- `GET /dashboard/recent-transactions` - Recent claims activity
- `GET /dashboard/assistance-distribution` - Distribution by type
- `GET /dashboard/top-assistance-types` - Most requested assistance
- `GET /dashboard/disbursement-velocity` - Approval/disbursement speed
- `GET /dashboard/savings-ticker` - Fraud prevention savings
- `GET /dashboard/double-dipper-leaderboard` - Top fraud suspects

### 12. **Reports** (4 endpoints)
- `GET /reports/beneficiary-demographics` - Demographics breakdown
- `GET /reports/budget-utilization` - Budget usage by municipality
- `GET /reports/fraud-detection` - Fraud statistics
- `GET /reports/monthly-disbursement` - Monthly disbursement report

## 🔑 Key Features

### UUID-Based Routing
All route parameters use UUIDs instead of integer IDs:
```
✓ /beneficiaries/9d4e8f2a-1234-5678-90ab-cdef12345678
✗ /beneficiaries/123
```

### Authentication
- Collection-level Bearer token authentication
- Login endpoint auto-saves token via test script
- No need to manually copy/paste tokens

### Request Examples
All POST/PUT requests include realistic example bodies:
```json
{
  "first_name": "Maria",
  "last_name": "Santos",
  "birthdate": "1985-05-15",
  "gender": "Female",
  "municipality_id": "{{municipality_uuid}}"
}
```

### Query Parameters
Documented with examples:
- Pagination: `?page=1&per_page=20`
- Filtering: `?municipality_id={{uuid}}&status=ACTIVE`
- Sorting: `?sort_by=created_at&sort_order=desc`

## 🔍 Testing Fraud Detection

### Scenario 1: Check for Duplicates
1. **Intake → Check Duplicate**
2. Use existing beneficiary data:
   ```json
   {
     "first_name": "Jose",
     "last_name": "Santos",
     "birthdate": "1975-01-15"
   }
   ```
3. Review similarity scores and matches

### Scenario 2: Assess Risk
1. **Intake → Assess Risk**
2. Use data from someone with recent claims
3. Check risk flags and recommendations

### Scenario 3: View Fraud Report
1. **Intake → Get Risk Report**
2. Replace `{beneficiary}` with a UUID from the database
3. Review claim history and risk summary

## 🎯 Common Workflows

### Create a New Beneficiary + Claim
```
1. POST /intake/check-duplicate        (verify no duplicates)
2. POST /intake/assess-risk            (check fraud risk)
3. POST /beneficiaries                 (create if clear)
4. POST /intake/claims                 (create claim)
5. GET /claims/{uuid}                  (verify creation)
```

### Approve and Disburse a Claim
```
1. GET /claims                         (list pending claims)
2. POST /disbursement/claims/{uuid}/approve
3. POST /disbursement/claims/{uuid}/proof (upload proof)
4. GET /disbursement/claims/{uuid}/proofs (verify upload)
```

### Investigate Fraud Alert
```
1. GET /dashboard/fraud-alerts         (list alerts)
2. GET /fraud-alerts/{uuid}            (get details)
3. POST /fraud-alerts/{uuid}/assign    (assign investigator)
4. POST /fraud-alerts/{uuid}/notes     (add notes)
```

## 📊 Dashboard Analytics Workflow
```
1. GET /dashboard/summary              (overview)
2. GET /dashboard/metrics-cards        (KPIs)
3. GET /dashboard/fraud-alerts         (recent alerts)
4. GET /dashboard/savings-ticker       (fraud savings)
5. GET /dashboard/double-dipper-leaderboard (top suspects)
```

## 🔐 Access Control Testing

### Provincial Staff (Global Access)
Login as: `governor@ifugao.gov.ph`
- Can view ALL municipalities' data
- Can create users for any municipality
- Full access to fraud reports

### Municipal Staff (Scoped Access)
Login as: `elena.dulnuan@lagawe.ifugao.gov.ph`
- Can only view Lagawe municipality's data
- Can search global beneficiary index (with masked data)
- Limited to their municipality's claims

## 🐛 Troubleshooting

### Issue: "Unauthenticated" Error
**Solution**: Run the Login request again. Token may have expired.

### Issue: "UUID not found" Error
**Solution**:
1. Run `GET /beneficiaries` or relevant list endpoint
2. Copy a valid UUID from the response
3. Replace placeholder in the request

### Issue: "403 Forbidden" Error
**Solution**: Check if the authenticated user has permission for this resource (Provincial vs Municipal scope).

### Issue: Variables Not Working
**Solution**:
1. Right-click collection → Edit → Variables
2. Ensure `base_url` is set correctly
3. Re-run Login to refresh `api_token`

## 📝 Tips

1. **Use Environments**: Create separate environments for Dev/Staging/Production
2. **Save Responses**: Use Postman's "Save Response" feature for documentation
3. **Run Collections**: Use Collection Runner for automated testing
4. **Generate Code**: Use Postman's "Code" snippet feature to generate HTTP client code
5. **Export Documentation**: Generate HTML docs via Postman's publish feature

## 🔄 Keeping Collection Updated

When routes change:
```bash
# 1. View current routes
php artisan route:list --path=api

# 2. Update collection accordingly
# 3. Re-import into Postman
```

## 📚 Additional Resources

- **Project README**: `../README.md`
- **API Documentation**: `../docs/`
- **Database Schema**: `../database/migrations/`
- **Seeder Data**: `../database/seeders/`

---

**Last Updated**: February 5, 2026
**Maintained By**: UBIS Development Team
