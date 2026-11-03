<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PenaltyCapType: string implements HasLabel
{
    case None = 'none';
    case Amount = 'amount';
    case PercentOfInvoice = 'percent_of_invoice';

    public function getLabel(): string
    {
        return match ($this) {
            self::None => 'No cap',
            self::Amount => 'Maximum amount (KES)',
            self::PercentOfInvoice => 'Maximum % of the invoice',
        };
    }
}
