<?php

namespace Tests\Feature\Phase7A;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Scenario;
use App\Services\Warmup\CfManufacturingWarmupService;
use App\Services\Warmup\DeterministicDemandRandomizer;
use App\Services\Warmup\FirmWarmupInitializationCoordinator;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WarmupSeedDimensionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
    }

    public function test_draw_seed_preserves_course_firm_quarter_and_field_dimensions(): void
    {
        $courseA = $this->course('Seed A');
        $courseB = $this->course('Seed B');
        $seedA = FirmWarmupInitializationCoordinator::courseSeed($courseA);
        $seedB = FirmWarmupInitializationCoordinator::courseSeed($courseB);
        $randomizer = new DeterministicDemandRandomizer(0.055);
        $firm = 'synthetic-firm-001';
        $field = DeterministicDemandRandomizer::DEMAND_FIELDS[0];

        $draw = $randomizer->growthFactor($seedA, $firm, 1, $field);
        $this->assertSame($draw, $randomizer->growthFactor($seedA, $firm, 1, $field));
        $this->assertNotSame($draw, $randomizer->growthFactor($seedA, $firm, 2, $field));
        $this->assertNotSame($draw, $randomizer->growthFactor($seedA, $firm, 1, DeterministicDemandRandomizer::DEMAND_FIELDS[1]));
        $this->assertNotSame($draw, $randomizer->growthFactor($seedA, 'synthetic-firm-002', 1, $field));
        $this->assertNotSame($draw, $randomizer->growthFactor($seedB, $firm, 1, $field));
        $this->assertNotSame($seedA, $seedB);
    }

    public function test_six_field_growth_vectors_vary_by_quarter_and_repeat_exactly(): void
    {
        $course = $this->course('Vector Course');
        $seed = FirmWarmupInitializationCoordinator::courseSeed($course);
        $randomizer = new DeterministicDemandRandomizer(0.055);
        $vectors = [];
        foreach ([1, 2, 3] as $quarter) {
            foreach (DeterministicDemandRandomizer::DEMAND_FIELDS as $field) {
                $vectors[$quarter][$field] = $randomizer->growthFactor($seed, 'firm-vector', $quarter, $field);
            }
        }

        $this->assertCount(6, $vectors[1]);
        $this->assertCount(6, $vectors[2]);
        $this->assertCount(6, $vectors[3]);
        $this->assertNotSame($vectors[1], $vectors[2]);
        $this->assertNotSame($vectors[2], $vectors[3]);
        $this->assertNotSame($vectors[1], $vectors[3]);

        foreach ([1, 2, 3] as $quarter) {
            foreach (DeterministicDemandRandomizer::DEMAND_FIELDS as $field) {
                $this->assertSame($vectors[$quarter][$field], $randomizer->growthFactor($seed, 'firm-vector', $quarter, $field));
            }
        }
    }

    public function test_complete_q1_q3_generation_is_reproducible_but_quarters_differ(): void
    {
        $course = $this->course('Generation Course');
        $seed = FirmWarmupInitializationCoordinator::courseSeed($course);
        $service = app(CfManufacturingWarmupService::class);
        $first = $service->generateFromSeedHash($seed, 'firm-generation', $course->scenario);
        $second = $service->generateFromSeedHash($seed, 'firm-generation', $course->scenario);

        foreach ([1, 2, 3] as $quarter) {
            $this->assertSame($first->quarters[$quarter]->demand, $second->quarters[$quarter]->demand);
            $this->assertCount(6, $first->quarters[$quarter]->demand);
        }
        $this->assertNotSame($first->quarters[1]->demand, $first->quarters[2]->demand);
        $this->assertNotSame($first->quarters[2]->demand, $first->quarters[3]->demand);
        $this->assertNotSame($first->quarters[1]->demand, $first->quarters[3]->demand);
        $this->assertSame($seed, $first->courseSeedHash);
        $this->assertSame('firm-generation', $first->firmIdentifier);
    }

    private function course(string $name): Course
    {
        $faculty = Faculty::query()->firstOrFail();

        return Course::query()->create([
            'institution_id' => $faculty->institution_id,
            'faculty_id' => $faculty->id,
            'scenario_id' => Scenario::query()->where('code', 'cf_manufacturing')->value('id'),
            'name' => $name,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-31',
            'cf_plus_enabled' => false,
            'status' => Course::STATUS_DEPLOYED,
            'deployed_at' => now(),
        ]);
    }
}
