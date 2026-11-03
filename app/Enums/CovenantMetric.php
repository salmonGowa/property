<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum CovenantMetric: string implements HasDescription, HasLabel
{
    case Dscr = 'dscr';
    case Ltv = 'ltv';
    case DebtYield = 'debt_yield';

    public function getLabel(): string
    {
        return match ($this) {
            self::Dscr => 'DSCR (minimum)',
            self::Ltv => 'Loan-to-Value (maximum)',
            self::DebtYield => 'Debt Yield (minimum)',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::Dscr => 'DSCR',
            self::Ltv => 'LTV',
            self::DebtYield => 'Debt Yield',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Dscr => 'Net operating income over the last 12 months ÷ loan payments due in the next 12 months',
            self::Ltv => 'Outstanding principal ÷ latest valuation of the security properties',
            self::DebtYield => 'Net operating income over the last 12 months ÷ outstanding principal',
        };
    }

    /**
     * Whether the covenant sets a floor (DSCR, debt yield) rather than a ceiling (LTV).
     */
    public function isMinimum(): bool
    {
        return $this !== self::Ltv;
    }

    public function format(string|float|null $value): string
    {
        if ($value === null) {
            return '-';
        }

        return $this === self::Dscr ? number_format((float) $value, 2).'x' : number_format((float) $value, 1).'%';
    }
}
