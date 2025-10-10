<div class="p-4 rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Ringkasan Perubahan</h3>
    @if(empty($items))
    <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada perubahan yang didefinisikan.</p>
    @else
    <ul class="space-y-2">
        @foreach($items as $item)
        <li class="flex items-start space-x-3">
            <div class="flex-1">
                <p class="font-semibold text-gray-800 dark:text-gray-200">
                    @if(($item['kind'] ?? 'field') === 'field')
                    TAMBAH KOLOM: <span class="font-mono">{{ $item['name'] ?? 'N/A' }}</span>
                    @else
                    TAMBAH RELASI: <span class="font-mono">{{ $item['name'] ?? 'N/A' }}</span>
                    @endif
                </p>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    @if(($item['kind'] ?? 'field') === 'field')
                    Tipe: {{ $item['type'] ?? 'N/A' }}
                    @else
                    Tabel Referensi: {{ $item['references_table'] ?? 'N/A' }}
                    @endif
                </p>
            </div>
        </li>
        @endforeach
    </ul>
    @endif
</div>