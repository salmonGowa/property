<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PenaltyRecurrence: string implements HasLabel
{
    case Once = 'once';
    case Monthly = 'monthly';
    case EveryNDays = 'every_n_days';

    public function getLabel(): string
    {
        return match ($this) {
            self::Once => 'Once per invoice',
            self::Monthly => 'Every month while unpaid',
            self::EveryNDays => 'Every N days while unpaid',
        };
    }
}
