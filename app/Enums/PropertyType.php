<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum PropertyType: string implements HasLabel
{
    case Residential = 'residential';
    case Commercial = 'commercial';
    case MixedUse = 'mixed_use';
    case Industrial = 'industrial';

    public function getLabel(): string
    {
        return match ($this) {
            self::Residential => 'Residential',
            self::Commercial => 'Commercial',
            self::MixedUse => 'Mixed Use',
            self::Industrial => 'Industrial / Warehouse',
        };
    }
}
