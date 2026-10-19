<?php

use App\Livewire\Faculty\Courses\AccountingElectionRequests;
use App\Livewire\Faculty\Dashboard as FacultyDashboard;
use App\Livewire\Faculty\Courses\CfPlusAuction;
use App\Livewire\Faculty\Courses\CourseWizard;
use App\Livewire\Faculty\Courses\FirmManager;
use App\Livewire\Faculty\Courses\QuarterManagement;
use App\Http\Controllers\Practice\PracticeViewController;
use App\Livewire\Faculty\Courses\PracticeRun;
use App\Livewire\Faculty\Courses\QuarterResults;
use App\Livewire\InheritedHistory;
use App\Livewire\Student\AccountingElection;
use App\Livewire\Student\CfPlusEvaluation;
use App\Livewire\Student\Decisions\DecisionWorkspace;
use App\Livewire\Student\FirmDashboard;
use App\Livewire\Student\Courses;
use App\Livewire\Student\CourseRegistration;
use App\Livewire\Faculty\Courses\Students;
use Illuminate\Support\Facades\Route;

Route::view('/', 'welcome')->name('home');

// Live walkthrough L8: the public tour for faculty thinking of adopting Flexee.
Route::get('tour/{step?}', \App\Http\Controllers\TourController::class)
    ->whereNumber('step')->name('tour');

 Route::get('register/course/{token}', CourseRegistration::class)
    ->name('student.course-registration');

// U3 (RP3): connecting the reporting connector in Claude. Only admins can approve.
Route::middleware(['auth', 'reporting.enabled'])->group(function () {
    Route::get('oauth/reporting/authorize', [\App\Http\Controllers\Reporting\ReportingConsentController::class, 'show'])->name('reporting.oauth.authorize');
    Route::post('oauth/reporting/authorize', [\App\Http\Controllers\Reporting\ReportingConsentController::class, 'decide'])->middleware('throttle:30,1')->name('reporting.oauth.decide');
});

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

    Route::get('admin/reporting', \App\Livewire\Admin\Reporting::class)
        ->middleware('admin')->name('admin.reporting');

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

    Route::get('faculty/courses/{course}/quarter-results/download', \App\Http\Controllers\Faculty\ResultsDownloadController::class)
        ->name('faculty.courses.quarter-results.download');

    // U11: the teams' explanations of each quarter.
    Route::get('faculty/courses/{course}/explanations', \App\Livewire\Faculty\Courses\TeamExplanations::class)
        ->name('faculty.courses.explanations');

    Route::get('faculty/courses/{course}/explanations/download', \App\Http\Controllers\Faculty\ExplanationsDownloadController::class)
        ->name('faculty.courses.explanations.download');

    // U4: the class debrief, presented in class; shared with students after the course.
    Route::get('faculty/courses/{course}/debrief', \App\Livewire\Faculty\Courses\Debrief::class)
        ->name('faculty.courses.debrief');

    Route::get('firms/{firm}/debrief', \App\Livewire\Student\CourseDebrief::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('firms.debrief');

    Route::get('faculty/courses/{course}/practice', PracticeRun::class)
        ->name('faculty.courses.practice');

    Route::post('faculty/courses/{course}/practice/view-as/{team}', [PracticeViewController::class, 'viewAs'])
        ->whereIn('team', \App\Services\Practice\ReferenceTeams::TEAMS)
        ->name('faculty.courses.practice.view-as');

    Route::post('practice/back', [PracticeViewController::class, 'back'])
        ->name('practice.back');


    Route::get('firms/{firm}/quarters/{quarter}/decisions', DecisionWorkspace::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('student.decisions.workspace');

    Route::get('firms/{firm}/quarters/{quarter}/accounting-election', AccountingElection::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('student.accounting-election');

    Route::get('firms/{firm}/history', InheritedHistory::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('firms.history');

    Route::get('firms/{firm}/dashboard', FirmDashboard::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('firms.dashboard');

    Route::get('firms/{firm}/cf-plus', CfPlusEvaluation::class)
        ->middleware(\App\Http\Middleware\EnsureStudentAccessConfirmed::class)
        ->name('student.cf-plus.evaluation');

    Route::get('faculty/courses/{course}/cf-plus-auction', CfPlusAuction::class)
        ->name('faculty.courses.cf-plus-auction');

    Route::get('student/courses', Courses::class)
        ->name('student.courses');

    Route::get('guide', \App\Livewire\Guide::class)
        ->name('guide');
});



require __DIR__ . '/settings.php';
