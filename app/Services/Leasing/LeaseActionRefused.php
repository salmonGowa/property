<?php

namespace App\Services\Leasing;

use RuntimeException;

/**
 * Thrown when a lease cannot move to the requested state, e.g. activating onto an occupied unit.
 */
class LeaseActionRefused extends RuntimeException {}
