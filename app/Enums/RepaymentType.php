<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

enum RepaymentType: string implements HasDescription, HasLabel
{
    case Annuity = 'annuity';
    case EqualPrincipal = 'equal_principal';
    case InterestOnly = 'interest_only';

    public function getLabel(): string
    {
        return match ($this) {
            self::Annuity => 'Equal instalments',
            self::EqualPrincipal => 'Equal principal',
            self::InterestOnly => 'Interest only, repay at maturity',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::Annuity => 'The same payment each period; the principal share grows over time (typical mortgage)',
            self::EqualPrincipal => 'The same principal each period plus interest, so payments fall over time',
            self::InterestOnly => 'Only interest until maturity, then the whole balance (bullet)',
        };
    }
}
