<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderPriority: string implements HasColor, HasDescription, HasLabel
{
    case Emergency = 'emergency';
    case High = 'high';
    case Normal = 'normal';
    case Low = 'low';

    public function getLabel(): string
    {
        return match ($this) {
            self::Emergency => 'Emergency',
            self::High => 'High',
            self::Normal => 'Normal',
            self::Low => 'Low',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Emergency => 'danger',
            self::High => 'warning',
            self::Normal => 'info',
            self::Low => 'gray',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Emergency => 'Burst pipe, power out, security risk: fix within 1 day',
            self::High => 'Affects use of the unit: fix within 3 days',
            self::Normal => 'Fix within 7 days',
            self::Low => 'Cosmetic or planned: fix within 14 days',
        };
    }

    /**
     * Days from reporting by which the work should be completed.
     */
    public function targetDays(): int
    {
        return match ($this) {
            self::Emergency => 1,
            self::High => 3,
            self::Normal => 7,
            self::Low => 14,
        };
    }
}
