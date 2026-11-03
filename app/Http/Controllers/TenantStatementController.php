<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Collections\TenantStatement;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Printer-friendly statement of account (use the browser's "Save as PDF" to send it).
 */
class TenantStatementController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, TenantStatement $statement): View
    {
        Gate::authorize('view', $tenant);

        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = Carbon::parse($validated['from'] ?? today()->subMonths(11)->startOfMonth());
        $to = Carbon::parse($validated['to'] ?? today());

        return view('statements.tenant', [
            'tenant' => $tenant,
            'from' => $from,
            'to' => $to,
            'statement' => $statement->build($tenant, $from, $to),
        ]);
    }
}
