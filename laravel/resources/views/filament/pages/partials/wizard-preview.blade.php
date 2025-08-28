@props(['analysis' => null])

<div>
    @if (!$analysis)
    <div class="text-gray-400">Click "Segarkan Pratinjau" untuk memunculkan preview</div>
    @else
    <x-filament::section>
        <x-slot name="heading">Impact</x-slot>
        @if (($analysis['impact'] ?? 'safe') === 'safe')
        <x-filament::badge color="success">Safe</x-filament::badge>
        @else
        <x-filament::badge color="warning">Risky</x-filament::badge>
        @endif
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">Warnings</x-slot>
        @if (empty($analysis['warnings']))
        <div class="text-gray-400">No warnings detected.</div>
        @else
        <ul class="list-disc pl-6">
            @foreach ($analysis['warnings'] as $w)
            <li>{{ $w }}</li>
            @endforeach
        </ul>
        @endif
    </x-filament::section>

    <x-filament::section class="mt-4">
        <x-slot name="heading">Estimated SQL (MySQL)</x-slot>
        <pre class="text-xs overflow-auto">{{ $analysis['estimated_sql'] }}</pre>
    </x-filament::section>
    @endif
</div>