<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Thrown when a disbursement would push used_budget past allocated_budget.
 *
 * Caught explicitly in DisbursementController::uploadProof() to return 422
 * instead of the generic 500 the base Exception handler produces.
 */
class InsufficientBudgetException extends \RuntimeException
{
}
