<?php

namespace Laravel\Jetstream\Http\Controllers\Livewire;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Laravel\Jetstream\Contracts\CreatesCustomerAccounts;
use Laravel\Jetstream\Features;
use Laravel\Jetstream\Jetstream;

class CustomerRegistrationController extends Controller
{
    /**
     * Show the customer self-registration screen for the given tenant.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $slug
     * @return \Illuminate\View\View
     */
    public function show(Request $request, $slug)
    {
        $tenant = $this->resolveTenant($slug);

        return view('portal.register', [
            'user' => $request->user(),
            'tenant' => $tenant,
            'alreadyCustomer' => $request->user()?->isCustomerOf($tenant) ?? false,
        ]);
    }

    /**
     * Register the authenticated user as a customer of the given tenant.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  string  $slug
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request, $slug)
    {
        $tenant = $this->resolveTenant($slug);

        $user = $request->user();

        if (! $user) {
            return redirect()->guest(route('login'));
        }

        if (! $user->isCustomerOf($tenant)) {
            $account = app(CreatesCustomerAccounts::class)->create(
                $tenant, $user, ['name' => $user->name]
            );

            $user->switchCustomerAccount($account);
        }

        return redirect()->route('portal.show');
    }

    /**
     * Resolve a tenant that allows customer self-registration by its slug.
     *
     * @param  string  $slug
     * @return mixed
     */
    protected function resolveTenant($slug)
    {
        abort_unless(Features::allowsCustomerRegistration(), 404);

        $tenant = Jetstream::newTenantModel()
            ->where('slug', $slug)
            ->firstOrFail();

        abort_unless($tenant->allow_customer_registration, 404);

        return $tenant;
    }
}
