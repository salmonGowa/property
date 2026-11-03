<?php

namespace App\Services\Ledger;

use RuntimeException;

/**
 * Thrown when a journal entry would break a ledger rule and so cannot be posted.
 */
class InvalidJournalEntry extends RuntimeException {}
