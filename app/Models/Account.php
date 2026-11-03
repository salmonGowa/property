<?php

namespace App\Models;

use App\Enums\AccountType;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

#[Fillable(['code', 'name', 'type', 'parent_id', 'is_header', 'is_active', 'description'])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use Auditable, HasFactory;

    protected static function booted(): void
    {
        static::updating(function (Account $account): void {
            if ($account->isDirty(['type', 'is_header']) && $account->lines()->exists()) {
                throw new LogicException("Account {$account->code} has postings, so its type and header flag cannot change.");
            }
        });
    }

    protected function casts(): array
    {
        return [
            'type' => AccountType::class,
            'is_header' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    /**
     * Accounts that journal lines may be posted to.
     */
    #[Scope]
    protected function postable(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_header', false);
    }

    /**
     * The balance on the account's normal side (debit for assets and expenses, credit otherwise).
     */
    public function balance(?CarbonInterface $asOf = null): string
    {
        $totals = $this->lines()
            ->when($asOf, fn ($query) => $query->whereHas('entry', fn (Builder $entry) => $entry->whereDate('entry_date', '<=', $asOf)))
            ->selectRaw('COALESCE(SUM(debit), 0) as debit_total, COALESCE(SUM(credit), 0) as credit_total')
            ->toBase()
            ->first();

        return $this->type->isDebitNormal()
            ? bcsub((string) $totals->debit_total, (string) $totals->credit_total, 2)
            : bcsub((string) $totals->credit_total, (string) $totals->debit_total, 2);
    }
}
