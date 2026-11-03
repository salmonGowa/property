<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * A posted, balanced journal entry. Entries are created only by PostingService and
 * never change afterwards; mistakes are corrected with a reversing entry.
 */
class JournalEntry extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Posted journal entries cannot be changed. Post a reversal instead.'));
        static::deleting(fn () => throw new LogicException('Posted journal entries cannot be deleted. Post a reversal instead.'));
    }

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'sequence' => 'integer',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalLine::class);
    }

    public function fiscalPeriod(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class);
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * The entry this one reverses, if it is a reversal.
     */
    public function reverses(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reverses_entry_id');
    }

    /**
     * The entry that reversed this one, if any.
     */
    public function reversal(): HasOne
    {
        return $this->hasOne(JournalEntry::class, 'reverses_entry_id');
    }

    public static function formatNumber(int $sequence): string
    {
        return 'JE-'.str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);
    }

    protected function number(): Attribute
    {
        return Attribute::get(fn (): string => self::formatNumber($this->sequence));
    }
}
