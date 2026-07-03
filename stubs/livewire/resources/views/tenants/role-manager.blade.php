<div>
    <x-action-section>
        <x-slot name="title">
            {{ __('Roles') }}
        </x-slot>

        <x-slot name="description">
            {{ __('Manage the roles that can be assigned to staff and team members. Default roles are provided by the application and may be overridden for this organization.') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-6">
                @foreach ($this->roles as $role)
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center">
                                <div class="text-gray-900 dark:text-white">{{ $role->name }}</div>

                                @if (array_key_exists($role->key, $this->customRoleKeys))
                                    <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-indigo-100 text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200">{{ __('Custom') }}</span>
                                @else
                                    <span class="ms-2 px-2 py-0.5 text-xs rounded-full bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">{{ __('Default') }}</span>
                                @endif
                            </div>

                            <div class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                                {{ $role->description }}
                            </div>

                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-500">
                                {{ implode(', ', $role->permissions) }}
                            </div>
                        </div>

                        @if (Gate::check('manageRoles', $tenant))
                            <div class="flex items-center">
                                <button class="cursor-pointer ms-6 text-sm text-gray-400 underline focus:outline-none"
                                        wire:click="editRole('{{ $role->key }}')">
                                    {{ array_key_exists($role->key, $this->customRoleKeys) ? __('Edit') : __('Override') }}
                                </button>

                                @if (array_key_exists($role->key, $this->customRoleKeys))
                                    <button class="cursor-pointer ms-4 text-sm text-red-500 focus:outline-none"
                                            wire:click="confirmRoleDeletion({{ $this->customRoleKeys[$role->key] }})">
                                        {{ __('Delete') }}
                                    </button>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach

                @if (Gate::check('manageRoles', $tenant))
                    <div class="flex items-center justify-end">
                        <x-button wire:click="createRole" wire:loading.attr="disabled">
                            {{ __('New Role') }}
                        </x-button>
                    </div>
                @endif
            </div>
        </x-slot>
    </x-action-section>

    <!-- Create / Edit Role Modal -->
    <x-dialog-modal wire:model.live="managingRole">
        <x-slot name="title">
            {{ $roleIdBeingUpdated ? __('Edit Role') : __('Create Role') }}
        </x-slot>

        <x-slot name="content">
            <div class="space-y-4">
                <div>
                    <x-label for="role-key" value="{{ __('Key') }}" />

                    <x-input id="role-key"
                                type="text"
                                class="mt-1 block w-full"
                                wire:model="roleForm.key"
                                :disabled="(bool) $roleIdBeingUpdated"
                                placeholder="{{ __('e.g. support-agent') }}" />

                    <x-input-error for="key" class="mt-2" />
                </div>

                <div>
                    <x-label for="role-name" value="{{ __('Name') }}" />

                    <x-input id="role-name" type="text" class="mt-1 block w-full" wire:model="roleForm.name" />

                    <x-input-error for="name" class="mt-2" />
                </div>

                <div>
                    <x-label for="role-description" value="{{ __('Description') }}" />

                    <x-input id="role-description" type="text" class="mt-1 block w-full" wire:model="roleForm.description" />

                    <x-input-error for="description" class="mt-2" />
                </div>

                <div>
                    <x-label value="{{ __('Permissions') }}" />

                    <div class="mt-2 grid grid-cols-1 md:grid-cols-2 gap-4">
                        @foreach ($this->availablePermissions as $permission)
                            <label class="flex items-center">
                                <x-checkbox wire:model="roleForm.permissions" :value="$permission" />
                                <span class="ms-2 text-sm text-gray-600 dark:text-gray-400">{{ $permission }}</span>
                            </label>
                        @endforeach
                    </div>

                    <x-input-error for="permissions" class="mt-2" />
                </div>
            </div>
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="stopManagingRole" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-button class="ms-3" wire:click="saveRole" wire:loading.attr="disabled">
                {{ __('Save') }}
            </x-button>
        </x-slot>
    </x-dialog-modal>

    <!-- Delete Role Confirmation Modal -->
    <x-confirmation-modal wire:model.live="confirmingRoleDeletion">
        <x-slot name="title">
            {{ __('Delete Role') }}
        </x-slot>

        <x-slot name="content">
            {{ __('Are you sure you would like to delete this role? Roles that are still assigned to members cannot be deleted.') }}

            <x-input-error for="role" class="mt-2" />
        </x-slot>

        <x-slot name="footer">
            <x-secondary-button wire:click="$set('confirmingRoleDeletion', false)" wire:loading.attr="disabled">
                {{ __('Cancel') }}
            </x-secondary-button>

            <x-danger-button class="ms-3" wire:click="deleteRole" wire:loading.attr="disabled">
                {{ __('Delete') }}
            </x-danger-button>
        </x-slot>
    </x-confirmation-modal>
</div>
