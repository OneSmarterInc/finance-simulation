<?php

namespace App\Livewire\Admin;

use App\Models\Institution;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.admin')]
class Institutions extends Component
{
    public function render(): View
    {
        return view('livewire.admin.institutions', [
            'institutions' => Institution::query()
                ->withCount(['faculty', 'students', 'courses'])
                ->orderBy('name')
                ->get(),
        ]);
    }
}
