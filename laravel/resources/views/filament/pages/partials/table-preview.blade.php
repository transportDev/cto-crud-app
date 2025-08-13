<div class="space-y-4">
    @if(isset($tablePreview['error']) && $tablePreview['error'])
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="rounded-full bg-gray-100 dark:bg-gray-800 p-3 mb-4">
                <x-heroicon-o-table-cells class="h-8 w-8 text-gray-400" />
            </div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                {{ $tablePreview['message'] }}
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md">
                {{ $tablePreview['helper'] }}
            </p>
        </div>
    @elseif($loading ?? false)
        {{-- Loading state --}}
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="animate-spin rounded-full h-8 w-8 border-b-2 border-primary-600 mb-4"></div>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                {{ __('table-builder.loading_preview') }}
            </p>
        </div>
    @elseif(isset($tablePreview['headers']) && isset($tablePreview['rows']))
        {{-- Table preview --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900/50">
                <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ __('table-builder.preview_title') }}: {{ $tablePreview['table_name'] ?? '' }}
                </h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/50">
                        <tr>
                            @foreach($tablePreview['headers'] as $header)
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                                    <div class="space-y-1">
                                        <div class="flex items-center space-x-2">
                                            <span>{{ $header['name'] }}</span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200">
                                                {{ $header['type'] }}
                                            </span>
                                        </div>
                                        @if(!empty($header['metadata']))
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($header['metadata'] as $meta)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
                                                        {{ $meta }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                </th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($tablePreview['rows'] as $rowIndex => $row)
                            <tr class="{{ $rowIndex % 2 === 0 ? 'bg-white dark:bg-gray-800' : 'bg-gray-50 dark:bg-gray-900/25' }}">
                                @foreach($row as $cellIndex => $cell)
                                    <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900 dark:text-gray-100">
                                        @if($cell === null)
                                            <span class="text-gray-400 italic">null</span>
                                        @else
                                            {{ $cell }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        
        {{-- Auto-refresh notice --}}
        <div class="flex items-center space-x-2 text-xs text-gray-500 dark:text-gray-400">
            <x-heroicon-o-information-circle class="h-4 w-4" />
            <span>Pratinjau akan diperbarui secara otomatis saat skema berubah</span>
        </div>
    @else
        {{-- Error state --}}
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <div class="rounded-full bg-red-100 dark:bg-red-900/20 p-3 mb-4">
                <x-heroicon-o-exclamation-triangle class="h-8 w-8 text-red-500" />
            </div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                {{ __('table-builder.preview_error') }}
            </h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mb-4">
                {{ __('table-builder.preview_error_helper') }}
            </p>
            <button 
                type="button" 
                wire:click="previewTable"
                class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-primary-600 hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
            >
                {{ __('table-builder.try_again') }}
            </button>
        </div>
    @endif
</div>