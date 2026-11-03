<?php

namespace App\Policies;

use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;

/**
 * Invoices are never edited or deleted once issued; they are voided instead.
 */
class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewBilling();
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $user->canViewBilling();
    }

    public function create(User $user): bool
    {
        return $user->canManageBilling();
    }

    public function void(User $user, Invoice $invoice): bool
    {
        return $user->canManageLedger()
            && $invoice->status !== InvoiceStatus::Void
            && ! $invoice->allocations()->exists();
    }
}
