<?php

namespace App\Models;

use Database\Factories\FirmFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property array<string, mixed>|null $accounting_elections
 * @property Carbon|null $accounting_elections_locked_at
 */
class Firm extends Model
{
    /** @use HasFactory<FirmFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public const WARMUP_PENDING = 'pending';

    public const WARMUP_QUEUED = 'queued';

    public const WARMUP_INITIALIZED = 'initialized';

    public const WARMUP_FAILED = 'failed';

    protected $fillable = ['course_id', 'institution_id', 'code', 'name', 'warmup_status', 'accounting_elections', 'accounting_elections_locked_at'];

    protected function casts(): array
    {
        return [
            'initialized_at' => 'datetime',
            'initialization_failed_at' => 'datetime',
            'initialization_error' => 'array',
            'accounting_elections' => 'array',
            'accounting_elections_locked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return HasMany<FirmMembership, $this> */
    public function memberships(): HasMany
    {
        return $this->hasMany(FirmMembership::class);
    }

    /** @return HasMany<FirmEngineResult, $this> */
    public function engineResults(): HasMany
    {
        return $this->hasMany(FirmEngineResult::class);
    }

    /** @return HasMany<FirmQuarterDecision, $this> */
    public function quarterDecisions(): HasMany
    {
        return $this->hasMany(FirmQuarterDecision::class);
    }

    /** @return HasMany<FirmQuarterClose, $this> */
    public function quarterCloses(): HasMany
    {
        return $this->hasMany(FirmQuarterClose::class);
    }

    /** @return HasMany<CfPlusDisclosureSnapshot, $this> */
    public function disclosureSnapshots(): HasMany
    {
        return $this->hasMany(CfPlusDisclosureSnapshot::class, 'target_firm_id');
    }

    /** @return HasMany<CfPlusConsultantReport, $this> */
    public function consultantReports(): HasMany
    {
        return $this->hasMany(CfPlusConsultantReport::class, 'target_firm_id');
    }

    /** @return HasMany<CfPlusOffer, $this> */
    public function acquisitionOffers(): HasMany
    {
        return $this->hasMany(CfPlusOffer::class, 'bidder_firm_id');
    }

    /** @return HasMany<CfPlusOffer, $this> */
    public function incomingAcquisitionOffers(): HasMany
    {
        return $this->hasMany(CfPlusOffer::class, 'target_firm_id');
    }

    /** @return HasMany<CfPlusAuctionResult, $this> */
    public function auctionResultsAsTarget(): HasMany
    {
        return $this->hasMany(CfPlusAuctionResult::class, 'target_firm_id');
    }

    public function accountingElectionRecords() 
    {
        return $this->hasMany(FirmAccountingElection::class); 
    }
}
