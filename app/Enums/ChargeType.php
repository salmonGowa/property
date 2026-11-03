<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ChargeType: string implements HasLabel
{
    case Rent = 'rent';
    case ServiceCharge = 'service_charge';
    case Deposit = 'deposit';
    case Utility = 'utility';
    case Penalty = 'penalty';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rent => 'Rent',
            self::ServiceCharge => 'Service Charge',
            self::Deposit => 'Security Deposit',
            self::Utility => 'Utility Recharge',
            self::Penalty => 'Late Payment Penalty',
            self::Other => 'Other',
        };
    }
}
