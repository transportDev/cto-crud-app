<div class="rounded-xl border border-gray-200 dark:border-gray-800 bg-white dark:bg-gray-900 p-4">
    <div class="flex items-start gap-3">
        <x-heroicon-o-shield-check class="h-6 w-6 text-red-600 mt-0.5" />
        <div class="space-y-1">
            <h3 class="text-sm font-semibold">Indexes & Constraints</h3>
            <ul class="text-sm text-gray-600 dark:text-gray-400 list-disc pl-5 space-y-1">
                <li>Use the Unique and Index toggles per column to create indexes. Fulltext is supported on compatible databases.</li>
                <li>Foreign keys appear when you choose the foreignId type. Configure the referenced table/column and actions.</li>
                <li>Primary keys can be set via the Primary toggle. Auto-increment is available for integer/bigInteger.</li>
                <li>Soft deletes and timestamps can be toggled in the Table Info step.</li>
                <li>Review everything in the Preview & Confirm step before applying changes.</li>
            </ul>
        </div>
    </div>
</div>