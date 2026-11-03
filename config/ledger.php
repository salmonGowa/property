<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Posting Accounts
    |--------------------------------------------------------------------------
    |
    | Chart of accounts codes that billing and collections post to. They match
    | the Kenyan template in ChartOfAccountsSeeder; change them here if your
    | chart uses different codes.
    |
    */

    'accounts' => [
        'receivable' => '1200',
        'vat_output' => '2300',
        'tenant_deposits' => '2200',
        'withholding_tax_credits' => '1320',
        'rent_residential' => '4100',
        'rent_commercial' => '4110',
        'service_charge' => '4200',
        'utility_recharge' => '4210',
        'penalties' => '4300',
        'other_income' => '4400',

        // Where money lands for each payment method.
        'deposit' => [
            'mpesa' => '1120',
            'bank_transfer' => '1100',
            'cheque' => '1100',
            'cash' => '1130',
            'withholding_tax' => '1320',
            'deposit_offset' => '2200',
        ],

        'bad_debts' => '5500',

        // Procurement and payables.
        'payable' => '2100',
        'vat_input' => '1310',
        'withholding_tax_payable' => '2330',
        'repairs' => '5100',
        'security' => '5110',
        'cleaning' => '5120',

        // Borrowings.
        'loans_long_term' => '2500',
        'loans_mezzanine' => '2510',
        'interest_expense' => '5600',
        'accrued_interest' => '2410',
        'bank_charges' => '5310',
        'doubtful_debts_allowance' => '1210',

        // Where money leaves from when paying suppliers.
        'disbursement' => [
            'mpesa' => '1120',
            'bank_transfer' => '1100',
            'cheque' => '1100',
            'cash' => '1130',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Tax
    |--------------------------------------------------------------------------
    |
    | Standard VAT rate (percent) applied to commercial rent and service charge
    | on leases flagged as VAT-able. Confirm against the current Finance Act.
    |
    */

    'vat_rate' => env('LEDGER_VAT_RATE', '16.00'),

    /*
    |--------------------------------------------------------------------------
    | Withholding Tax
    |--------------------------------------------------------------------------
    |
    | Rates (percent of the amount before VAT) withheld from resident suppliers
    | and paid to KRA. Confirm against the current Income Tax Act schedule.
    |
    */

    'withholding_tax_rates' => [
        'none' => '0',
        'professional_fees' => '5',
        'management_fees' => '5',
        'contractual_fees' => '3',
    ],

    'currency' => 'KES',

];
