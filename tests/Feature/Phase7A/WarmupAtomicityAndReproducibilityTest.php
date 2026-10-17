<?php

namespace Tests\Feature\Phase7A;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\Scenario;
use App\Models\Student;
use App\Services\Engines\CorporateFinance\AccountingIdentityValidator;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\FinancialPlausibilityValidator;
use App\Services\Warmup\CfManufacturingWarmupService;
use App\Services\Warmup\Contracts\WarmupGenerationResult;
use App\Services\Warmup\Contracts\WarmupQuarterResult;
use App\Services\Warmup\Exceptions\WarmupGenerationException;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class WarmupAtomicityAndReproducibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    #[DataProvider('failingQuarters')]
    public function test_any_quarter_accounting_failure_rolls_back_every_state_and_allows_retry(int $failingQuarter): void
    {
        $this->assertFailureRollsBackAndRetries($failingQuarter, false);
    }

    public function test_plausibility_failure_rolls_back_every_state_and_allows_retry(): void
    {
        $this->assertFailureRollsBackAndRetries(2, true);
    }

    public function test_multiple_firms_are_reproducible_but_receive_distinct_histories(): void
    {
        [$course, $first] = $this->firm('F-A');
        $second = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => 'F-B']);
        $service = app(InheritedWarmupHistoryService::class);
        $service->initialize($first);
        $service->initialize($second);

        $firstHashes = $first->engineResults()->orderBy('quarter_number')->pluck('payload_hash')->all();
        $secondHashes = $second->engineResults()->orderBy('quarter_number')->pluck('payload_hash')->all();
        $this->assertCount(3, $firstHashes);
        $this->assertCount(3, $secondHashes);
        $this->assertNotSame($firstHashes, $secondHashes);
        $this->assertSame(FirmWarmupInitializationCoordinator::courseSeed($course), FirmWarmupInitializationCoordinator::courseSeed($course->fresh()));
    }

    public function test_inherited_result_rejects_direct_update_and_delete(): void
    {
        [, $firm] = $this->firm('LOCK');
        app(InheritedWarmupHistoryService::class)->initialize($firm);
        $result = $firm->engineResults()->firstOrFail();

        try {
            $result->forceFill(['quarter_number' => 9])->save();
            $this->fail('Update should be rejected.');
        } catch (LogicException) {
            $this->assertSame(1, $result->refresh()->quarter_number);
        }

        $this->expectException(LogicException::class);
        $result->delete();
    }

    public static function failingQuarters(): array
    {
        return [[1], [2], [3]];
    }

    private function assertFailureRollsBackAndRetries(int $failingQuarter, bool $plausibilityFailure): void
    {
        [$course, $firm] = $this->firm($plausibilityFailure ? 'PLAUS' : "FAULT-{$failingQuarter}");
        $faculty = Faculty::query()->firstOrFail();
        $student = Student::query()->firstOrFail();
        $membership = FirmMembership::factory()->create([
            'firm_id' => $firm->id,
            'course_id' => $course->id,
            'student_id' => $student->id,
            'institution_id' => $course->institution_id,
            'assigned_by_faculty_id' => $faculty->id,
        ]);
        $courseState = $this->rawState($course, ['status', 'deployed_at', 'updated_at']);
        $membershipState = $this->rawState($membership, ['id', 'firm_id', 'course_id', 'student_id', 'assigned_at', 'updated_at', 'deleted_at']);
        $seed = FirmWarmupInitializationCoordinator::courseSeed($course);
        $valid = app(CfManufacturingWarmupService::class)->generateFromSeedHash($seed, $firm->id, $course->scenario);
        $quarters = $valid->quarters;
        $original = $quarters[$failingQuarter];
        $fields = $original->statements->fields;
        $fields['bs_cash'] += 100;
        if ($plausibilityFailure) {
            $fields['bs_retained'] += 100;
            $fields['cf_equity'] += 100;
        }
        $statements = new ManufacturingEngineResult($fields, $original->statements->incomeStatement, $original->statements->balanceSheet, $original->statements->cashFlowStatement, $original->statements->notes, $original->statements->intermediates);
        $quarters[$failingQuarter] = new WarmupQuarterResult($original->quarter, $original->demand, $original->decisions, $original->opening, $statements, $original->plausibility, $original->flags);
        $warmup = Mockery::mock(CfManufacturingWarmupService::class);
        $warmup->shouldReceive('generateFromSeedHash')->once()->andReturn(new WarmupGenerationResult($seed, $firm->id, $quarters));
        $service = new InheritedWarmupHistoryService($warmup, app(AccountingIdentityValidator::class), app(FinancialPlausibilityValidator::class));

        $caught = null;
        try {
            $service->initialize($firm);
        } catch (WarmupGenerationException $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Initialization must fail explicitly.');
        $this->assertSame($failingQuarter, $caught->quarter);
        $this->assertStringContainsString("Quarter {$failingQuarter}", $caught->getMessage());
        $this->assertDatabaseCount('firm_engine_results', 0);
        $this->assertDatabaseMissing('firm_engine_results', ['firm_id' => $firm->id, 'quarter_number' => 1]);
        $this->assertSame(0, $firm->engineResults()->whereIn('quarter_number', [1, 2])->count());
        $this->assertSame(0, $firm->engineResults()->whereNotNull('fields')->count());
        $this->assertSame(0, $firm->engineResults()->where('provenance', 'inherited_warmup')->count());
        $this->assertSame(Firm::WARMUP_FAILED, $firm->refresh()->warmup_status);
        $this->assertNull($firm->initialized_at);
        $this->assertNotNull($firm->initialization_failed_at);
        $this->assertSame($membershipState, $this->rawState($membership->fresh(), array_keys($membershipState)));
        $this->assertSame($courseState, $this->rawState($course->fresh(), array_keys($courseState)));
        $this->assertDatabaseCount('jobs', 0);

        // No separate retry/completion marker exists; failed status remains eligible for the same controlled service retry.
        app(InheritedWarmupHistoryService::class)->initialize($firm->fresh());
        $this->assertSame([1, 2, 3], $firm->engineResults()->orderBy('quarter_number')->pluck('quarter_number')->all());
        $this->assertDatabaseCount('firm_engine_results', 3);
        $this->assertSame(Firm::WARMUP_INITIALIZED, $firm->refresh()->warmup_status);
        $this->assertNotNull($firm->initialized_at);
        $this->assertNull($firm->initialization_error);
        $this->assertSame($membershipState, $this->rawState($membership->fresh(), array_keys($membershipState)));
        $this->assertSame($courseState, $this->rawState($course->fresh(), array_keys($courseState)));
    }

    /** @param array<int, string> $keys @return array<string, mixed> */
    private function rawState(Model $model, array $keys): array
    {
        return collect($keys)->mapWithKeys(fn (string $key): array => [$key => $model->getRawOriginal($key)])->all();
    }

    /** @return array{Course, Firm} */
    private function firm(string $code): array
    {
        $faculty = Faculty::query()->firstOrFail();
        $course = Course::query()->create([
            'institution_id' => $faculty->institution_id, 'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => "Course {$code}", 'starts_on' => '2026-09-01', 'ends_on' => '2026-12-31',
            'cf_plus_enabled' => false, 'status' => Course::STATUS_DEPLOYED, 'deployed_at' => now(),
        ]);

        return [$course, Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => $code])];
    }
}
