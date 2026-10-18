<?php

namespace Tests\Feature\Ai;

use App\Models\CfPlusAuctionResult;
use App\Models\CfPlusOffer;
use App\Models\Course;
use App\Models\Firm;
use App\Services\Ai\AskClaudeTools;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

/**
 * Ask Claude layer 3: the evaluation stage, told to Claude the way the
 * faculty page shows it. Offer counts, never amounts, until the instructor
 * settles; true worth and prices only after that.
 */
class AskClaudeEvaluationTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    private Course $course;

    private Firm $alpha;

    private Firm $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        [$this->course, $this->alpha] = $this->phase7bCourse('Plus course');
        $this->course->forceFill(['cf_plus_enabled' => true, 'build_quarters' => 1, 'adversarial_quarters' => 1, 'post_bid_quarters' => 1])->save();
        $this->alpha->forceFill(['name' => 'Alpha'])->save();
        $this->beta = Firm::factory()->create(['course_id' => $this->course->id, 'institution_id' => $this->course->institution_id, 'name' => 'Beta']);
    }

    public function test_before_settlement_claude_sees_offer_counts_but_never_amounts_or_true_worth(): void
    {
        CfPlusOffer::query()->create([
            'course_id' => $this->course->id, 'bidder_firm_id' => $this->beta->id, 'target_firm_id' => $this->alpha->id, 'institution_id' => $this->course->institution_id,
            'offered_amount' => 123456789.12, 'stake' => 0.51, 'fundable_cap' => 200000000, 'bidder_cash' => 1, 'unused_committed_credit' => 1, 'target_acquisition_financing' => 1,
            'submitted_at' => now(), 'sealed_at' => now(),
        ]);

        $stage = (new AskClaudeTools($this->course->fresh()))->run('get_evaluation_stage', []);

        $this->assertSame(5, $stage['opens_with_quarter']);
        $this->assertFalse($stage['settled']);
        $this->assertSame(['Alpha' => 1, 'Beta' => 0], collect($stage['firms'])->pluck('offers_received', 'firm')->all());
        $this->assertArrayNotHasKey('results', $stage);
        $this->assertStringNotContainsString('123,456,789', json_encode($stage));
        $this->assertStringNotContainsString('123456789', json_encode($stage));
    }

    public function test_after_settlement_claude_sees_who_won_the_price_and_the_true_worth(): void
    {
        CfPlusAuctionResult::query()->create([
            'course_id' => $this->course->id, 'target_firm_id' => $this->alpha->id, 'institution_id' => $this->course->institution_id,
            'winning_bidder_firm_id' => $this->beta->id, 'price_paid' => 294700000, 'stake' => 0.51,
            'full_consultant_value' => 462300000, 'consultant_stake_value' => 235773000, 'raw_full_true_worth' => 423800000,
            'displayed_full_true_worth' => 423800000, 'true_stake_worth' => 216138000, 'tied_top_bidder_ids' => [], 'settled_at' => now(),
        ]);

        $stage = (new AskClaudeTools($this->course->fresh()))->run('get_evaluation_stage', []);

        $this->assertTrue($stage['settled']);
        $this->assertSame([
            'target' => 'Alpha', 'won_by' => 'Beta', 'price_paid' => '$294,700,000.00', 'stake' => '51%',
            'consultant_value_of_stake' => '$235,773,000.00', 'true_worth_of_stake' => '$216,138,000.00',
            'paid_above_true_worth' => '$78,562,000.00', 'tie_settled_by_instructor' => false,
        ], $stage['results'][0]);
    }

    public function test_a_course_without_the_evaluation_stage_says_so(): void
    {
        $this->course->forceFill(['cf_plus_enabled' => false])->save();

        $this->assertSame('none', (new AskClaudeTools($this->course->fresh()))->run('get_evaluation_stage', [])['evaluation_stage']);
    }
}
