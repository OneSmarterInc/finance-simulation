<?php

namespace Tests\Feature\Phase7B;

use App\Livewire\Faculty\Courses\QuarterManagement;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmMembership;
use App\Models\FirmQuarterClose;
use App\Models\Student;
use App\Services\Decisions\FirmDecisionService;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngine;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineInput;
use App\Services\Engines\CorporateFinance\Contracts\ManufacturingEngineResult;
use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Services\Quarters\FirmQuarterCloseService;
use App\Services\Quarters\QuarterManagementService;
use App\Services\Warmup\InheritedWarmupHistoryService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class CloseFailureRemediationTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_three_firm_partial_success_is_visible_and_retry_reuses_successful_results(): void
    {
        [$course, $firmA, $studentA, $q4, $q5] = $this->phase7bCourse('Three-firm remediation');
        [$firmB, $studentB] = $this->additionalFirm($course, 'B', 1);
        [$firmC, $studentC] = $this->additionalFirm($course, 'C', 2);
        $quarters = app(QuarterManagementService::class);
        $decisions = app(FirmDecisionService::class);
        $quarters->open($course->faculty, $q4);
        foreach ([[$firmA, $studentA], [$firmB, $studentB], [$firmC, $studentC]] as [$firm, $student]) {
            $decisions->submit($student, $firm, $q4, $this->completePayload(), 0);
        }
        DB::table('firm_quarter_decisions')->where('firm_id', $firmB->id)->update(['submitted_payload' => json_encode(['sourcing' => $this->completePayload()['sourcing']], JSON_THROW_ON_ERROR)]);
        Queue::fake();
        $quarters->queueClose($course->faculty, $q4);
        $closer = app(FirmQuarterCloseService::class);
        foreach (FirmQuarterClose::query()->orderBy('firm_id')->get() as $attempt) {
            $closer->close($attempt);
        }
        $quarters->finalize($q4);

        $attemptA = FirmQuarterClose::query()->where('firm_id', $firmA->id)->firstOrFail();
        $attemptB = FirmQuarterClose::query()->where('firm_id', $firmB->id)->firstOrFail();
        $attemptC = FirmQuarterClose::query()->where('firm_id', $firmC->id)->firstOrFail();
        $this->assertSame(FirmQuarterClose::SUCCEEDED, $attemptA->status);
        $this->assertSame(FirmQuarterClose::FAILED, $attemptB->status);
        $this->assertSame('decision', $attemptB->safe_error['category']);
        $this->assertSame(FirmQuarterClose::SUCCEEDED, $attemptC->status);
        $this->assertSame(1, $firmA->engineResults()->where('quarter_number', 4)->count());
        $this->assertSame(0, $firmB->engineResults()->where('quarter_number', 4)->count());
        $this->assertSame(1, $firmC->engineResults()->where('quarter_number', 4)->count());
        $this->assertSame(CourseQuarter::PROCESSING, $q4->fresh()->state);
        $this->assertSame(CourseQuarter::LOCKED, $q5->fresh()->state);

        Livewire::actingAs($course->faculty->user)
            ->test(QuarterManagement::class, ['course' => $course])
            ->assertSee('Quarter 4')
            ->assertSee($firmB->code)
            ->assertSee('Decision:')
            ->assertSee('Retry failed firms')
            ->assertSee('2')
            ->assertSee('3')
            ->assertSee('firms complete.')
            ->assertDontSee('SQLSTATE')
            ->assertDontSee('Stack trace');
        $snapshots = collect([$firmA, $firmC])->mapWithKeys(function (Firm $firm): array {
            $row = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();

            return [$firm->id => [$row->id, $row->payload_hash, $row->created_at->toJSON(), $row->updated_at->toJSON(), $row->fields]];
        })->all();
        DB::table('firm_quarter_decisions')->where('firm_id', $firmB->id)->update(['submitted_payload' => json_encode($this->completePayload(), JSON_THROW_ON_ERROR)]);
        $closer->close($attemptB->fresh());
        $quarters->finalize($q4);

        foreach ([$firmA, $firmC] as $firm) {
            $row = $firm->engineResults()->where('quarter_number', 4)->firstOrFail();
            $this->assertSame($snapshots[$firm->id], [$row->id, $row->payload_hash, $row->created_at->toJSON(), $row->updated_at->toJSON(), $row->fields]);
        }
        $this->assertSame(1, $firmB->engineResults()->where('quarter_number', 4)->count());
        $this->assertSame(3, FirmQuarterClose::query()->where('status', FirmQuarterClose::SUCCEEDED)->count());
        $this->assertSame(CourseQuarter::CLOSED, $q4->fresh()->state);
        $quarters->open($course->faculty, $q5);
        $this->assertSame(CourseQuarter::OPEN, $q5->fresh()->state);
    }

    #[DataProvider('invalidEngineProvider')]
    public function test_accounting_and_plausibility_failures_store_no_result_and_expose_safe_category(string $mode, string $category): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse("{$mode} failure");
        $this->app->instance(ManufacturingEngine::class, new MutatingEngine($mode));

        $quarters = app(QuarterManagementService::class);

        $quarters->open($course->faculty, $q4);

        app(FirmDecisionService::class)->submit(
            $student,
            $firm,
            $q4,
            $this->completePayload(),
            0
        );

        Queue::fake();

        $quarters->queueClose($course->faculty, $q4);

        app(FirmQuarterCloseService::class)->close(
            FirmQuarterClose::query()->firstOrFail()
        );

        $attempt = FirmQuarterClose::query()->firstOrFail();

        if ($mode === 'plausibility') {
            $this->assertSame(FirmQuarterClose::SUCCEEDED, $attempt->status);
            $this->assertNull($attempt->safe_error);

            $this->assertSame(
                1,
                $firm->engineResults()->where('quarter_number', 4)->count()
            );

            $this->assertSame(
                'plausibility',
                $attempt->diagnostic_detail['type']
            );

            $violations = $attempt->diagnostic_detail['operating_outcome_violations'];

            $this->assertNotEmpty($violations);

            $this->assertSame(
                'receivables_to_revenue_ratio',
                $violations[0]['rule']
            );

            Livewire::actingAs($course->faculty->user)
                ->test(QuarterManagement::class, ['course' => $course])
                ->assertSee($firm->code)
                ->assertSee('Plausibility:')
                ->assertSee('receivables_to_revenue_ratio');

            $this->assertSame(
                CourseQuarter::PROCESSING,
                $q4->fresh()->state
            );
        } else {
            $this->assertSame(FirmQuarterClose::FAILED, $attempt->status);
            $this->assertSame($category, $attempt->safe_error['category']);

            $this->assertStringNotContainsString(
                'SQL',
                $attempt->safe_error['message']
            );

            $this->assertSame(
                0,
                $firm->engineResults()->where('quarter_number', 4)->count()
            );

            $this->assertSame(
                CourseQuarter::PROCESSING,
                $q4->fresh()->state
            );
        }
    }

    /** @return array<string, array{string, string}> */
    public static function invalidEngineProvider(): array
    {
        return ['accounting' => ['accounting', 'accounting'], 'plausibility' => ['plausibility', 'plausibility'], 'system' => ['system', 'system']];
    }

    /** @return array{Firm, Student} */
    private function additionalFirm($course, string $code, int $studentOffset): array
    {
        $firm = Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id, 'code' => $code, 'name' => "Firm {$code}"]);
        $student = Student::query()->skip($studentOffset)->firstOrFail();
        FirmMembership::query()->create(['firm_id' => $firm->id, 'course_id' => $course->id, 'student_id' => $student->id, 'institution_id' => $course->institution_id, 'assigned_by_faculty_id' => $course->faculty_id, 'assigned_at' => now()]);
        app(InheritedWarmupHistoryService::class)->initialize($firm);

        return [$firm, $student];
    }
}

class MutatingEngine implements ManufacturingEngine
{
    public function __construct(private readonly string $mode) {}

    public function calculate(ManufacturingEngineInput $input): ManufacturingEngineResult
    {
        if ($this->mode === 'system') {
            throw new \RuntimeException('Synthetic worker interruption.');
        }
        $result = (new ManufacturingCFEngine)->calculate($input);
        $fields = $result->fields;
        if ($this->mode === 'accounting') {
            $fields['bs_cash'] += 1;
        }
        if ($this->mode === 'plausibility') {
            $fields['bs_ar'] += 100000000;
            $fields['bs_ap'] += 100000000;
        }

        return new ManufacturingEngineResult($fields, $result->incomeStatement, $result->balanceSheet, $result->cashFlowStatement, $result->notes, $result->intermediates);
    }
}
