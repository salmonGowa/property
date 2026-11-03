<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum UnitStatus: string implements HasColor, HasLabel
{
    case Vacant = 'vacant';
    case Reserved = 'reserved';
    case Occupied = 'occupied';
    case Maintenance = 'maintenance';

    public function getLabel(): string
    {
        return match ($this) {
            self::Vacant => 'Vacant',
            self::Reserved => 'Reserved',
            self::Occupied => 'Occupied',
            self::Maintenance => 'Under Maintenance',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Vacant => 'success',
            self::Reserved => 'info',
            self::Occupied => 'gray',
            self::Maintenance => 'warning',
        };
    }
}
