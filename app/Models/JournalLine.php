<?php

declare(strict_types=1);

namespace App\Models;

use App\Exceptions\LedgerRuleException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class JournalLine extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'operating_unit_id',
        'client_id',
        'employee_id',
        'debit',
        'credit',
        'currency',
        'original_amount',
        'fx_rate',
        'memo',
    ];

    protected $casts = [
        'debit' => 'decimal:4',
        'credit' => 'decimal:4',
        'original_amount' => 'decimal:4',
        'fx_rate' => 'decimal:8',
    ];

    /**
     * ACC-13: a posted line never changes. Corrections are reversals, and
     * query-builder maintenance (backfills) bypasses model events on purpose.
     */
    protected static function booted(): void
    {
        self::updating(function (): void {
            throw new LedgerRuleException('Posted journal lines cannot be changed; reverse the entry instead.', 'LEDGER_IMMUTABLE');
        });

        self::deleting(function (): void {
            throw new LedgerRuleException('Posted journal lines cannot be deleted; reverse the entry instead.', 'LEDGER_IMMUTABLE');
        });
    }

    /**
     * ACC-16: lines of entries that are not soft-deleted. The SoftDeletes
     * scope applies inside the subquery.
     *
     * @param  Builder<JournalLine>  $query
     * @return Builder<JournalLine>
     */
    public function scopePosted(Builder $query): Builder
    {
        return $query->whereIn('journal_lines.journal_entry_id', JournalEntry::query()->select('id'));
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function operatingUnit(): BelongsTo
    {
        return $this->belongsTo(OperatingUnit::class);
    }
}
