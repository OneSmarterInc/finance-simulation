<?php

namespace App\Livewire\Admin;

use App\Models\ReportingCall;
use App\Models\ReportingConnection;
use App\Services\Reporting\ReportingOAuth;
use App\Services\Reporting\ReportingViews;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * U3 (RP3): the reporting connector for admins. Its address and how to add
 * it in Claude, what each view shares (with the rows Claude would get), and
 * every connection, with Revoke.
 */
#[Layout('layouts.admin')]
class Reporting extends Component
{
    public ?string $showing = null;

    public ?string $confirming = null;

    public string $statusMessage = '';

    public function show(string $tool): void
    {
        abort_unless(array_key_exists($tool, ReportingViews::tools()), 404);
        $this->showing = $this->showing === $tool ? null : $tool;
    }

    public function confirmRevoke(string $id): void
    {
        $this->confirming = $id;
    }

    public function revoke(ReportingOAuth $oauth): void
    {
        $connection = ReportingConnection::query()->with('user')->findOrFail($this->confirming);
        $oauth->revoke($connection, auth()->user());
        $this->confirming = null;
        $this->statusMessage = "{$connection->user?->name}'s connection has been revoked. Claude can no longer read the reports with it.";
    }

    public function render(ReportingViews $views): View
    {
        $counts = [];
        foreach (array_keys(ReportingViews::tools()) as $tool) {
            $counts[$tool] = count($views->run($tool));
        }
        $calls = ReportingCall::query()->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('connection_id, count(*) as n')->groupBy('connection_id')->pluck('n', 'connection_id');

        return view('livewire.admin.reporting', [
            'endpoint' => ReportingOAuth::resource(),
            'tools' => ReportingViews::tools(),
            'counts' => $counts,
            'rows' => $this->showing ? $views->run($this->showing) : [],
            'connections' => ReportingConnection::query()->with(['user', 'client', 'revoker'])->latest()->get(),
            'calls' => $calls,
            'enabled' => (bool) config('flexee.reporting.enabled'),
        ]);
    }
}
