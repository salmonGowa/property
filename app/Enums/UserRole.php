<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case FinanceManager = 'finance_manager';
    case Accountant = 'accountant';
    case PropertyManager = 'property_manager';
    case WarehouseManager = 'warehouse_manager';
    case GateOfficer = 'gate_officer';
    case Auditor = 'auditor';

    public function getLabel(): string
    {
        return match ($this) {
            self::Admin => 'Administrator',
            self::FinanceManager => 'Finance Manager',
            self::Accountant => 'Accountant',
            self::PropertyManager => 'Property Manager',
            self::WarehouseManager => 'Warehouse Manager',
            self::GateOfficer => 'Gate Officer',
            self::Auditor => 'Auditor (read-only)',
        };
    }
}
