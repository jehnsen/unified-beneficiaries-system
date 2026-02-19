<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Canonical document type labels for DisbursementProof uploads.
 *
 * Using a backed enum (string) means the stored value is human-readable in the
 * additional_documents JSON column and in API responses — no lookup table needed.
 *
 * Named URL columns on DisbursementProof (photo_url, signature_url, id_photo_url)
 * always map to BeneficiaryPhoto, Signature, and ValidId respectively.
 * All other uploads arrive via additional_documents and MUST carry one of these values.
 */
enum DocumentType: string
{
    case BeneficiaryPhoto   = 'Beneficiary Photo';
    case Signature          = 'Signature';
    case ValidId            = 'Valid ID';
    case DeathCertificate   = 'Death Certificate';
    case BarangayClearance  = 'Barangay Clearance';
    case SupportingDocument = 'Supporting Document';
}
