<x-filament::page>
    <div class="space-y-6">
        {{-- Header --}}
        <div class="flex items-center gap-4">
            <div class="bg-red-500/10 p-2 rounded-md">
                <x-heroicon-o-table-cells class="h-6 w-6 text-red-600" />
            </div>
            <div>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('table-builder.description') }}
                </p>
            </div>
        </div>

        {{-- Builder form: This renders the Wizard and the new accordion repeater --}}
        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/50">
            {{ $this->form }}
        </div>

        {{-- Sticky action bar --}}
        <div class="sticky bottom-4 z-10">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white/75 dark:bg-gray-800/75 backdrop-blur-xl shadow-lg p-2 flex items-center justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="previewTable"
                    icon="heroicon-o-eye"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                >
                    <span wire:loading.remove wire:target="previewTable">
                        {{ __('table-builder.actions.reload_preview') }}
                    </span>
                    <span wire:loading wire:target="previewTable">
                        {{ __('table-builder.loading_preview') }}
                    </span>
                </x-filament::button>

                <x-filament::button
                    color="danger"
                    wire:click="createTable"
                    icon="heroicon-o-rocket-launch"
                    wire:loading.attr="disabled"
                    wire:target="createTable"
                    wire:loading.class="opacity-50"
                >
                    <span wire:loading.remove wire:target="createTable">
                        {{ __('table-builder.actions.create_table') }}
                    </span>
                    <span wire:loading wire:target="createTable">
                        {{ __('table-builder.loading_preview') }}
                    </span>
                </x-filament::button>
            </div>
        </div>
    </div>

    {{-- Auto-generate preview when entering preview step --}}
    <script>
        document.addEventListener('livewire:initialized', () => {
            // Listen for wizard step changes
            Livewire.on('wizard-step-changed', (step) => {
                if (step === 3) { // Preview step (0-indexed)
                    // Auto-generate preview when entering preview step
                    Livewire.find('{{ $this->getId() }}').call('previewTable');
                }
            });
        });
    </script>
</x-filament::page>