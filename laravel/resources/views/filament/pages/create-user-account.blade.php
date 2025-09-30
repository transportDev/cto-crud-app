<x-filament::page>
    <div class="space-y-6">
        <div class="flex items-center gap-4">
            <div class="bg-red-500/10 p-2 rounded-md">
                <x-heroicon-o-user-plus class="h-6 w-6 text-red-600" />
            </div>
            <div>
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100">
                    Buat Akun Pengguna
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Isi formulir di bawah ini untuk membuat akun baru bagi pengguna lain.
                </p>
            </div>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm p-4 text-sm text-gray-600 dark:text-white">
            <p class="font-semibold text-gray-900 dark:text-gray-100 mb-2">Ringkasan Peran:</p>
            <ul class="list-disc list-inside space-y-1">
                <li><span class="font-medium text-gray-900 dark:text-gray-100">Admin:</span> akses penuh ke panel administrasi /admin dan fitur manajemen (termasuk membuat akun baru).</li>
                <li><span class="font-medium text-gray-900 dark:text-gray-100">Requestor:</span> dapat membuka dashboard dan membuat order, tetapi tidak memiliki akses ke /admin.</li>
                <li><span class="font-medium text-gray-900 dark:text-gray-100">Viewer:</span> hanya dapat melihat dashboard tanpa kemampuan membuat order.</li>
            </ul>
        </div>

        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm">
            <x-filament-panels::form wire:submit.prevent="createUser" class="space-y-6 p-6">
                {{ $this->form }}

                <div class="flex items-center justify-end gap-3">
                    <x-filament::button type="button" color="gray" wire:click="cancel" icon="heroicon-o-x-mark">
                        Batal
                    </x-filament::button>

                    <x-filament::button type="submit" color="primary" icon="heroicon-o-check-circle" wire:loading.attr="disabled" wire:target="createUser">
                        <span wire:loading.remove wire:target="createUser">Simpan</span>
                        <span wire:loading.flex wire:target="createUser" class="items-center gap-2">
                            Menyimpan...
                        </span>
                    </x-filament::button>
                </div>
            </x-filament-panels::form>
        </div>
    </div>
</x-filament::page>