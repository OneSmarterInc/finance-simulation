<?php

namespace Tests\Feature\Interpretations;

use App\Livewire\Faculty\Courses\TeamExplanations;
use App\Livewire\Student\CfPlusEvaluation;
use App\Models\Course;
use App\Models\CourseQuarter;
use App\Models\Firm;
use App\Models\FirmRivalAnalysis;
use App\Models\Student;
use App\Services\Ai\AskClaudeTools;
use App\Services\CfPlus\CfPlusAuctionSettlementService;
use App\Services\CfPlus\CfPlusOfferService;
use App\Services\Interpretations\RivalAnalysisService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Support\CfPlus\BuildsCfPlusMarket;
use Tests\TestCase;

/**
 * U11 layer 3 (RA1 to RA3, decided 19 October 2026): on + courses, a team
 * submits its analysis of a rival before it can make a positive offer for
 * it; faculty read the text only after settlement.
 */
class RivalAnalysisTest extends TestCase
{
    use BuildsCfPlusMarket, RefreshDatabase;

    private const ANSWERS = [
        'matters' => 'Bravo earns steady operating income, but its warranty reserve is thin for the defects its supplier ships.',
        'misses' => 'The consultant capitalises reported income, so it ignores the warranty claims still to come from the units already sold.',
        'worth' => 'We start from the consultant figure and take off the warranty claims we expect, which gives our figure below.',
    ];

    private Course $course;

    /** @var array<string, Firm> */
    private array $firms;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        [$this->course, $this->firms, $this->student] = $this->cfPlusMarket();
        $this->course->forceFill(['interpretations_required' => true])->save();
    }

    public function test_a_positive_offer_waits_for_the_analysis_and_a_zero_offer_does_not(): void
    {
        $offers = app(CfPlusOfferService::class);
        $analyses = app(RivalAnalysisService::class);
        [$alpha, $bravo] = [$this->firms['alpha']->fresh(), $this->firms['bravo']->fresh()];

        try {
            $offers->submit($this->course, $alpha, $bravo, 1000000);
            $this->fail('A positive offer must wait for the analysis.');
        } catch (ValidationException $e) {
            $this->assertSame("Submit your team's analysis of Bravo first. Then you can save a positive offer for it. A \$0 offer needs no analysis.", $e->errors()['offer'][0]);
        }
        $this->assertSame(0.0, (float) $offers->submit($this->course, $alpha, $bravo, 0)->offered_amount, 'a $0 offer needs no analysis');

        $analyses->saveDraft($this->student, $alpha, $bravo, ['matters' => 'Steady income.'], '');
        $this->assertFalse($analyses->satisfied($alpha, $bravo), 'a draft is not enough');
        try {
            $analyses->submit($this->student, $alpha, $bravo, ['matters' => 'Steady income.'], '');
            $this->fail('Short answers and a missing figure must be refused.');
        } catch (ValidationException $e) {
            $this->assertSame(['analysis.matters', 'analysis.misses', 'analysis.worth', 'analysis.estimate'], array_keys($e->errors()));
        }

        $row = $analyses->submit($this->student, $alpha, $bravo, self::ANSWERS, '41,500,000');
        $this->assertTrue($row->isSubmitted());
        $this->assertSame(41500000.0, $row->estimated_stake_value);
        $this->assertSame(1000000.0, (float) $offers->submit($this->course, $alpha, $bravo, 1000000)->offered_amount);
        $this->assertFalse($analyses->satisfied($alpha, $this->firms['charlie']), 'each rival needs its own analysis');

        // Switched off, or in a course without the evaluation stage, nothing is asked.
        $this->course->forceFill(['interpretations_required' => false])->save();
        $this->assertSame(2000000.0, (float) $offers->submit($this->course->fresh(), $alpha->fresh(), $this->firms['charlie'], 2000000)->offered_amount);
    }

    public function test_the_evaluation_page_asks_for_the_analysis_in_the_rivals_card(): void
    {
        $bravo = $this->firms['bravo'];
        $this->actingAs($this->student->user);
        $page = Livewire::test(CfPlusEvaluation::class, ['firm' => $this->firms['alpha']])
            ->assertSee("Your team's analysis of Bravo", false)
            ->assertSee('Needed before a positive offer')
            ->assertSee('data-test="offer-needs-analysis"', false)
            ->set("offerAmounts.{$bravo->id}", '1000000')
            ->call('saveOffer', $bravo->id)
            ->assertHasErrors("offerAmounts.{$bravo->id}")
            ->set("analysis.{$bravo->id}.matters", self::ANSWERS['matters'])
            ->call('submitAnalysis', $bravo->id)
            ->assertHasErrors(["analysis.{$bravo->id}.misses", "analysis.{$bravo->id}.estimate"]);

        foreach (self::ANSWERS as $key => $text) {
            $page->set("analysis.{$bravo->id}.{$key}", $text);
        }
        $page->set("analysis.{$bravo->id}.estimate", '41500000')
            ->call('submitAnalysis', $bravo->id)
            ->assertHasNoErrors()
            ->assertSee('You can now save a positive offer for it.')
            ->assertSee('Submitted by '.$this->student->user->name)
            ->call('saveOffer', $bravo->id)
            ->assertHasNoErrors()
            ->assertSee('Sealed offer saved.');
    }

    public function test_faculty_see_who_analysed_whom_until_settlement_and_then_the_text_beside_the_outcome(): void
    {
        [$alpha, $bravo] = [$this->firms['alpha'], $this->firms['bravo']];
        app(RivalAnalysisService::class)->submit($this->student, $alpha, $bravo, self::ANSWERS, '41500000');
        app(CfPlusOfferService::class)->submit($this->course, $alpha, $bravo, 1000000);
        $faculty = $this->course->faculty;
        $this->actingAs($faculty->user);

        Livewire::test(TeamExplanations::class, ['course' => $this->course])
            ->call('showRivals')
            ->assertSee('data-test="rival-analyses-sealed"', false)
            ->assertSee('Bravo')
            ->assertSee('The text opens here when you settle the evaluation stage.')
            ->assertDontSee(self::ANSWERS['misses']);
        $sealed = (new AskClaudeTools($this->course))->run('get_rival_analyses', []);
        $this->assertFalse($sealed['settled']);
        $this->assertArrayNotHasKey('answers', $sealed['analyses'][0]);
        $this->assertStringStartsWith('submitted by Student 1', $sealed['analyses'][0]['status']);
        $this->assertStringNotContainsString(self::ANSWERS['misses'], $this->downloadSheet(2));
        $this->assertStringContainsString('Shown after settlement', $this->downloadSheet(2));
        $this->expectsCommentRefusedBeforeSettlement();

        $this->course->quarters()->update(['state' => CourseQuarter::CLOSED]);
        app(CfPlusAuctionSettlementService::class)->settle($faculty, $this->course);

        Livewire::test(TeamExplanations::class, ['course' => $this->course->fresh()])
            ->call('showRivals')
            ->assertSee('Alpha on Bravo')
            ->assertSee(self::ANSWERS['misses'])
            ->assertSee('$41,500,000.00')
            ->assertSee('True worth of 51%')
            ->set('rivalComments.'.FirmRivalAnalysis::query()->value('id'), 'Good catch on the warranty reserve.')
            ->call('saveRivalComment', FirmRivalAnalysis::query()->value('id'))
            ->assertSee('Alpha sees it on its evaluation page');
        $this->assertStringContainsString(htmlspecialchars(self::ANSWERS['misses'], ENT_XML1 | ENT_QUOTES), $this->downloadSheet(2));

        $tools = new AskClaudeTools($this->course->fresh());
        $open = $tools->run('get_rival_analyses', []);
        $this->assertSame(self::ANSWERS['matters'], $open['analyses'][0]['answers']['What matters most?']);
        $this->assertContains('$41,500,000.00', $tools->quotable(), 'the team\'s figure can be quoted, never stated as fact');
        $this->assertArrayNotHasKey('teams_figure_for_51_percent', AskClaudeTools::factual('get_rival_analyses', $open)['analyses'][0]);

        $this->actingAs($this->student->user);
        Livewire::test(CfPlusEvaluation::class, ['firm' => $alpha])
            ->assertSee("Your team's figure for 51%", false)
            ->assertSee('$41,500,000.00')
            ->assertSee('Good catch on the warranty reserve.');
    }

    private function expectsCommentRefusedBeforeSettlement(): void
    {
        try {
            app(RivalAnalysisService::class)->comment($this->course->faculty, FirmRivalAnalysis::query()->firstOrFail(), 'Too early.');
            $this->fail('Comments wait for settlement.');
        } catch (ValidationException $e) {
            $this->assertSame('Rival analyses open to you once you settle the evaluation stage.', $e->errors()['comment'][0]);
        }
    }

    private function downloadSheet(int $n): string
    {
        $response = $this->actingAs($this->course->faculty->user)->get(route('faculty.courses.explanations.download', $this->course))->assertOk();
        $zip = new \ZipArchive;
        $zip->open($response->getFile()->getPathname());
        $sheet = (string) $zip->getFromName("xl/worksheets/sheet{$n}.xml");
        $zip->close();

        return $sheet;
    }
}
