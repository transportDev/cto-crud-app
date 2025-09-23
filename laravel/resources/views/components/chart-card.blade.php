@props(['id', 'title'])

<div class="card relative">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold">{{ $title }}</h2>
        @if (isset($header))
        <div>{{ $header }}</div>
        @endif
    </div>

    <div id="{{ $id }}" style="height: 420px;"></div>

    <div class="loading-overlay" id="{{ $id }}-loading">
        <div class="spinner" aria-label="Loading"></div>
    </div>
</div>