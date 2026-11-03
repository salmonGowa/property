<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PenaltyMethod: string implements HasLabel
{
    case Percentage = 'percentage';
    case Flat = 'flat';
    case DailyPercentage = 'daily_percentage';

    public function getLabel(): string
    {
        return match ($this) {
            self::Percentage => 'Percentage of overdue amount',
            self::Flat => 'Flat fee',
            self::DailyPercentage => 'Daily percentage (accrues per day late)',
        };
    }
}
