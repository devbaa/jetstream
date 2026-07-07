<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 dark:text-gray-200 leading-tight">
            {{ __('Organization Help') }}
        </h2>
    </x-slot>

    <div>
        <div class="max-w-4xl mx-auto py-10 sm:px-6 lg:px-8 space-y-6">
            <p class="px-4 sm:px-0 text-gray-600 dark:text-gray-400">
                {{ __('How organizations, staff, roles, sub-teams, and customers fit together — and the tools you have to keep everything running smoothly.') }}
            </p>

            <!-- Organizations / tenants -->
            <x-help-topic :title="__('Organizations')" icon="building">
                <p>{{ __('An organization (also called a tenant) is your company\'s workspace. It has one owner, staff members, roles, sub-teams, and — if enabled — its own customers.') }}</p>
                <ul>
                    <li>{{ __('Switch between organizations you belong to using the switcher in the top navigation.') }}</li>
                    <li>{{ __('Open "Organization Settings" to manage the name, staff, roles, and more.') }}</li>
                    <li>{{ __('The owner has full control and cannot be removed; ownership is a special, protected role.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Staff -->
            <x-help-topic :title="__('Staff members')" icon="users">
                <p>{{ __('Staff are the people who run the organization. Each staff member has a role that decides what they can do.') }}</p>
                <ol>
                    <li>{{ __('In "Organization Settings", use "Add Staff Member" and enter their email and a role.') }}</li>
                    <li>{{ __('Change someone\'s role at any time by selecting the role shown next to their name.') }}</li>
                    <li>{{ __('Remove a staff member with "Remove"; you can also leave an organization yourself with "Leave".') }}</li>
                </ol>
            </x-help-topic>

            <!-- Roles & permissions -->
            <x-help-topic :title="__('Roles & permissions')" icon="badge">
                <p>{{ __('Roles bundle permissions together so you can grant access in one click. Your organization starts with sensible defaults (such as Administrator and Staff) and you can tailor them.') }}</p>
                <ol>
                    <li>{{ __('In "Organization Settings", open the roles manager to see every available role and what it can do.') }}</li>
                    <li>{{ __('Create a custom role, or edit a default one — editing a default creates your own version for this organization without affecting anyone else.') }}</li>
                    <li>{{ __('Tick the permissions each role should have. Assign the role to staff from the staff list.') }}</li>
                </ol>
                <ul>
                    <li>{{ __('The "owner" role is reserved and always has full access.') }}</li>
                    <li>{{ __('A role that is still assigned to someone cannot be deleted — reassign those people first.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Teams -->
            <x-help-topic :title="__('Sub-teams')" icon="users">
                <p>{{ __('Teams are smaller groups inside your organization — for example a department or a project squad.') }}</p>
                <ul>
                    <li>{{ __('Create a team with "Create New Team" and invite members to it.') }}</li>
                    <li>{{ __('Members can belong to several teams; switch your active team from the navigation menu.') }}</li>
                    <li>{{ __('Teams created inside an organization automatically belong to it.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Customers -->
            <x-help-topic :title="__('Customers & the portal')" icon="lifebuoy">
                <p>{{ __('If your organization serves customers, each customer has their own account and signs in through the customer portal.') }}</p>
                <ul>
                    <li>{{ __('Invite a customer by email from the customers screen, or let them self-register if you enable registration for your organization.') }}</li>
                    <li>{{ __('A customer account can be a single person or a small shared group with invited members.') }}</li>
                    <li>{{ __('The same person can be your customer and a staff member of another organization — the two never mix.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Freezing staff / accounts -->
            <x-help-topic :title="__('Freezing staff & customer accounts')" icon="snowflake">
                <p>{{ __('Freezing temporarily suspends access without deleting anything — it is fully reversible. Use it to pause an account during a dispute, a security review, or non-payment.') }}</p>
                <ul>
                    <li>{{ __('Freeze a staff member from the staff list: they keep their place in the organization but lose all access and permissions until you unfreeze them. Owners cannot be frozen.') }}</li>
                    <li>{{ __('Freeze a customer account from the customers screen: its members are locked out of the portal for that account until it is unfrozen.') }}</li>
                    <li>{{ __('Nothing is lost while frozen — unfreeze at any time to restore access instantly.') }}</li>
                </ul>
            </x-help-topic>

            <!-- Freezing whole organizations (admin) -->
            @if ($user?->isSystemAdmin())
                <x-help-topic :title="__('Freezing an entire organization (administrators)')" icon="snowflake">
                    <p>{{ __('System administrators can freeze a whole organization from the tenant administration screen.') }}</p>
                    <ul>
                        <li>{{ __('When an organization is frozen, all of its staff and customers lose access at once.') }}</li>
                        <li>{{ __('This is reversible: unfreeze to bring everyone back exactly as they were.') }}</li>
                    </ul>
                </x-help-topic>

                <!-- Blocking users (admin) -->
                <x-help-topic :title="__('Blocking users (administrators)')" icon="no-entry">
                    <p>{{ __('Blocking is an account-wide ban that applies everywhere, across every organization the person belongs to.') }}</p>
                    <ol>
                        <li>{{ __('From "User Administration", find the person and choose "Block", optionally recording a reason.') }}</li>
                        <li>{{ __('They are signed out immediately and cannot sign back in until unblocked.') }}</li>
                        <li>{{ __('Choose "Unblock" to restore their access.') }}</li>
                    </ol>
                    <p>{{ __('Freeze vs. block: freeze limits access within one organization or account; block bans the person from the whole application.') }}</p>
                    <p class="font-medium">{{ __('Helping locked-out users') }}</p>
                    <ul>
                        <li>{{ __('From the same screen you can reset a user\'s two-factor authentication or clear their passkeys if they have lost their device and recovery codes.') }}</li>
                    </ul>
                </x-help-topic>

                <!-- Audit log (admin) -->
                <x-help-topic :title="__('Audit log (administrators)')" icon="clock">
                    <p>{{ __('Every important change is recorded with who did it, when, from where (IP address), and with which device.') }}</p>
                    <ul>
                        <li>{{ __('Each organization has its own change log on its settings screen.') }}</li>
                        <li>{{ __('The application-wide audit log is available from "Audit Log" in the administration menu.') }}</li>
                    </ul>
                </x-help-topic>
            @endif

            <p class="px-4 sm:px-0 text-gray-600 dark:text-gray-400">
                {{ __('Looking for help with your own sign-in, recovery, or privacy?') }}
                <a href="{{ route('help.account') }}" class="underline text-gray-900 dark:text-gray-100">{{ __('Visit Account Help') }}</a>.
            </p>
        </div>
    </div>
</x-app-layout>
