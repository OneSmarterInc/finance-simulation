<?php

namespace App\Livewire\Admin;

use App\Models\Student as StudentModel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Students extends Component
{
    public function render(): View
    {
        return view('livewire.admin.students', [
            'students' => StudentModel::query()
                ->with(['user', 'institution'])
                ->withCount(['enrollments', 'firmMemberships'])
                ->orderBy('id')
                ->get(),
        ]);
    }
}