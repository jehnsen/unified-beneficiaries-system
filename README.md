Continue integrating in FE:

Beneficiary Demographics	/api/reports/beneficiary-demographics	None

Fraud Detection Analysis	/api/reports/fraud-detection	?month=YYYY-MM

Quarterly Budget Utilization	/api/reports/budget-utilization	?quarter=Q1&year=2026

=============================
NewLy Added Endpoints:
=============================
GET /api/dashboard/savings-ticker
GET /api/dashboard/double-dipper-leaderboard
GET /api/dashboard/top-assistance-types

## ✅ Completed Tasks

### UUID Implementation (February 5, 2026)
- ✅ Updated all database seeders to generate UUIDs for public-facing tables
- ✅ API Resources already using UUIDs (MunicipalityResource, UserResource, BeneficiaryResource, ClaimResource)
- ✅ FraudDetectionService updated with UUID wrapper methods:
  - `generateRiskReportByUuid(string $uuid)`
  - `checkDuplicatesByUuid(..., ?string $excludeBeneficiaryUuid = null)`
- ✅ All route parameters now use UUID-based model binding

### Postman Collection (February 5, 2026)
- ✅ Updated `docs/UBIS_Provincial_Grid_API.postman_collection.json` with all 47 endpoints
- ✅ All route parameters use UUIDs instead of integer IDs
- ✅ Organized into 12 logical folders with comprehensive documentation
- ✅ Created `docs/POSTMAN_COLLECTION_GUIDE.md` for quick reference

**Import the collection**: See `docs/POSTMAN_COLLECTION_GUIDE.md` for instructions