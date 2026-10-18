<?php

namespace App\Models;

use App\Support\Scenario\CfManufacturingScenarioDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use LogicException;

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
        'engine_version',
        'starting_elections',
        'is_practice',
        'practice_source_course_id',
        'practice_last_played_at',
        'practice_team_settings',
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
            'engine_version' => 'integer',
            'starting_elections' => 'array',
            'is_practice' => 'boolean',
            'ai_helper_enabled' => 'boolean',
            'ai_questions_per_month' => 'integer',
            'practice_last_played_at' => 'datetime',
            'practice_team_settings' => 'array',
            'deployed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // A course keeps the engine version it starts with to the end (ED11).
        static::creating(function (Course $course): void {
            $course->engine_version ??= (int) config('flexee.engine_version_for_new_courses', 1);
        });
        static::updating(function (Course $course): void {
            if ($course->isDirty('engine_version') && FirmEngineResult::query()->where('course_id', $course->id)->exists()) {
                throw new LogicException('A course keeps its engine version once any firm has results.');
            }
        });
    }

    /**
     * Courses with real students: practice runs (U9) are kept out of every
     * list, report, count and the scheduler.
     *
     * @param  Builder<Course>  $query
     */
    public function scopeReal($query): void
    {
        $query->where('is_practice', false);
    }

    public function engineVersion(): int
    {
        return (int) ($this->engine_version ?? 1);
    }

    /**
     * The accounting methods every firm starts with (ED9): the faculty's
     * choice from the wizard, or the standard methods. Inventory stays FIFO.
     *
     * @return array<string, string>
     */
    public function startingElections(): array
    {
        $standard = CfManufacturingScenarioDefinition::fixedWarmupPayload('notes_elections');
        $chosen = is_array($this->starting_elections) ? $this->starting_elections : [];
        $elections = [];
        foreach ($standard as $key => $value) {
            $elections[$key] = $chosen[$key] ?? $value;
        }
        $elections['inventory_method'] = 'fifo';

        return $elections;
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
