<?php

namespace Tests\Feature\Walkthrough;

use App\Services\Engines\CorporateFinance\ManufacturingCFEngine;
use App\Support\Presentation\StatementPresenter;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Phase7B\BuildsPhase7BCourse;
use Tests\TestCase;

class StatementPresenterTest extends TestCase
{
    use BuildsPhase7BCourse, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_lines_add_up_to_the_stored_totals_for_every_warmup_quarter(): void
    {
        [, $firm] = $this->phase7bCourse('Presenter tie-out');

        foreach ($firm->engineResults()->orderBy('quarter_number')->get() as $result) {
            foreach (['income_statement', 'balance_sheet', 'cash_flow_statement'] as $statement) {
                $rows = StatementPresenter::rows($statement, [$result]);
                $running = 0.0;
                foreach ($rows as $row) {
                    if ($row['type'] === 'heading') {
                        $running = 0.0;
                        continue;
                    }
                    if ($row['type'] === 'line') {
                        $running += $row['values'][0] ?? 0.0;
                        continue;
                    }
                    if ($row['type'] === 'subtotal' && $running !== 0.0) {
                        $this->assertEqualsWithDelta($row['values'][0], $running, 0.02, "Q{$result->quarter_number} {$statement}: {$row['label']}");
                    }
                    if (in_array($row['type'], ['subtotal', 'total'], true)) {
                        $running = (float) $row['values'][0];
                    }
                }
            }

            $balance = collect(StatementPresenter::rows('balance_sheet', [$result]))->keyBy('label');
            $this->assertEqualsWithDelta(
                $balance['Total assets']['values'][0],
                $balance['Total current assets']['values'][0] + $balance['Property, plant and equipment, net']['values'][0] + $balance['Right-of-use asset (leases)']['values'][0],
                0.02,
            );
            $this->assertEqualsWithDelta(
                $balance['Total liabilities']['values'][0],
                $balance['Total current liabilities']['values'][0] + $balance['Total long-term liabilities']['values'][0],
                0.02,
            );
            $this->assertEqualsWithDelta($balance['Total assets']['values'][0], $balance['Total liabilities and equity']['values'][0], 0.02);

            $cash = collect(StatementPresenter::rows('cash_flow_statement', [$result]))->keyBy('label');
            $this->assertEqualsWithDelta(
                $cash['Cash at end of quarter']['values'][0],
                $cash['Cash at beginning of quarter']['values'][0] + $cash['Net change in cash']['values'][0],
                0.02,
            );
        }
    }

    public function test_every_stored_field_has_a_label(): void
    {
        $labels = StatementPresenter::fieldLabels();
        $unlabelled = array_diff(ManufacturingCFEngine::FIELD_KEYS, array_keys($labels), ['bs_tech_net', 'is_disposal']);

        $this->assertSame([], array_values($unlabelled));
    }

    public function test_formats_follow_accounting_presentation(): void
    {
        $this->assertSame('($9,135,266.06)', StatementPresenter::format(-9135266.06, 'currency'));
        $this->assertSame('$500,000.00', StatementPresenter::format(500000, 'currency'));
        $this->assertSame('4.65x', StatementPresenter::format(4.6512, 'ratio'));
        $this->assertSame('18.1%', StatementPresenter::format(0.1805, 'percent'));
        $this->assertSame('FOB shipping point', StatementPresenter::format(1.0, 'shipping'));
    }
}
