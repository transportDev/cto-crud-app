<x-filament::page>
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <div class="bg-red-500/10 p-2 rounded-md">
                <x-heroicon-o-lock-closed class="h-6 w-6 text-red-600" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Reset Password Pengguna
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Gunakan formulir ini untuk mereset password pengguna yang lupa atau perlu direset.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
            <div class="p-6 space-y-6">
                <x-filament-panels::form>
                    {{ $this->form }}

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-gray-200 dark:border-gray-700">
                        <x-filament::button
                            type="button"
                            color="gray"
                            wire:click="resetForm"
                            icon="heroicon-o-arrow-path">
                            Reset Form
                        </x-filament::button>

                        <x-filament::button
                            type="button"
                            color="danger"
                            icon="heroicon-o-lock-closed"
                            wire:click="confirmPasswordReset"
                            wire:loading.attr="disabled"
                            wire:target="resetPassword">
                            Reset Password
                        </x-filament::button>
                    </div>
                </x-filament-panels::form>
            </div>
        </div>
    </div>

    <x-filament::modal id="confirm-password-reset" width="md">
        <x-slot name="heading">
            Konfirmasi Reset Password
        </x-slot>

        <div class="space-y-4">
            <p class="text-sm text-gray-600 dark:text-gray-400">
                Apakah Anda yakin ingin mereset password pengguna ini?
            </p>
            <p class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                Tindakan ini tidak dapat dibatalkan.
            </p>
        </div>

        <x-slot name="footerActions">
            <x-filament::button
                color="gray"
                x-on:click="close">
                Batal
            </x-filament::button>

            <x-filament::button
                color="danger"
                wire:click="resetPassword"
                wire:loading.attr="disabled"
                wire:target="resetPassword">
                <span wire:loading.remove wire:target="resetPassword">Ya, Reset Password</span>
                <span wire:loading wire:target="resetPassword">Mereset...</span>
            </x-filament::button>
        </x-slot>
    </x-filament::modal>
</x-filament::page>