<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum PaymentStatus: string implements HasColor, HasLabel
{
    case Posted = 'posted';
    case Reversed = 'reversed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Posted => 'Posted',
            self::Reversed => 'Reversed',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Posted => 'success',
            self::Reversed => 'danger',
        };
    }
}
