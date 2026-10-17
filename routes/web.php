<?php

use App\Http\Controllers\TestController;
use App\Livewire\Faculty\Courses\AccountingElectionRequests;
use App\Livewire\Faculty\Dashboard as FacultyDashboard;
use App\Livewire\Faculty\Courses\CfPlusAuction;
use App\Livewire\Faculty\Courses\CourseWizard;
use App\Livewire\Faculty\Courses\FirmManager;
use App\Livewire\Faculty\Courses\QuarterManagement;
use App\Livewire\Faculty\Courses\QuarterResults;
use App\Livewire\InheritedHistory;
use App\Livewire\Student\AccountingElection;
use App\Livewire\Student\CfPlusEvaluation;
use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Livewire\Student\FirmDashboard;
use App\Livewire\Student\Courses;
use App\Livewire\Student\CourseRegistration;
use App\Livewire\Faculty\Courses\Students;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $databaseAvailable = true;

    try {
        DB::select('select 1');
    } catch (Throwable) {
        $databaseAvailable = false;
    }

    return view('welcome', [
        'databaseAvailable' => $databaseAvailable,
        'laravelVersion' => Application::VERSION,
    ]);
})->name('home');

 Route::get('register/course/{token}', CourseRegistration::class)
    ->name('student.course-registration');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', FacultyDashboard::class)->name('dashboard');

    Route::get('admin/dashboard', \App\Livewire\Admin\Dashboard::class)
        ->middleware('admin')
        ->name('admin.dashboard');

    Route::get('admin/institutions', \App\Livewire\Admin\Institutions::class)
        ->middleware('admin')
        ->name('admin.institutions');

    Route::get('admin/faculty', \App\Livewire\Admin\Faculty::class)
        ->middleware('admin')
        ->name('admin.faculty');

    Route::get('admin/students', \App\Livewire\Admin\Students::class)
        ->middleware('admin')
        ->name('admin.students');

    Route::get('admin/courses', \App\Livewire\Admin\Courses::class)
        ->middleware('admin')->name('admin.courses');

    Route::get('admin/courses/create', \App\Livewire\Admin\CoursePreparation::class)
        ->middleware('admin')->name('admin.courses.create');

    Route::get('admin/courses/{course}/prepare', \App\Livewire\Admin\CoursePreparation::class)
        ->middleware('admin')->name('admin.courses.prepare');

    Route::get('faculty/courses/create', CourseWizard::class)
        ->name('faculty.courses.create');

    Route::get('faculty/courses/{course}/wizard', CourseWizard::class)
        ->name('faculty.courses.wizard');

    Route::get('faculty/courses/{course}/firms', FirmManager::class)
        ->name('faculty.courses.firms');

    Route::get('faculty/courses/{course}/students', Students::class)
        ->name('faculty.courses.students');

    Route::get('faculty/courses/{course}/accounting-election-requests', AccountingElectionRequests::class)
        ->name('faculty.courses.accounting-election-requests');

    Route::get('faculty/courses/{course}/quarters', QuarterManagement::class)
        ->name('faculty.courses.quarters');

    Route::get('faculty/courses/{course}/quarter-results', QuarterResults::class)
        ->name('faculty.courses.quarter-results');


    Route::get('firms/{firm}/quarters/{quarter}/decisions', DecisionWorkspace::class)
        ->name('student.decisions.workspace');

    Route::get('firms/{firm}/quarters/{quarter}/accounting-election', AccountingElection::class)
        ->name('student.accounting-election');

    Route::get('firms/{firm}/history', InheritedHistory::class)
        ->name('firms.history');

    Route::get('firms/{firm}/dashboard', FirmDashboard::class)
        ->name('firms.dashboard');

    Route::get('firms/{firm}/cf-plus', CfPlusEvaluation::class)
        ->name('student.cf-plus.evaluation');

    Route::get('faculty/courses/{course}/cf-plus-auction', CfPlusAuction::class)
        ->name('faculty.courses.cf-plus-auction');

    Route::get('student/courses', Courses::class)
        ->name('student.courses');
});

Route::get('/testMail', [TestController::class, 'testMail'])->name('testMail');


require __DIR__ . '/settings.php';
