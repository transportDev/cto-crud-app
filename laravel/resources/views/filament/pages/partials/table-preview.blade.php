<div class="space-y-4">
    @if(isset($tablePreview['error']) && $tablePreview['error'])
        {{-- Empty state --}}
        <div class="flex flex-col items-center justify-center py-12 text-center bg-gray-50/50 dark:bg-gray-800/50 rounded-lg border-2 border-dashed border-gray-200 dark:border-gray-600">
            <div class="rounded-full bg-gray-100 dark:bg-gray-700 p-3 mb-4">
                <x-heroicon-o-table-cells class="h-8 w-8 text-gray-400 dark:text-gray-500" />
            </div>
            <h3 class="text-lg font-medium text-gray-900 dark:text-gray-100 mb-2">
                {{ $tablePreview['message'] }}
            </h3>
            <p class="text-sm text-gray-600 dark:text-gray-400 max-w-md">
                {{ $tablePreview['helper'] }}
            </p>
        </div>
    @elseif($loading ?? false)
        {{-- Loading state --}}
		<div class="flex flex-col items-center justify-center py-12 text-center bg-gray-50/50 dark:bg-gray-800/50 rounded-lg border border-gray-200 dark:border-gray-600">
			<style>
				.tb-loader{width:45px;aspect-ratio:.75;--c:no-repeat linear-gradient(currentColor 0 0);background:var(--c) 0% 100%,var(--c) 50% 100%,var(--c) 100% 100%;background-size:20% 65%;animation:tb-l8 1s infinite linear}
				@keyframes tb-l8{16.67%{background-position:0% 0%,50% 100%,100% 100%}33.33%{background-position:0% 0%,50% 0%,100% 100%}50%{background-position:0% 0%,50% 0%,100% 0%}66.67%{background-position:0% 100%,50% 0%,100% 0%}83.33%{background-position:0% 100%,50% 100%,100% 0%}}
			</style>
			<div class="tb-loader text-primary-600 dark:text-primary-400"></div>
			<p class="mt-4 text-sm text-gray-600 dark:text-gray-400">
				{{ __('table-builder.loading_preview') }}
			</p>
		</div>
    @elseif(isset($tablePreview['headers']) && isset($tablePreview['rows']))
        {{-- Table preview --}}
        <div class="bg-white dark:bg-gray-900 rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden shadow-sm">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-medium text-gray-900 dark:text-gray-100">
                        {{ __('table-builder.preview_title') }}: 
                        <span class="text-primary-600 dark:text-primary-400 font-mono">{{ $tablePreview['table_name'] ?? '' }}</span>
                    </h3>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400">
                        {{ count($tablePreview['headers']) }} {{ __('table-builder.columns_count') }}
                    </span>
                </div>
            </div>
            
            <div class="overflow-x-auto max-h-96">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">
                        <tr>
                            @foreach($tablePreview['headers'] as $header)
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider border-r border-gray-200 dark:border-gray-700 last:border-r-0 min-w-[150px]">
                                    <div class="space-y-1.5">
                                        <div class="flex items-center space-x-2">
                                            <span class="text-gray-900 dark:text-gray-100 font-semibold text-sm normal-case font-mono">{{ $header['name'] }}</span>
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-900/50 dark:text-blue-300">
                                                {{ $header['type'] }}
                                            </span>
                                        </div>
                                        @if(!empty($header['metadata']))
                                            <div class="flex flex-wrap gap-1">
                                                @foreach($header['metadata'] as $meta)
                                                    <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-white">
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
                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($tablePreview['rows'] as $rowIndex => $row)
                            <tr class="">
                                @foreach($row as $cellIndex => $cell)
                                    <td class="px-4 py-3 whitespace-nowrap text-sm border-r border-gray-200 dark:border-gray-700 last:border-r-0">
                                        @if($cell === null)
                                            <span class="text-gray-400 dark:text-gray-500 italic font-mono text-xs bg-gray-100 dark:bg-gray-800 px-2 py-1 rounded">null</span>
                                        @elseif($cell === '')
                                            <span class="text-gray-400 dark:text-gray-500 italic font-mono text-xs">empty</span>
                                        @else
                                            <span class="text-gray-900 dark:text-gray-100">{{ $cell }}</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            
            {{-- Table footer with sample data notice --}}
            <div class="px-4 py-2 border-t border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800">
                <p class="text-xs text-gray-500 dark:text-gray-400 flex items-center space-x-1">
                    <x-heroicon-o-information-circle class="h-3 w-3" />
                    <span>{{ __('table-builder.sample_data_notice') }}</span>
                </p>
            </div>
        </div>
    @else
        {{-- Default state when no preview is available --}}
        <div class="flex flex-col items-center justify-center py-8 text-center bg-gray-50/50 dark:bg-gray-800/50 rounded-lg border border-gray-200 dark:border-gray-600">
            <div class="rounded-full bg-gray-100 dark:bg-gray-700 p-3 mb-3">
                <x-heroicon-o-eye class="h-6 w-6 text-gray-400 dark:text-gray-500" />
            </div>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ __('table-builder.no_preview_available') }}
            </p>
        </div>
    @endif
</div>