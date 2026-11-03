<?php

namespace App\Services\Debt;

use RuntimeException;

/**
 * Thrown when a loan cannot take the requested step, e.g. drawing more than the facility.
 */
class LoanActionRefused extends RuntimeException {}
