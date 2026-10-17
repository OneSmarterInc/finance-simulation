<?php

namespace App\Livewire\Admin;

use App\Models\Course;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Courses extends Component
{
    public function render(): View
    {
        return view('livewire.admin.courses', [
            'courses' => Course::query()
                ->with(['institution', 'faculty.user', 'scenario'])
                ->withCount(['quarters', 'firms'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}