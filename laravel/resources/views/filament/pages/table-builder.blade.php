<x-filament::page>
    <div class="space-y-6">

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


        <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800/50">
            {{ $this->form }}
        </div>


        <div class="sticky bottom-4 z-10">
            <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white/75 dark:bg-gray-800/75 backdrop-blur-xl shadow-lg p-2 flex items-center justify-end gap-3">



                <x-filament::button
                    color="gray"
                    wire:click="previewTable"
                    icon="heroicon-o-eye"
                    wire:loading.attr="disabled"
                    wire:loading.class="opacity-50"
                    wire:target="previewTable">
                    <span wire:loading.remove wire:target="previewTable">
                        {{ __('table-builder.actions.reload_preview') }}
                    </span>
                    <span wire:loading wire:target="previewTable">
                        {{ __('table-builder.loading_preview') }}
                    </span>
                </x-filament::button>

                <x-filament::button
                    color="danger"
                    icon="heroicon-o-rocket-launch"
                    x-on:click="$dispatch('open-modal', { id: 'confirm-create-table' })"
                    wire:loading.attr="disabled"
                    wire:target="createTable"
                    wire:loading.class="opacity-50">

                    <span wire:loading.remove wire:target="createTable">
                        {{ __('table-builder.actions.create_table') }}
                    </span>
                    <span wire:loading wire:target="createTable">
                        Membuat Tabel...
                    </span>
                </x-filament::button>
            </div>
        </div>
    </div>
    @php
    $tableName = data_get($this->data, 'table');
    $columnsCount = count(data_get($this->data, 'columns', []));
    $canAct = filled($tableName) && $columnsCount > 0;
    @endphp

    <x-filament::modal
        id="confirm-create-table"
        icon="heroicon-o-exclamation-triangle"
        heading="Konfirmasi pembuatan tabel"
        subheading="Tindakan ini akan membuat tabel baru di database. Pastikan konfigurasi sudah benar."
        width="lg">

        <div class="space-y-4">
            <div class="rounded-lg border border-danger-300/30 bg-danger-50 dark:bg-danger-500/10 p-3">
                <p class="text-sm text-danger-700 dark:text-danger-300">
                    Apakah Anda yakin ingin membuat tabel ini? Proses ini akan menyimpan struktur tabel ke database.
                </p>
            </div>
        </div>

        <x-slot name="footer">
            <div class="w-full flex items-center justify-between">
                <x-filament::button
                    color="gray"
                    x-on:click="$dispatch('close-modal', { id: 'confirm-create-table' })">
                    Batal
                </x-filament::button>

                <div class="flex items-center gap-2">


                    <x-filament::button
                        color="danger"
                        icon="heroicon-o-rocket-launch"
                        wire:click="createTable"
                        wire:loading.attr="disabled"
                        wire:target="createTable"
                        x-on:click="$dispatch('close-modal', { id: 'confirm-create-table' })">
                        <span wire:loading.remove wire:target="createTable">
                            Buat tabel
                        </span>
                        <span wire:loading wire:target="createTable">
                            Membuat...
                        </span>
                    </x-filament::button>
                </div>
            </div>
        </x-slot>
    </x-filament::modal>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            let previewTimeout = null;


            function handlePreviewCall() {

                setTimeout(function() {
                    const loadingElements = document.querySelectorAll('[wire\\:loading][wire\\:target="previewTable"]');
                    loadingElements.forEach(function(element) {
                        if (element.style.display !== 'none') {
                            console.warn('Preview taking too long, forcing reset');
                            @this.set('previewLoading', false);
                        }
                    });
                }, 10000);
            }

            function handleCreateTableCall() {

                setTimeout(function() {
                    const loadingElements = document.querySelectorAll('[wire\\:loading][wire\\:target="createTable"]');
                    loadingElements.forEach(function(element) {
                        if (element.style.display !== 'none') {
                            console.warn('Table creation taking too long, forcing reset');
                            @this.set('previewLoading', false);
                        }
                    });
                }, 30000);
            }


            setInterval(function() {
                const loadingElements = document.querySelectorAll('[wire\\:loading][wire\\:target="previewTable"]');
                loadingElements.forEach(function(element) {
                    if (element.style.display !== 'none') {
                        console.warn('Detected stuck loading state, forcing reset');
                        @this.set('previewLoading', false);
                    }
                });
            }, 8000);


            function goToFirstWizardStep() {
                const wizard = document.getElementById('table-builder-wizard');
                if (!wizard) return;

                const tablists = wizard.querySelectorAll('[role="tablist"]');
                if (!tablists.length) return;

                const firstStepBtn = tablists[0].querySelector('[role="tab"]');
                if (firstStepBtn) firstStepBtn.click();

                const url = new URL(window.location.href);
                url.searchParams.delete('tb_step');
                history.replaceState({}, document.title, url);
            }


            document.addEventListener('livewire:initialized', function() {


                Livewire.on('reset-wizard-to-step-one', function() {
                    console.log('Resetting wizard to step one');
                    setTimeout(function() {
                        goToFirstWizardStep();

                        window.scrollTo({
                            top: 0,
                            behavior: 'smooth'
                        });
                    }, 500);
                });


                Livewire.on('form-reset-complete', function() {
                    console.log('Form reset completed');

                    const header = document.querySelector('.space-y-6');
                    if (header) {
                        const successBadge = document.createElement('div');
                        successBadge.className = 'fixed top-4 right-4 bg-green-500 text-white px-4 py-2 rounded-lg shadow-lg z-50 transform transition-transform';
                        successBadge.textContent = 'Form berhasil direset';

                        document.body.appendChild(successBadge);
                        setTimeout(() => successBadge.style.transform = 'translateX(0)', 50);
                        setTimeout(() => {
                            successBadge.style.transform = 'translateX(100%)';
                            setTimeout(() => successBadge.remove(), 300);
                        }, 3000);
                    }
                });


                Livewire.on('preview-completed', function() {
                    console.log('Preview completed');
                });

                document.addEventListener('click', function(event) {
                    const stepButton = event.target.closest('#table-builder-wizard [role="tablist"] [role="tab"]');
                    if (stepButton) {
                        setTimeout(function() {
                            const isPreviewStep =
                                stepButton.textContent.includes('Pratinjau') ||
                                stepButton.textContent.includes('Preview');

                            if (isPreviewStep) {
                                setTimeout(function() {
                                    console.log('Auto-generating preview for step');
                                    handlePreviewCall();
                                    @this.call('previewTable', true);
                                }, 500);
                            }
                        }, 100);
                    }
                });


                document.addEventListener('click', function(event) {
                    if (event.target.matches('[wire\\:click="previewTable"]') ||
                        event.target.closest('[wire\\:click="previewTable"]')) {
                        console.log('Manual preview button clicked');
                        handlePreviewCall();
                    }
                });


                document.addEventListener('click', function(event) {
                    if (event.target.matches('[wire\\:click="createTable"]') ||
                        event.target.closest('[wire\\:click="createTable"]')) {
                        console.log('Create table button clicked');
                        handleCreateTableCall();
                    }
                });
            });
        });
    </script>

</x-filament::page>