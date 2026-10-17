<?php

namespace App\Livewire\Admin;

use App\Models\Faculty as FacultyModel;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Faculty extends Component
{
    public function render(): View
    {
        return view('livewire.admin.faculty', [
            'facultyMembers' => FacultyModel::query()
                ->with(['user', 'institution'])
                ->withCount('courses')
                ->orderBy('id')
                ->get(),
        ]);
    }
}