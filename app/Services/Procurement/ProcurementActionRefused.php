<?php

namespace App\Services\Procurement;

use RuntimeException;

/**
 * Thrown when a purchase order, bill or work order cannot take the requested step,
 * e.g. approving above your limit or cancelling an order that has been billed.
 */
class ProcurementActionRefused extends RuntimeException {}
