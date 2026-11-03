<?php

namespace App\Models;

use App\Enums\FiscalPeriodStatus;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\FiscalPeriodFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'starts_on', 'ends_on', 'status', 'closed_at', 'closed_by'])]
class FiscalPeriod extends Model
{
    /** @use HasFactory<FiscalPeriodFactory> */
    use Auditable, HasFactory;

    protected $attributes = [
        'status' => 'open',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'status' => FiscalPeriodStatus::class,
            'closed_at' => 'datetime',
        ];
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function journalEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    #[Scope]
    protected function containing(Builder $query, CarbonInterface $date): Builder
    {
        return $query->whereDate('starts_on', '<=', $date)->whereDate('ends_on', '>=', $date);
    }

    public function isOpen(): bool
    {
        return $this->status === FiscalPeriodStatus::Open;
    }
}
