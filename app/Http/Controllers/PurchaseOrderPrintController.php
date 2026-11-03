<?php

namespace App\Http\Controllers;

use App\Models\PurchaseOrder;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Printer-friendly purchase order to send to the supplier (use "Save as PDF" to email it).
 */
class PurchaseOrderPrintController extends Controller
{
    public function __invoke(PurchaseOrder $purchaseOrder): View
    {
        Gate::authorize('view', $purchaseOrder);

        return view('purchase-orders.print', [
            'order' => $purchaseOrder->load(['vendor', 'lines', 'property', 'workOrder.unit', 'requester', 'approver']),
        ]);
    }
}
