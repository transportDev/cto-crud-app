<div class="p-4 rounded-xl bg-white dark:bg-gray-900 ring-1 ring-gray-950/5 dark:ring-white/10">
    <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-2">Ringkasan Perubahan</h3>
    @if(empty($items))
        <p class="text-sm text-gray-500 dark:text-gray-400">Tidak ada perubahan yang didefinisikan.</p>
    @else
        <ul class="space-y-2">
            @foreach($items as $item)
                <li class="flex items-center space-x-3">
                    <span @class([
                        'inline-flex items-center justify-center w-8 h-8 rounded-full text-sm font-semibold',
                        'bg-primary-500/10 text-primary-700 dark:text-primary-400' => ($item['kind'] ?? 'field') === 'field',
                        'bg-success-500/10 text-success-700 dark:text-success-400' => ($item['kind'] ?? 'field') === 'relation',
                    ])>
                        @if(($item['kind'] ?? 'field') === 'field')
                            F
                        @else
                            R
                        @endif
                    </span>
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

