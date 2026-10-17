<?php

namespace App\Livewire\Admin;

use App\Models\Institution;
use App\Services\Admin\AdminDirectoryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Item 4: institutions can be created and renamed. */
#[Layout('layouts.admin')]
class Institutions extends Component
{
    public string $name = '';

    public ?string $editingId = null;

    public string $editingName = '';

    public string $statusMessage = '';

    public function create(AdminDirectoryService $service): void
    {
        $institution = $service->createInstitution($this->name);
        $this->reset('name');
        $this->statusMessage = "{$institution->name} added.";
    }

    public function edit(string $id): void
    {
        $this->editingId = $id;
        $this->editingName = Institution::query()->findOrFail($id)->name;
    }

    public function rename(AdminDirectoryService $service): void
    {
        $institution = $service->renameInstitution(Institution::query()->findOrFail($this->editingId), $this->editingName);
        $this->reset('editingId', 'editingName');
        $this->statusMessage = "Renamed to {$institution->name}.";
    }

    public function render(): View
    {
        return view('livewire.admin.institutions', [
            'institutions' => Institution::query()->withCount(['faculty', 'students', 'courses'])->orderBy('name')->get(),
        ]);
    }
}
