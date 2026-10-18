<?php

namespace Tests\Feature\Practice;

use App\Services\Practice\ReferenceTeams;
use Tests\TestCase;

/** The PHP reference teams decide exactly as the golden fixtures record (FF3). */
class ReferenceTeamsTest extends TestCase
{
    public function test_the_reference_teams_reproduce_the_fixture_decisions(): void
    {
        $fixtures = json_decode((string) file_get_contents(base_path('economics/fixtures/v2_reference.json')), true, flags: JSON_THROW_ON_ERROR);
        $scenario = json_decode((string) file_get_contents(base_path('economics/data/scenario.json')), true, flags: JSON_THROW_ON_ERROR);
        $teams = new ReferenceTeams;
        $checked = 0;

        foreach ($fixtures['teams'] as $team => $rows) {
            foreach ($rows as $i => $row) {
                if ($row['quarter'] < 4) {
                    continue;
                }
                $decision = $teams->decide($team, $row['quarter'], $rows[$i - 1]['fields'], $scenario['parameters'], $scenario['warmup_notes_elections']);
                $open = array_intersect_key($decision['payload'], array_flip($row['student_areas']));
                $this->assertEquals($row['student_payload'], $open, "{$team} Q{$row['quarter']}");
                $this->assertEquals($row['decisions']['notes_elections'], $decision['notes'], "{$team} Q{$row['quarter']} methods");
                $checked++;
            }
        }

        $this->assertSame(24, $checked);
    }
}
