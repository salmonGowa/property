<?php

namespace App\Http\Controllers;

use App\Models\SupplierPayment;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Printer-friendly payment voucher listing the bills a supplier payment settled.
 */
class SupplierPaymentPrintController extends Controller
{
    public function __invoke(SupplierPayment $supplierPayment): View
    {
        Gate::authorize('view', $supplierPayment);

        return view('supplier-payments.print', [
            'payment' => $supplierPayment->load(['vendor', 'allocations.bill', 'paidFromAccount', 'creator']),
        ]);
    }
}
