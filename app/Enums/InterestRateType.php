<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum InterestRateType: string implements HasLabel
{
    case Fixed = 'fixed';
    case Variable = 'variable';

    public function getLabel(): string
    {
        return match ($this) {
            self::Fixed => 'Fixed',
            self::Variable => 'Variable (base rate + margin)',
        };
    }
}
