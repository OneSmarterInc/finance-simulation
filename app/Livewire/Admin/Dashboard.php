<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use App\Models\Faculty;
use App\Models\Institution;
use App\Models\Student;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Dashboard extends Component
{
    public function render(): View
    {
        return view('livewire.admin.dashboard', [
            'institutionCount' => Institution::query()->count(),
            'facultyCount' => Faculty::query()->count(),
            'studentCount' => Student::query()->count(),
            'courseCount' => Course::query()->count(),
        ]);
    }
}
