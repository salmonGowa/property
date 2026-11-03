<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PaymentMethod: string implements HasLabel
{
    case Mpesa = 'mpesa';
    case BankTransfer = 'bank_transfer';
    case Cheque = 'cheque';
    case Cash = 'cash';
    case WithholdingTax = 'withholding_tax';
    case DepositOffset = 'deposit_offset';

    public function getLabel(): string
    {
        return match ($this) {
            self::Mpesa => 'M-Pesa',
            self::BankTransfer => 'Bank Transfer / EFT',
            self::Cheque => 'Cheque',
            self::Cash => 'Cash',
            self::WithholdingTax => 'Withholding Tax Certificate',
            self::DepositOffset => 'Applied from deposit',
        };
    }

    /**
     * Methods a cashier can choose; deposit offsets are only created by deposit settlement.
     *
     * @return list<self>
     */
    public static function receivable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $method): bool => $method !== self::DepositOffset));
    }

    /**
     * Ways of paying a supplier.
     *
     * @return list<self>
     */
    public static function disbursable(): array
    {
        return [self::BankTransfer, self::Mpesa, self::Cheque, self::Cash];
    }
}
