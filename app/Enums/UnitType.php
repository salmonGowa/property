<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UnitType: string implements HasLabel
{
    case Bedsitter = 'bedsitter';
    case Studio = 'studio';
    case Apartment = 'apartment';
    case House = 'house';
    case Office = 'office';
    case Shop = 'shop';
    case Warehouse = 'warehouse';
    case Parking = 'parking';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::Bedsitter => 'Bedsitter',
            self::Studio => 'Studio',
            self::Apartment => 'Apartment',
            self::House => 'House / Maisonette',
            self::Office => 'Office',
            self::Shop => 'Shop / Retail',
            self::Warehouse => 'Warehouse / Godown',
            self::Parking => 'Parking Bay',
            self::Other => 'Other',
        };
    }

    /**
     * Commercial space attracts VAT on rent; residential letting is VAT exempt in Kenya.
     */
    public function isCommercial(): bool
    {
        return in_array($this, [self::Office, self::Shop, self::Warehouse], true);
    }
}
