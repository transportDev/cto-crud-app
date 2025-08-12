<x-filament::page>
    <div class="space-y-6">
        {{-- Header: No changes needed here, it already looks good. --}}
        <div class="flex items-center gap-4">
            <div class="bg-red-500/10 p-2 rounded-md">
                <x-heroicon-o-table-cells class="h-6 w-6 text-red-600" />
            </div>
            <div>
  
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Buat tabel baru dengan panduan langkah demi langkah. Setiap perubahan akan diperiksa dan ditampilkan pratinjaunya sebelum disimpan.
                </p>
            </div>
        </div>

        {{-- Builder form: This renders the Wizard and the new accordion repeater --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/50">
            {{ $this->form }}
        </div>

        {{-- Sticky action bar: Polished to match your design --}}
        <div class="sticky bottom-4 z-10">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white/75 dark:bg-gray-800/75 backdrop-blur-xl shadow-lg p-2 flex items-center justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="previewTable"
                    icon="heroicon-o-eye"
                    wire:loading.attr="disabled"
                >
                    Preview
                </x-filament::button>

                {{-- Using "danger" color to achieve the red button style from your screenshot --}}
                <x-filament::button
                    color="danger"
                    wire:click="createTable"
                    icon="heroicon-o-rocket-launch"
                    wire:loading.attr="disabled"
                    wire:target="createTable"
                >
                    Create Table
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament::page>