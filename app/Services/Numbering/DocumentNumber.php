<?php

namespace App\Services\Numbering;

use App\Models\DocumentSequence;
use Illuminate\Support\Facades\DB;

class DocumentNumber
{
    public const TENANT = 'tenant';

    public const LEASE = 'lease';

    public const INVOICE = 'invoice';

    public const PAYMENT = 'payment';

    public const CREDIT_NOTE = 'credit_note';

    public const VENDOR = 'vendor';

    public const WORK_ORDER = 'work_order';

    public const PURCHASE_ORDER = 'purchase_order';

    public const SUPPLIER_BILL = 'supplier_bill';

    public const SUPPLIER_PAYMENT = 'supplier_payment';

    public const LOAN = 'loan';

    /**
     * Prefix and zero-padded width for each sequence.
     *
     * @var array<string, array{0: string, 1: int}>
     */
    private const FORMATS = [
        self::TENANT => ['T', 5],
        self::LEASE => ['LSE-', 5],
        self::INVOICE => ['INV-', 6],
        self::PAYMENT => ['RCT-', 6],
        self::CREDIT_NOTE => ['CN-', 6],
        self::VENDOR => ['SUP-', 4],
        self::WORK_ORDER => ['WO-', 6],
        self::PURCHASE_ORDER => ['PO-', 6],
        self::SUPPLIER_BILL => ['BILL-', 6],
        self::SUPPLIER_PAYMENT => ['PV-', 6],
        self::LOAN => ['LN-', 4],
    ];

    /**
     * Reserve the next number in a sequence. Call inside the transaction that saves
     * the document so a rollback releases the number and no gaps appear.
     */
    public function next(string $sequence): string
    {
        [$prefix, $width] = self::FORMATS[$sequence];

        return DB::transaction(function () use ($sequence, $prefix, $width): string {
            DocumentSequence::query()->insertOrIgnore(['name' => $sequence, 'last_value' => 0]);

            $row = DocumentSequence::query()->whereKey($sequence)->lockForUpdate()->firstOrFail();
            $row->increment('last_value');

            return $prefix.str_pad((string) $row->last_value, $width, '0', STR_PAD_LEFT);
        });
    }
}
