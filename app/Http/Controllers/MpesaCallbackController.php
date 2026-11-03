<?php

namespace App\Http\Controllers;

use App\Services\Collections\MpesaC2bService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Safaricom Daraja C2B callbacks. Daraja does not sign requests, so the URL carries a
 * secret token (MPESA_CALLBACK_TOKEN) that must match before anything is processed.
 */
class MpesaCallbackController extends Controller
{
    /**
     * Accept every payment at validation; matching happens after confirmation.
     */
    public function validation(string $token): JsonResponse
    {
        $this->assertToken($token);

        return $this->accepted();
    }

    public function confirmation(Request $request, string $token, MpesaC2bService $mpesa): JsonResponse
    {
        $this->assertToken($token);

        $payload = $request->validate([
            'TransID' => ['required', 'string', 'max:30'],
            'TransAmount' => ['required', 'numeric', 'gt:0'],
            'TransTime' => ['required', 'string'],
            'TransactionType' => ['nullable', 'string'],
            'BillRefNumber' => ['nullable', 'string', 'max:100'],
            'MSISDN' => ['nullable', 'string', 'max:20'],
            'FirstName' => ['nullable', 'string'],
            'MiddleName' => ['nullable', 'string'],
            'LastName' => ['nullable', 'string'],
            'BusinessShortCode' => ['nullable', 'string'],
        ]);

        $mpesa->ingest($payload);

        return $this->accepted();
    }

    private function assertToken(string $token): void
    {
        $expected = (string) config('services.mpesa.callback_token');

        abort_if($expected === '' || ! hash_equals($expected, $token), 404);
    }

    private function accepted(): JsonResponse
    {
        return response()->json(['ResultCode' => 0, 'ResultDesc' => 'Accepted']);
    }
}
