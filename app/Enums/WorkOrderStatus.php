<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum WorkOrderStatus: string implements HasColor, HasLabel
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function getLabel(): string
    {
        return match ($this) {
            self::Open => 'Open - not assigned',
            self::Assigned => 'Assigned',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Assigned => 'info',
            self::InProgress => 'warning',
            self::Completed => 'success',
            self::Cancelled => 'gray',
        };
    }

    /**
     * Work that still needs doing.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Open, self::Assigned, self::InProgress];
    }

    public function isUnfinished(): bool
    {
        return in_array($this, self::unfinished(), true);
    }
}
