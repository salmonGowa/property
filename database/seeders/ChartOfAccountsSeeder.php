<?php

namespace Database\Seeders;

use App\Enums\AccountType;
use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Standard chart of accounts for a Kenyan property, warehousing and logistics company.
 * Safe to re-run: accounts are matched on code and only missing ones are created.
 */
class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Header accounts and the posting accounts beneath them.
     *
     * @var array<string, array{name: string, type: AccountType, accounts: array<string, string>}>
     */
    private const CHART = [
        '1000' => ['name' => 'Assets', 'type' => AccountType::Asset, 'accounts' => [
            '1100' => 'Bank - Operating Account',
            '1110' => 'Bank - Tenant Deposits Account',
            '1120' => 'M-Pesa Paybill Clearing',
            '1130' => 'Petty Cash',
            '1200' => 'Accounts Receivable - Tenants',
            '1210' => 'Allowance for Doubtful Debts',
            '1220' => 'Other Receivables',
            '1300' => 'Prepayments',
            '1310' => 'VAT Input (Recoverable)',
            '1320' => 'Withholding Tax Credits (Rent)',
            '1400' => 'Maintenance Stores Inventory',
            '1500' => 'Investment Property - Land & Buildings',
            '1510' => 'Plant & Equipment',
            '1520' => 'Motor Vehicles',
            '1530' => 'Furniture & Office Equipment',
            '1590' => 'Accumulated Depreciation',
        ]],
        '2000' => ['name' => 'Liabilities', 'type' => AccountType::Liability, 'accounts' => [
            '2100' => 'Accounts Payable - Trade',
            '2110' => 'Accrued Expenses',
            '2200' => 'Tenant Security Deposits Held',
            '2210' => 'Rent Received in Advance',
            '2300' => 'VAT Output Payable',
            '2310' => 'Monthly Rental Income Tax Payable',
            '2320' => 'PAYE Payable',
            '2330' => 'Withholding Tax Payable',
            '2340' => 'Corporation Tax Payable',
            '2400' => 'Bank Loans - Current Portion',
            '2410' => 'Accrued Interest Payable',
            '2500' => 'Bank Loans & Mortgages - Long Term',
            '2510' => 'Mezzanine & Other Long-Term Debt',
        ]],
        '3000' => ['name' => 'Equity', 'type' => AccountType::Equity, 'accounts' => [
            '3100' => 'Share Capital',
            '3200' => 'Retained Earnings',
            '3300' => 'Shareholder Distributions',
        ]],
        '4000' => ['name' => 'Revenue', 'type' => AccountType::Revenue, 'accounts' => [
            '4100' => 'Rental Income - Residential',
            '4110' => 'Rental Income - Commercial',
            '4120' => 'Warehouse Storage Income',
            '4130' => '3PL Handling Fees',
            '4200' => 'Service Charge Recoveries',
            '4210' => 'Utility Recharges',
            '4300' => 'Late Payment Penalties',
            '4400' => 'Other Income',
        ]],
        '5000' => ['name' => 'Expenses', 'type' => AccountType::Expense, 'accounts' => [
            '5100' => 'Repairs & Maintenance',
            '5110' => 'Security Services',
            '5120' => 'Cleaning & Waste Management',
            '5130' => 'Utilities',
            '5140' => 'Insurance',
            '5150' => 'Land Rates & Ground Rent',
            '5200' => 'Salaries & Wages',
            '5300' => 'Professional Fees',
            '5310' => 'Bank & M-Pesa Charges',
            '5400' => 'Depreciation',
            '5500' => 'Bad Debts',
            '5600' => 'Interest Expense',
            '5700' => 'Income Tax Expense',
            '5900' => 'General & Administrative',
        ]],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::CHART as $headerCode => $group) {
            $header = Account::query()->firstOrCreate(
                ['code' => $headerCode],
                ['name' => $group['name'], 'type' => $group['type'], 'is_header' => true],
            );

            foreach ($group['accounts'] as $code => $name) {
                Account::query()->firstOrCreate(
                    ['code' => $code],
                    ['name' => $name, 'type' => $group['type'], 'parent_id' => $header->id],
                );
            }
        }
    }
}
