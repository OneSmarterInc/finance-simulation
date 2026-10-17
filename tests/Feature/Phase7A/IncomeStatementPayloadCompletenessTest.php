<?php

namespace Tests\Feature\Phase7A;

use App\Models\FirmEngineResult;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class IncomeStatementPayloadCompletenessTest extends TestCase
{
    use BuildsPhase7BCourse;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_inherited_and_later_persisted_quarters_share_the_complete_income_statement_shape(): void
    {
        [, $firm] = $this->phase7bCourse('Corrective 2 Persistence');
        $q3 = $firm->engineResults()->where('quarter_number', 3)->firstOrFail();
        foreach ([4, 6] as $quarter) {
            $payload = $q3->only(['firm_id', 'course_id', 'institution_id', 'fields', 'income_statement', 'balance_sheet', 'cash_flow_statement', 'notes', 'intermediates', 'engine_contract']);
            $payload += ['quarter_number' => $quarter, 'provenance' => FirmEngineResult::PROVENANCE_STUDENT_CLOSE, 'immutable' => true, 'validated_at' => now()];
            FirmEngineResult::query()->create($payload + ['payload_hash' => hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION))]);
        }

        $results = $firm->engineResults()->whereIn('quarter_number', [1, 2, 3, 4, 6])->orderBy('quarter_number')->get();
        $this->assertSame([1, 2, 3, 4, 6], $results->pluck('quarter_number')->all());
        foreach ($results as $result) {
            $this->assertArrayHasKey('interest_expense', $result->income_statement, "Q{$result->quarter_number}");
            $this->assertArrayHasKey('income_before_income_taxes', $result->income_statement, "Q{$result->quarter_number}");
            $this->assertArrayHasKey('income_tax_expense', $result->income_statement, "Q{$result->quarter_number}");
        }
    }
}
