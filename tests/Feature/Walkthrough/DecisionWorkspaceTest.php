<?php

namespace Tests\Feature\Walkthrough;

use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Models\FirmQuarterDecision;
use App\Services\Quarters\QuarterManagementService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/** Walkthrough items 10, 73 to 76. */
class DecisionWorkspaceTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_save_draft_works_and_the_draft_is_shared_with_the_team(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Draft');
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->set('decisions.production.p1_production', 123456)
            ->call('saveDraft')
            ->assertHasNoErrors()
            ->assertSee('Draft saved');

        $draft = FirmQuarterDecision::query()->where('firm_id', $firm->id)->firstOrFail();
        $this->assertSame(FirmQuarterDecision::DRAFT, $draft->state);
        $this->assertSame(123456, $draft->draft_payload['production']['p1_production']);
        // The inert input is filled in, not shown (item 76).
        $this->assertSame('medium', $draft->draft_payload['forecasting']['forecast_confidence']);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSet('decisions.production.p1_production', 123456)
            ->assertDontSee('Forecast confidence');
    }

    public function test_names_replace_codes(): void
    {
        [$course, $firm, $student, $q4] = $this->phase7bCourse('Names');
        app(QuarterManagementService::class)->open($course->faculty, $q4);

        Livewire::actingAs($student->user)->test(DecisionWorkspace::class, ['firm' => $firm, 'quarter' => $q4])
            ->assertSee('Product 1 demand forecast, Northeast (units)')
            ->assertSee('China: $120 a unit, 3.0% defects, 45-day lead time')
            ->assertSee('Share of materials from the main supplier (%)')
            ->assertDontSeeText('SUP001')
            ->assertDontSee('P1 Demand Forecast');
    }
}
