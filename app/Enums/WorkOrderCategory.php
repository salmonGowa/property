<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WorkOrderCategory: string implements HasLabel
{
    case Plumbing = 'plumbing';
    case Electrical = 'electrical';
    case Carpentry = 'carpentry';
    case Painting = 'painting';
    case Roofing = 'roofing';
    case AirConditioning = 'air_conditioning';
    case Lifts = 'lifts';
    case Security = 'security';
    case Cleaning = 'cleaning';
    case PestControl = 'pest_control';
    case Landscaping = 'landscaping';
    case Appliances = 'appliances';
    case General = 'general';

    public function getLabel(): string
    {
        return match ($this) {
            self::Plumbing => 'Plumbing',
            self::Electrical => 'Electrical',
            self::Carpentry => 'Carpentry & Doors',
            self::Painting => 'Painting',
            self::Roofing => 'Roofing',
            self::AirConditioning => 'Air Conditioning',
            self::Lifts => 'Lifts',
            self::Security => 'Security Systems',
            self::Cleaning => 'Cleaning & Waste',
            self::PestControl => 'Pest Control',
            self::Landscaping => 'Landscaping',
            self::Appliances => 'Appliances',
            self::General => 'General Repairs',
        };
    }

    /**
     * The config/ledger.php key of the expense account this kind of work is usually charged to.
     */
    public function expenseAccountKey(): string
    {
        return match ($this) {
            self::Security => 'security',
            self::Cleaning, self::PestControl, self::Landscaping => 'cleaning',
            default => 'repairs',
        };
    }
}
