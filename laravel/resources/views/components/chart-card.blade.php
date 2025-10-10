@props(['id', 'title', 'height' => 420, 'class' => ''])

@php
$chartHeightValue = is_numeric($height)
? ((int) $height) . 'px'
: (is_string($height) && trim($height) !== ''
? trim($height)
: '420px');
@endphp

<div class="card relative {{ $class }}">
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-xl font-semibold">{{ $title }}</h2>
        @if (isset($header))
        <div>{{ $header }}</div>
        @endif
    </div>

    <div id="{{ $id }}" class="chart-card__canvas" style="--chart-height: {{ e($chartHeightValue) }};"></div>

    <div class="loading-overlay" id="{{ $id }}-loading">
        <div class="spinner" aria-label="Loading"></div>
    </div>
</div>