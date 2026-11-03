<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Printer-friendly tax invoice (use the browser's "Save as PDF" to email it).
 */
class InvoicePrintController extends Controller
{
    public function __invoke(Invoice $invoice): View
    {
        Gate::authorize('view', $invoice);

        return view('invoices.print', [
            'invoice' => $invoice->load(['tenant', 'lines', 'lease.unit.property', 'allocations.payment']),
        ]);
    }
}
