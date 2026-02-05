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

TODO:
Update API resources to expose UUIDs instead of integer IDs
Update FraudDetectionService with UUID wrapper method