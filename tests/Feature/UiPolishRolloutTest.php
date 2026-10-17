<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class UiPolishRolloutTest extends TestCase
{
    public function test_financial_presenter_treats_quarter_number_as_hidden_metadata(): void
    {
        $statement = [
            'quarter_number' => 3,
            'income_statement' => ['net_revenue' => 44000000],
        ];

        $rendered = Blade::render('<h2>Quarter {{ $statement[\'quarter_number\'] }}</h2><x-financial-array :values="$statement" currency />', compact('statement'));

        $this->assertSame(3, $statement['quarter_number']);
        $this->assertStringContainsString('Quarter 3', $rendered);
        $this->assertStringNotContainsString('Quarter Number', $rendered);
        $this->assertStringNotContainsString('>$3.00<', str_replace(["\r", "\n", ' '], '', $rendered));
        $this->assertStringContainsString('Net revenue', $rendered);
        $this->assertStringContainsString('$44,000,000.00', $rendered);
    }

    public function test_financial_surfaces_share_the_structured_financial_presenter(): void
    {
        foreach ([
            'livewire/inherited-history.blade.php',
            'livewire/student/firm-dashboard.blade.php',
            'livewire/student/cf-plus-evaluation.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertTrue(
                str_contains($source, '<x-financial-array') || str_contains($source, '<x-firm-report') || str_contains($source, '<x-statement'),
                $view.' must use the shared financial presenter',
            );
            $this->assertStringNotContainsString('json_encode', $source, $view);
        }
    }

    public function test_remaining_operational_screens_use_the_approved_page_header_and_flux_controls(): void
    {
        foreach ([
            'livewire/faculty/courses/course-wizard.blade.php',
            'livewire/faculty/courses/firm-manager.blade.php',
            'livewire/faculty/courses/cf-plus-auction.blade.php',
            'livewire/student/decisions/decision-workspace.blade.php',
            'livewire/inherited-history.blade.php',
            'livewire/student/firm-dashboard.blade.php',
        ] as $view) {
            $source = file_get_contents(resource_path('views/'.$view));

            $this->assertStringContainsString('<x-page-header', $source, $view);
            $this->assertStringContainsString('<flux:', $source, $view);
        }
    }

    public function test_rollout_preserves_signed_livewire_action_names(): void
    {
        $expectations = [
            'livewire/faculty/courses/course-wizard.blade.php' => ['saveSetup', 'saveSchedule', 'saveEnablements', 'saveIndustry', 'saveFacultyRoles', 'deploy'],
            'livewire/faculty/courses/firm-manager.blade.php' => ['createFirm', 'enrollAndAssign'],
            'livewire/faculty/courses/cf-plus-auction.blade.php' => ['settle', 'overrideTie'],
            'livewire/student/decisions/decision-workspace.blade.php' => ['saveDraft', 'submit'],
        ];

        foreach ($expectations as $view => $actions) {
            $source = file_get_contents(resource_path('views/'.$view));

            foreach ($actions as $action) {
                $this->assertStringContainsString($action, $source, $view.' lost '.$action);
            }
        }
    }

    public function test_dashboard_is_a_navigation_surface_without_synthetic_metrics(): void
    {
        $source = file_get_contents(resource_path('views/dashboard.blade.php'));

        $this->assertStringContainsString('Product::NAME', $source);
        $this->assertStringNotContainsString('FLEXEE', $source);
        $this->assertStringContainsString("route('faculty.courses.create')", $source);
        $this->assertStringContainsString("route('profile.edit')", $source);
        $this->assertStringNotContainsString('x-placeholder-pattern', $source);
    }
}
