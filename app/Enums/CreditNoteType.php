<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum CreditNoteType: string implements HasColor, HasLabel
{
    case Adjustment = 'adjustment';
    case PenaltyWaiver = 'penalty_waiver';
    case Termination = 'termination';
    case WriteOff = 'write_off';

    public function getLabel(): string
    {
        return match ($this) {
            self::Adjustment => 'Billing adjustment',
            self::PenaltyWaiver => 'Penalty waiver',
            self::Termination => 'Early termination credit',
            self::WriteOff => 'Bad debt write-off',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Adjustment => 'info',
            self::PenaltyWaiver => 'warning',
            self::Termination => 'gray',
            self::WriteOff => 'danger',
        };
    }

    /**
     * Write-offs are an expense (bad debts); every other credit reverses the income billed.
     */
    public function reversesIncome(): bool
    {
        return $this !== self::WriteOff;
    }
}
