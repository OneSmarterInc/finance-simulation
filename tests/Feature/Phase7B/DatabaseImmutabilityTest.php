<?php

namespace Tests\Feature\Phase7B;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Firm;
use App\Models\FirmEngineResult;
use App\Models\Institution;
use App\Models\Scenario;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_postgresql_rejects_direct_update_of_inherited_result(): void
    {
        $row = $this->createResult(1);
        $this->expectException(QueryException::class);
        DB::table('firm_engine_results')->where('id', $row->id)->update(['provenance' => 'tampered']);
    }

    public function test_postgresql_rejects_direct_delete_of_inherited_result(): void
    {
        $row = $this->createResult(2);
        $this->expectException(QueryException::class);
        DB::table('firm_engine_results')->where('id', $row->id)->delete();
    }

    public function test_postgresql_rejects_soft_delete_of_inherited_result(): void
    {
        $row = $this->createResult(3);
        $this->expectException(QueryException::class);
        DB::table('firm_engine_results')->where('id', $row->id)->update(['deleted_at' => now()]);
    }

    public function test_q4_and_q5_inserts_remain_allowed(): void
    {
        $q4 = $this->createResult(4);
        $q5 = $this->createResult(5, $q4->firm);
        $this->assertSame(4, $q4->quarter_number);
        $this->assertSame(5, $q5->quarter_number);
        $this->assertDatabaseCount('firm_engine_results', 2);
    }

    public function test_trigger_predicate_is_quarter_number_not_provenance(): void
    {
        $q3 = $this->createResult(3, null, 'arbitrary_source');
        $this->expectException(QueryException::class);
        DB::table('firm_engine_results')->where('id', $q3->id)->update(['payload_hash' => str_repeat('f', 64)]);
    }

    public function test_q4_is_not_trigger_protected_even_with_warmup_provenance(): void
    {
        $q4 = $this->createResult(4);
        DB::table('firm_engine_results')->where('id', $q4->id)->update(['provenance' => FirmEngineResult::PROVENANCE_WARMUP]);
        $this->assertSame(FirmEngineResult::PROVENANCE_WARMUP, $q4->fresh()->provenance);
    }

    private function createResult(int $quarter, ?Firm $firm = null, ?string $provenance = null): FirmEngineResult
    {
        $course = $firm?->course ?? Course::query()->create([
            'institution_id' => Scenario::query()->firstOrFail()->courses()->value('institution_id') ?? Institution::query()->value('id'),
            'faculty_id' => Faculty::query()->value('id'), 'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => 'Trigger test', 'starts_on' => '2026-09-01', 'ends_on' => '2027-09-01', 'status' => 'deployed',
        ]);
        $firm ??= Firm::factory()->create(['course_id' => $course->id, 'institution_id' => $course->institution_id]);
        $payload = ['firm_id' => $firm->id, 'course_id' => $course->id, 'institution_id' => $firm->institution_id, 'quarter_number' => $quarter,
            'fields' => [], 'income_statement' => [], 'balance_sheet' => [], 'cash_flow_statement' => [], 'notes' => [], 'intermediates' => [],
            'engine_contract' => FirmEngineResult::ENGINE_CONTRACT, 'provenance' => $provenance ?? ($quarter <= 3 ? FirmEngineResult::PROVENANCE_WARMUP : FirmEngineResult::PROVENANCE_STUDENT_CLOSE),
            'immutable' => true, 'payload_hash' => str_repeat((string) $quarter, 64), 'validated_at' => now()];

        return FirmEngineResult::query()->create($payload);
    }
}
