# Provincial UBIS API Documentation

## Base URL
```
Development: http://localhost:8000/api
Production: https://ubis.province.gov.ph/api
```

## Authentication
All endpoints require Bearer token authentication via Laravel Sanctum.

**Headers Required:**
```
Authorization: Bearer {token}
Content-Type: application/json
Accept: application/json
```

---

## Endpoints Overview

### 1. Health Check
**Endpoint:** `GET /health`
**Authentication:** Not required

**Response:**
```json
{
  "status": "ok",
  "service": "Provincial UBIS API",
  "version": "1.0.0",
  "timestamp": "2024-01-01T12:00:00Z"
}
```

---

## INTAKE MODULE

### 2. Check Duplicate Beneficiary
**Endpoint:** `POST /intake/check-duplicate`
**Purpose:** Search for existing beneficiaries using phonetic matching (SOUNDEX) to enforce the "Golden Record" principle.

**Request Body:**
```json
{
  "first_name": "Juan",
  "last_name": "Dela Cruz",
  "birthdate": "1990-01-01",
  "assistance_type": "Medical" // Optional
}
```

**Response (No Duplicates):**
```json
{
  "data": {
    "has_duplicates": false,
    "message": "No existing beneficiary found. Safe to create new record.",
    "matches": []
  }
}
```

**Response (Duplicates Found):**
```json
{
  "data": {
    "has_duplicates": true,
    "message": "Potential duplicate(s) found in the Provincial Grid.",
    "matches": [
      {
        "id": 123,
        "full_name": "Juan Dela Cruz",
        "first_name": "Juan",
        "last_name": "Dela Cruz",
        "birthdate": "1990-01-01",
        "age": 34,
        "gender": "Male",
        "contact_number": "***-****-****",
        "home_municipality": {
          "id": 2,
          "name": "Municipality A",
          "code": "MUN-001"
        },
        "is_active": true,
        "created_at": "2024-01-01T12:00:00Z"
      }
    ]
  }
}
```

---

### 3. Assess Fraud Risk
**Endpoint:** `POST /intake/assess-risk`
**Purpose:** Check if a beneficiary poses a fraud risk based on claim history across ALL municipalities.

**Request Body:**
```json
{
  "first_name": "Juan",
  "last_name": "Dela Cruz",
  "birthdate": "1990-01-01",
  "assistance_type": "Medical" // Optional
}
```

**Response (No Risk):**
```json
{
  "data": {
    "is_risky": false,
    "risk_level": "LOW",
    "details": "No matching beneficiaries found in the Provincial Grid.",
    "matching_beneficiaries_count": 0,
    "recent_claims_count": 0
  }
}
```

**Response (Risk Detected):**
```json
{
  "data": {
    "is_risky": true,
    "risk_level": "HIGH",
    "details": "Claimed assistance from 3 different municipalities | Received Medical assistance 15 days ago from Municipality B | High frequency: 5 claims in the last 90 days",
    "matching_beneficiaries_count": 1,
    "recent_claims_count": 5
  }
}
```

**Risk Levels:**
- `LOW`: No risk factors detected
- `MEDIUM`: 1-2 risk flags
- `HIGH`: 3+ risk flags or 5+ claims in 90 days

---

### 4. Create Claim
**Endpoint:** `POST /intake/claims`
**Purpose:** Create a new claim with automatic fraud detection and beneficiary creation/linking.

**Request Body:**
```json
{
  "home_municipality_id": 2,
  "first_name": "Juan",
  "last_name": "Dela Cruz",
  "middle_name": "Santos",
  "suffix": "Jr.",
  "birthdate": "1990-01-01",
  "gender": "Male",
  "contact_number": "09123456789",
  "address": "123 Main St, Barangay Centro",
  "barangay": "Centro",
  "id_type": "PhilSys",
  "id_number": "1234-5678-9012",
  "municipality_id": 2,
  "assistance_type": "Medical",
  "amount": 5000.00,
  "purpose": "Hospital bill for emergency treatment",
  "notes": "Urgent case"
}
```

**Field Notes:**
- `municipality_id`: Required for Provincial Staff, auto-filled for Municipal Staff
- `assistance_type`: One of: `Medical`, `Cash`, `Burial`, `Educational`, `Food`, `Disaster Relief`
- `amount`: Decimal, max 999999.99

**Response (Success):**
```json
{
  "data": {
    "id": 456,
    "beneficiary": {
      "id": 123,
      "full_name": "Juan Santos Dela Cruz Jr.",
      "home_municipality": {
        "id": 2,
        "name": "Municipality A",
        "code": "MUN-001"
      }
    },
    "municipality": {
      "id": 2,
      "name": "Municipality A",
      "code": "MUN-001"
    },
    "assistance_type": "Medical",
    "amount": "5000.00",
    "purpose": "Hospital bill for emergency treatment",
    "status": "PENDING",
    "is_flagged": true,
    "flag_reason": "Received Medical assistance 15 days ago from Municipality B",
    "risk_assessment": {
      "is_risky": true,
      "risk_level": "MEDIUM",
      "details": "Received Medical assistance 15 days ago from Municipality B"
    },
    "created_at": "2024-01-01T12:00:00Z"
  },
  "message": "Claim created but flagged for review due to fraud risk."
}
```

**Status Flow:**
```
PENDING → UNDER_REVIEW → APPROVED → DISBURSED
                ↓
            REJECTED/CANCELLED
```

---

### 5. Get Risk Report for Beneficiary
**Endpoint:** `GET /intake/beneficiaries/{id}/risk-report`
**Purpose:** Generate detailed fraud risk analysis for a specific beneficiary.

**Response:**
```json
{
  "data": {
    "beneficiary": {
      "id": 123,
      "name": "Juan Dela Cruz",
      "birthdate": "1990-01-01",
      "home_municipality": "Municipality A"
    },
    "risk_summary": {
      "total_claims": 5,
      "municipalities_involved": 3,
      "municipality_names": ["Municipality A", "Municipality B", "Municipality C"],
      "assistance_types": ["Medical", "Cash", "Educational"],
      "total_amount_received": 25000.00,
      "risk_level": "HIGH"
    },
    "recent_claims": [
      {
        "id": 456,
        "municipality": "Municipality B",
        "assistance_type": "Medical",
        "amount": 5000.00,
        "status": "DISBURSED",
        "date": "2024-01-15",
        "days_ago": 15
      }
    ]
  }
}
```

---

### 6. Get Flagged Claims
**Endpoint:** `GET /intake/flagged-claims`
**Purpose:** Retrieve claims flagged for fraud review.

**Response:**
```json
{
  "data": [
    {
      "id": 456,
      "beneficiary": {
        "id": 123,
        "full_name": "Juan Dela Cruz"
      },
      "municipality": {
        "id": 2,
        "name": "Municipality A"
      },
      "assistance_type": "Medical",
      "amount": "5000.00",
      "status": "PENDING",
      "is_flagged": true,
      "flag_reason": "Received Medical assistance 15 days ago from Municipality B",
      "created_at": "2024-01-01T12:00:00Z"
    }
  ]
}
```

---

### 7. FALSE POSITIVE HANDLER (Whitelist Management)

The False Positive Handler prevents the fraud detection system from repeatedly flagging verified distinct beneficiaries. When admins manually verify that two phonetically similar beneficiaries are actually different people, the system remembers and never flags them again.

#### 7.1 Whitelist Pair
**Endpoint:** `POST /intake/whitelist-pair`
**Purpose:** Whitelist a pair of beneficiaries as verified distinct or duplicate.

**Authorization:**
- **Provincial Staff**: Can whitelist ANY pair
- **Municipal Staff**: Can only whitelist if AT LEAST ONE beneficiary is from their municipality

**Request Body:**
```json
{
  "beneficiary_a_uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
  "beneficiary_b_uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
  "verification_status": "VERIFIED_DISTINCT",
  "verification_reason": "Verified via PhilSys ID - confirmed as different people with similar names",
  "notes": "Juan Cruz (ID: PSN-1234567890) and Juan Kruz (ID: PSN-0987654321) are distinct individuals",
  "similarity_score": 85,
  "levenshtein_distance": 2
}
```

**Field Notes:**
- `beneficiary_a_uuid`: First beneficiary UUID (required)
- `beneficiary_b_uuid`: Second beneficiary UUID (required, must be different from A)
- `verification_status`: Status (required) - `"VERIFIED_DISTINCT"` or `"VERIFIED_DUPLICATE"`
- `verification_reason`: Detailed reason (required, min 10 characters)
- `notes`: Additional notes (optional)
- `similarity_score`: Similarity score from fraud detection (optional, 0-100)
- `levenshtein_distance`: Levenshtein distance from fraud detection (optional)

**Use Cases:**
- **VERIFIED_DISTINCT**: Mark phonetically similar names as different people (e.g., "Juan Cruz" vs "Juan Kruz")
- **VERIFIED_DUPLICATE**: Confirm they are actually the same person

**Effect:** Pairs marked as `VERIFIED_DISTINCT` will be excluded from future fraud detection.

**Response (Success - 201):**
```json
{
  "data": {
    "pair_id": "9d4e8f2a-9999-8888-90ab-cdef12345678",
    "beneficiary_a": {
      "uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
      "name": "Juan Dela Cruz"
    },
    "beneficiary_b": {
      "uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
      "name": "Juan Kruz"
    },
    "verification_status": "VERIFIED_DISTINCT",
    "verified_at": "2026-02-06T10:30:00Z",
    "verified_by": "Admin User"
  },
  "message": "Beneficiary pair successfully verified."
}
```

**Response (Conflict - 409):**
```json
{
  "error": "This pair has already been verified.",
  "data": {
    "existing_status": "VERIFIED_DISTINCT",
    "verified_at": "2026-02-05T14:20:00Z",
    "verified_by": "Jane Doe"
  }
}
```

**Response (Authorization Denied - 403):**
```json
{
  "error": "Authorization denied. You can only whitelist beneficiaries from your municipality."
}
```

---

#### 7.2 Get Verified Pairs
**Endpoint:** `GET /intake/verified-pairs`
**Purpose:** Get paginated list of verified beneficiary pairs.

**Query Parameters:**
- `per_page`: Results per page (optional, default: 15)
- `status`: Filter by verification status (optional) - `VERIFIED_DISTINCT`, `VERIFIED_DUPLICATE`, `UNDER_REVIEW`, `REVOKED`

**Scoping:**
- **Provincial Staff**: See ALL verified pairs
- **Municipal Staff**: See only pairs involving their municipality

**Response (Success - 200):**
```json
{
  "data": [
    {
      "pair_id": "9d4e8f2a-9999-8888-90ab-cdef12345678",
      "beneficiary_a": {
        "uuid": "9d4e8f2a-1234-5678-90ab-cdef12345678",
        "name": "Juan Dela Cruz",
        "municipality": "Lagawe"
      },
      "beneficiary_b": {
        "uuid": "9d4e8f2a-5678-1234-90ab-cdef87654321",
        "name": "Juan Kruz",
        "municipality": "Lamut"
      },
      "verification_status": "VERIFIED_DISTINCT",
      "similarity_score": 85,
      "verified_at": "2026-02-06T10:30:00Z",
      "verified_by": "Admin User"
    }
  ],
  "meta": {
    "current_page": 1,
    "total": 45,
    "per_page": 15
  }
}
```

---

#### 7.3 Revoke Verified Pair
**Endpoint:** `DELETE /intake/whitelist-pair/{pair}`
**Purpose:** Revoke a verified pair whitelist.

**Route Parameter:** `pair` - Verified pair UUID

**Authorization:**
- **Provincial Staff**: Can revoke ANY pair
- **Municipal Staff**: Can only revoke pairs involving their municipality

**Request Body:**
```json
{
  "revocation_reason": "New evidence shows these are actually the same person - PhilSys records merged"
}
```

**Field Notes:**
- `revocation_reason`: Detailed reason for revocation (required, min 10 characters)

**Effect:** Pair status changes to `REVOKED` and will re-enter fraud detection.

**Response (Success - 200):**
```json
{
  "message": "Pair verification revoked successfully."
}
```

**Response (Authorization Denied - 403):**
```json
{
  "error": "Authorization denied."
}
```

---

### How the False Positive Handler Works

**Before Implementation:**
```
Step 1: System flags "Juan Cruz" and "Juan Kruz" as duplicates
Step 2: Admin reviews and confirms they are different people
Step 3: Next claim → System flags them AGAIN ❌ (frustrating!)
```

**After Implementation:**
```
Step 1: System flags "Juan Cruz" and "Juan Kruz" as duplicates
Step 2: Admin reviews and confirms they are different people
Step 3: Admin whitelists the pair via POST /intake/whitelist-pair
Step 4: Pair stored in verified_distinct_pairs table
Step 5: Next claim → System SKIPS this pair ✅ (no repeated flags!)
```

**Workflow Example:**
1. Fraud detection flags two similar beneficiaries: "Maria Santos" and "Maria Santoz"
2. Admin investigates and confirms they have different PhilSys IDs
3. Admin calls `POST /intake/whitelist-pair` with:
   - `verification_status: "VERIFIED_DISTINCT"`
   - `verification_reason: "Verified via PhilSys ID check - different individuals"`
4. System stores the pair in the database
5. Future fraud checks automatically exclude this pair
6. If needed later, admin can revoke via `DELETE /intake/whitelist-pair/{pair}`

**Database Design:**
- Pairs are normalized: `beneficiary_a_id < beneficiary_b_id` (smaller ID always first)
- This ensures (5, 10) and (10, 5) are stored as the same record
- Bidirectional indexes enable fast lookups regardless of search order
- Audit trail includes: similarity metrics, verification reason, who verified, when verified

---

## DISBURSEMENT MODULE

### 7. Approve Claim
**Endpoint:** `POST /disbursement/claims/{id}/approve`
**Authorization:** Requires `ADMIN` or `REVIEWER` role

**Request Body:**
```json
{
  "notes": "Verified supporting documents" // Optional
}
```

**Response:**
```json
{
  "data": {
    "id": 456,
    "status": "APPROVED",
    "approved_at": "2024-01-02T10:00:00Z",
    "processed_by": {
      "id": 1,
      "name": "Admin User",
      "role": "ADMIN"
    }
  },
  "message": "Claim approved successfully."
}
```

---

### 8. Reject Claim
**Endpoint:** `POST /disbursement/claims/{id}/reject`
**Authorization:** Requires `ADMIN` or `REVIEWER` role

**Request Body:**
```json
{
  "rejection_reason": "Insufficient supporting documents. Missing medical certificate."
}
```

**Response:**
```json
{
  "data": {
    "id": 456,
    "status": "REJECTED",
    "rejected_at": "2024-01-02T10:00:00Z",
    "rejection_reason": "Insufficient supporting documents. Missing medical certificate.",
    "processed_by": {
      "id": 1,
      "name": "Admin User",
      "role": "ADMIN"
    }
  },
  "message": "Claim rejected."
}
```

---

### 9. Upload Disbursement Proof
**Endpoint:** `POST /disbursement/claims/{id}/proof`
**Purpose:** Upload proof of disbursement and mark claim as DISBURSED (final step).

**Request:** `multipart/form-data`

**Fields:**
```
photo: File (required, max 5MB, jpg/jpeg/png)
signature: File (required, max 2MB, jpg/jpeg/png)
id_photo: File (optional, max 5MB, jpg/jpeg/png)
latitude: Number (optional, -90 to 90)
longitude: Number (optional, -180 to 180)
location_accuracy: String (optional, e.g., "10 meters")
```

**Example (cURL):**
```bash
curl -X POST http://localhost:8000/api/disbursement/claims/456/proof \
  -H "Authorization: Bearer {token}" \
  -F "photo=@beneficiary_photo.jpg" \
  -F "signature=@signature.png" \
  -F "id_photo=@valid_id.jpg" \
  -F "latitude=14.5995" \
  -F "longitude=120.9842" \
  -F "location_accuracy=5 meters"
```

**Response:**
```json
{
  "data": {
    "claim": {
      "id": 456,
      "status": "DISBURSED",
      "disbursed_at": "2024-01-02T14:00:00Z"
    },
    "proof": {
      "id": 789,
      "photo_url": "http://localhost:8000/storage/disbursement-proofs/photos/xyz.jpg",
      "signature_url": "http://localhost:8000/storage/disbursement-proofs/signatures/abc.png",
      "location": {
        "latitude": 14.5995,
        "longitude": 120.9842,
        "accuracy": "5 meters",
        "google_maps_url": "https://www.google.com/maps?q=14.5995,120.9842"
      },
      "captured_at": "2024-01-02T14:00:00Z",
      "captured_by": {
        "id": 1,
        "name": "Admin User"
      }
    }
  },
  "message": "Disbursement proof uploaded successfully. Claim marked as DISBURSED."
}
```

---

### 10. Get Disbursement Proofs
**Endpoint:** `GET /disbursement/claims/{id}/proofs`
**Purpose:** Retrieve all disbursement proofs for a claim.

**Response:**
```json
{
  "data": [
    {
      "id": 789,
      "claim_id": 456,
      "photo_url": "http://localhost:8000/storage/disbursement-proofs/photos/xyz.jpg",
      "signature_url": "http://localhost:8000/storage/disbursement-proofs/signatures/abc.png",
      "location": {
        "latitude": 14.5995,
        "longitude": 120.9842,
        "google_maps_url": "https://www.google.com/maps?q=14.5995,120.9842"
      },
      "captured_at": "2024-01-02T14:00:00Z"
    }
  ]
}
```

---

## Error Responses

### Validation Error (422)
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "first_name": [
      "The first name field is required."
    ],
    "amount": [
      "The amount must be greater than zero."
    ]
  }
}
```

### Unauthorized (401)
```json
{
  "message": "Unauthenticated."
}
```

### Forbidden (403)
```json
{
  "error": "Unauthorized. You can only approve claims from your municipality."
}
```

### Not Found (404)
```json
{
  "error": "Beneficiary not found"
}
```

### Server Error (500)
```json
{
  "error": "Failed to create claim.",
  "message": "Database connection failed"
}
```

---

## Multi-Tenancy Rules

### Provincial Staff (Global Access)
- `user.municipality_id = NULL`
- Can view/edit ALL records across all municipalities
- Used for oversight and provincial-level reporting

### Municipal Staff (Tenant-Scoped)
- `user.municipality_id = SET`
- Can only view/edit records from their municipality
- **Exception:** Fraud checks can search the entire Provincial Grid

### Data Masking
When viewing beneficiaries from another municipality:
- Contact details are masked: `***-****-****`
- Address is hidden: `[Hidden - Different Municipality]`
- ID numbers are masked: `****`

---

## Rate Limiting
- Default: 60 requests per minute per user
- Configurable in `RouteServiceProvider`

## Testing Credentials

### Provincial Admin
```
Email: admin@province.gov.ph
Password: password
Municipality: NULL (Global Access)
```

### Municipal Admin (Municipality A)
```
Email: admin@muna.gov.ph
Password: password
Municipality: Municipality A (ID: 2)
```

---

## Postman Collection
[Download Postman Collection](#) (Coming Soon)

## Support
For API issues or questions, contact: tech-support@province.gov.ph
