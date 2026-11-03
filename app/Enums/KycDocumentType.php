<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum KycDocumentType: string implements HasLabel
{
    case NationalId = 'national_id';
    case KraPin = 'kra_pin';
    case Incorporation = 'incorporation';
    case Cr12 = 'cr12';
    case BusinessPermit = 'business_permit';
    case LeaseAgreement = 'lease_agreement';
    case Other = 'other';

    public function getLabel(): string
    {
        return match ($this) {
            self::NationalId => 'National ID / Passport',
            self::KraPin => 'KRA PIN Certificate',
            self::Incorporation => 'Certificate of Incorporation',
            self::Cr12 => 'CR12',
            self::BusinessPermit => 'Business Permit',
            self::LeaseAgreement => 'Signed Lease Agreement',
            self::Other => 'Other',
        };
    }
}
