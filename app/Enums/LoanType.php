<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum LoanType: string implements HasDescription, HasLabel
{
    case SeniorTerm = 'senior_term';
    case Mortgage = 'mortgage';
    case Mezzanine = 'mezzanine';
    case ConstructionFacility = 'construction_facility';

    public function getLabel(): string
    {
        return match ($this) {
            self::SeniorTerm => 'Senior Term Loan',
            self::Mortgage => 'Mortgage',
            self::Mezzanine => 'Mezzanine Debt',
            self::ConstructionFacility => 'Construction Credit Line',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::SeniorTerm => 'First-ranking bank loan, usually amortising',
            self::Mortgage => 'Loan secured by a charge over a property',
            self::Mezzanine => 'Subordinated debt ranking behind the senior lender',
            self::ConstructionFacility => 'Drawn in stages as building progresses',
        };
    }

    /**
     * The config/ledger.php key of the liability account the loan is usually held in.
     */
    public function liabilityAccountKey(): string
    {
        return $this === self::Mezzanine ? 'loans_mezzanine' : 'loans_long_term';
    }
}
