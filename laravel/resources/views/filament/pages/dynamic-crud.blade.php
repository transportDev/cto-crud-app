<x-filament::page>
    <div class="space-y-4">
        <!-- Sticky header actions and table selector -->
        <div class="z-20 bg-white/80 dark:bg-gray-900/80 backdrop-blur supports-[backdrop-filter]:bg-white/60 rounded-xl border border-gray-200 dark:border-gray-800 p-4">
            <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
                <div class="flex items-center gap-3">
                    <x-heroicon-o-list-bullet class="h-6 w-6 text-red-600" />
                    <div>

                        <p class="text-sm text-gray-600 dark:text-gray-400">Kelola tabel apapun. Pilih tabel untuk melihat, menambah, mengedit, atau menghapus data.</p>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <!-- Render the inline selector form -->
                    <div class="min-w-[240px]">
                        {{ $this->form }}
                    </div>

                    <a href="{{ \App\Filament\Pages\TableBuilder::getUrl() }}"
                        class="inline-flex items-center gap-1 rounded-lg px-3 py-2 text-sm font-medium bg-red-600 text-white hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900 transition">
                        <x-heroicon-o-plus-circle class="h-5 w-5" />
                        Table Builder
                    </a>
                </div>
            </div>
        </div>

        <!-- Table region -->
        <div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-2">
            {{ $this->table }}
        </div>

        <!-- Helpful empty state is handled by Table definition in the Page class -->
    </div>
</x-filament::page>