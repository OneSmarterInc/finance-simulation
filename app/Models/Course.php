<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasUuids, SoftDeletes;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_DEPLOYED = 'deployed';

    protected $fillable = [
        'institution_id',
        'faculty_id',
        'scenario_id',
        'name',
        'starts_on',
        'ends_on',
        'payment_mode',
        'cf_plus_enabled',
        'accounting_election_enabled',
        'build_quarters',
        'adversarial_quarters',
        'post_bid_quarters',
        'status',
        'deployed_at',
        'registration_token',
        'registration_enabled',
    ];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'cf_plus_enabled' => 'boolean',
            'accounting_election_enabled' => 'boolean',
            'registration_enabled' => 'boolean',
            'build_quarters' => 'integer',
            'adversarial_quarters' => 'integer',
            'post_bid_quarters' => 'integer',
            'deployed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /** @return BelongsTo<Faculty, $this> */
    public function faculty(): BelongsTo
    {
        return $this->belongsTo(Faculty::class);
    }

    /** @return BelongsTo<Scenario, $this> */
    public function scenario(): BelongsTo
    {
        return $this->belongsTo(Scenario::class);
    }

    /** @return HasMany<CourseQuarter, $this> */
    public function quarters(): HasMany
    {
        return $this->hasMany(CourseQuarter::class);
    }

    /** @return HasMany<CourseFacultyRole, $this> */
    public function facultyRoles(): HasMany
    {
        return $this->hasMany(CourseFacultyRole::class);
    }

    /** @return HasMany<Firm, $this> */
    public function firms(): HasMany
    {
        return $this->hasMany(Firm::class);
    }

    /** @return HasMany<Enrollment, $this> */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /** @return HasMany<CfPlusDisclosureSnapshot, $this> */
    public function cfPlusDisclosureSnapshots(): HasMany
    {
        return $this->hasMany(CfPlusDisclosureSnapshot::class);
    }

    /** @return HasMany<CfPlusConsultantReport, $this> */
    public function cfPlusConsultantReports(): HasMany
    {
        return $this->hasMany(CfPlusConsultantReport::class);
    }

    /** @return HasMany<CfPlusAuctionEconomicsSnapshot, $this> */
    public function cfPlusAuctionEconomicsSnapshots(): HasMany
    {
        return $this->hasMany(CfPlusAuctionEconomicsSnapshot::class);
    }

    /** @return HasMany<CfPlusOffer, $this> */
    public function cfPlusOffers(): HasMany
    {
        return $this->hasMany(CfPlusOffer::class);
    }

    /** @return HasMany<CfPlusAuctionResult, $this> */
    public function cfPlusAuctionResults(): HasMany
    {
        return $this->hasMany(CfPlusAuctionResult::class);
    }
}
