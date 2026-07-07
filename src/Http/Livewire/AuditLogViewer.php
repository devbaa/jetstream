<?php

declare(strict_types=1);

namespace Laravel\Jetstream\Http\Livewire;

use Illuminate\Support\Facades\Gate;
use Laravel\Jetstream\Jetstream;
use Livewire\Component;

/**
 * @property-read \App\Models\User $user
 */
class AuditLogViewer extends Component
{
    /**
     * The tenant whose audit log is being viewed, if any.
     *
     * When no tenant is given, the viewer shows the application-wide audit
     * log and requires the viewer to be a system administrator.
     *
     * @var \Laravel\Jetstream\Tenant|null
     */
    public $tenant = null;

    /**
     * The number of log entries that are displayed.
     *
     * @var int
     */
    public $perPage = 25;

    /**
     * Mount the component.
     *
     * @param  \Laravel\Jetstream\Tenant|null  $tenant
     * @return void
     */
    public function mount($tenant = null)
    {
        $this->tenant = $tenant;

        if ($tenant !== null) {
            Gate::forUser($this->user)->authorize('update', $tenant);
        } else {
            abort_unless($this->user->isSystemAdmin(), 403);
        }
    }

    /**
     * Display more log entries.
     *
     * @return void
     */
    public function loadMore()
    {
        $this->perPage += 25;
    }

    /**
     * Get the current user of the application.
     *
     * @return mixed
     */
    public function getUserProperty()
    {
        return Jetstream::currentUser();
    }

    /**
     * Get the audit log entries that should be displayed.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Laravel\Jetstream\AuditLog>
     */
    public function getLogsProperty()
    {
        $query = Jetstream::newAuditLogModel()->newQuery()->with('user');

        if ($this->tenant !== null) {
            $query->where('tenant_id', $this->tenant->id);
        }

        return $query->latest('id')->limit(max(1, $this->perPage))->get();
    }

    /**
     * Determine if more log entries are available.
     *
     * @return bool
     */
    public function getHasMoreProperty()
    {
        $query = Jetstream::newAuditLogModel()->newQuery();

        if ($this->tenant !== null) {
            $query->where('tenant_id', $this->tenant->id);
        }

        return $query->count() > $this->perPage;
    }

    /**
     * Render the component.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        return view('audit.log-viewer');
    }
}
