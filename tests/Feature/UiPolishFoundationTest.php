<?php

namespace Tests\Feature;

use Tests\TestCase;

class UiPolishFoundationTest extends TestCase
{
    public function test_flexee_brand_replaces_starter_kit_identity_in_the_application_shell(): void
    {
        $shell = file_get_contents(resource_path('views/layouts/app/sidebar.blade.php'));
        $brand = file_get_contents(resource_path('views/components/app-logo.blade.php'));

        $this->assertStringContainsString(':name="\App\Support\Product::NAME"', $brand);
        $this->assertStringNotContainsString('Laravel Starter Kit', $brand);
        $this->assertStringNotContainsString('livewire-starter-kit', $shell);
        $this->assertStringNotContainsString('laravel.com/docs', $shell);
        $this->assertStringNotContainsString('class="dark"', $shell);
    }

    public function test_representative_screens_use_shared_presentation_primitives_without_changing_actions(): void
    {
        $faculty = file_get_contents(resource_path('views/livewire/faculty/courses/quarter-management.blade.php'));
        $student = file_get_contents(resource_path('views/livewire/student/cf-plus-evaluation.blade.php'));

        $this->assertStringContainsString('<x-page-header', $faculty);
        $this->assertStringContainsString('<flux:card', $faculty);
        $this->assertStringContainsString('wire:click="open(', $faculty);
        $this->assertStringContainsString('wire:click="close(', $faculty);
        $this->assertStringContainsString('<x-page-header', $student);
        $this->assertStringContainsString('<x-financial-array', $student);
        $this->assertStringContainsString('wire:click="saveOffer(', $student);
        $this->assertStringNotContainsString('json_encode', $student);
    }

    public function test_design_tokens_keep_emerald_as_the_semantic_accent(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('--color-accent: var(--color-emerald-700);', $css);
        $this->assertStringContainsString('--color-accent: var(--color-emerald-400);', $css);
        $this->assertStringContainsString('.flexee-page', $css);
    }
}
