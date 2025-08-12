<x-filament::page>
    <div class="space-y-6">
        <!-- Header -->
        <div class="flex items-center gap-3">
            <x-heroicon-o-table-cells class="h-6 w-6 text-red-600" />
            <div>
                <h2 class="text-lg font-semibold">Table Builder</h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Create new tables with a guided wizard. Changes are validated and previewed before applying.
                </p>
            </div>
        </div>

        <!-- Builder form -->
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
            {{ $this->form }}
        </div>

        <!-- Sticky action bar -->
        <div class="sticky bottom-3 z-20">
            <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white/90 dark:bg-gray-900/90 backdrop-blur supports-[backdrop-filter]:bg-white/70 shadow-lg p-3 flex items-center justify-end gap-2">
                <x-filament::button color="gray" wire:click="previewTable" icon="heroicon-o-eye">
                    Preview
                </x-filament::button>
                <x-filament::button color="primary" wire:click="createTable" icon="heroicon-o-rocket-launch">
                    Create Table
                </x-filament::button>
            </div>
        </div>
    </div>
</x-filament::page>
