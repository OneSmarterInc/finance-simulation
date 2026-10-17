<?php

namespace App\Livewire\Admin;

use App\Models\Faculty as FacultyModel;
use App\Models\Institution;
use App\Services\Admin\AdminDirectoryService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Item 5: add faculty to an institution, or move one who has no courses yet. */
#[Layout('layouts.admin')]
class Faculty extends Component
{
    public string $name = '';

    public string $email = '';

    public string $institutionId = '';

    /** @var array<string, string> */
    public array $moveTo = [];

    public string $statusMessage = '';

    public function create(AdminDirectoryService $service): void
    {
        ['faculty' => $faculty, 'emailed' => $emailed] = $service->createFaculty($this->name, $this->email, $this->institutionId);
        $this->reset('name', 'email', 'institutionId');
        $this->statusMessage = $faculty->user->name.' added. '.($emailed
            ? 'An email with a link to set a password is on its way.'
            : 'Ask them to use "Forgot password" on the sign-in page to set a password.');
    }

    public function move(string $facultyId, AdminDirectoryService $service): void
    {
        if (blank($this->moveTo[$facultyId] ?? null)) {
            $this->addError('moveTo', 'Choose the institution to move them to.');

            return;
        }
        $faculty = FacultyModel::query()->with('user')->findOrFail($facultyId);
        $service->moveFaculty($faculty, $this->moveTo[$facultyId] ?? '');
        unset($this->moveTo[$facultyId]);
        $this->statusMessage = $faculty->user->name.' moved.';
    }

    public function render(): View
    {
        return view('livewire.admin.faculty', [
            'facultyMembers' => FacultyModel::query()->with(['user', 'institution'])->withCount('courses')->get()
                ->sortBy(fn ($f) => strtolower((string) $f->user?->name))->values(),
            'institutions' => Institution::query()->orderBy('name')->get(),
        ]);
    }
}
