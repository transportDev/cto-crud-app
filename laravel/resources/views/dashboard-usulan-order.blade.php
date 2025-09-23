@extends('layouts.dashboard')

@section('title', 'CTO Panel • Usulan Order')

@push('head')
@endpush

@section('content')
<div class="dash-card">
    <div class="dash-card-header">
        <div>
            <h2 class="dash-card-title">Usulan Order</h2>
            <div class="dash-card-subtitle">Data dari tabel data_usulan_order</div>
        </div>
        @if(auth()->check() && auth()->user()->hasRole('admin'))
        <div>
            <button id="exportUsulanOrder" type="button" class="btn-ghost" title="Ekspor Excel">Ekspor Excel</button>
        </div>
        @endif
    </div>



    <div class="overflow-x-auto dash-table-wrapper dash-scroll">
        <table class="dash-table">
            <thead>
                <tr>

                    <th>No</th>
                    <th>Tanggal</th>
                    <th>Requestor</th>
                    <th>Regional</th>
                    <th>NOP</th>
                    <th>Site NE</th>
                    <th>Site FE</th>
                    <th>Transport Type</th>
                    <th>PL Status</th>
                    <th>Transport Category</th>
                    <th>PL Value</th>
                    <th class="text-right">Link Cap</th>
                    <th class="text-right">Link Util</th>
                    <th>Link Owner</th>
                    <th>Propose Solution</th>
                    <th>Remark</th>
                    <th class="text-right">Jarak ODP</th>
                    <th>Cek NIM</th>
                    <th>Status Order</th>
                    <th>Komentar</th>
                </tr>
            </thead>
            <tbody>
                @forelse($orders as $i => $o)
                <tr class="align-top">

                    <td>{{ $o->no }}</td>
                    <td>{{ $o->tanggal_input }}</td>
                    <td class="truncate" title="{{ $o->requestor }}">{{ $o->requestor }}</td>
                    <td>{{ $o->regional }}</td>
                    <td class="truncate" title="{{ $o->nop }}">{{ $o->nop }}</td>
                    <td>{{ $o->siteid_ne }}</td>
                    <td class="truncate" title="{{ $o->siteid_fe }}">{{ $o->siteid_fe }}</td>
                    <td>{{ $o->transport_type }}</td>
                    <td>{{ $o->pl_status }}</td>
                    <td>{{ $o->transport_category }}</td>
                    <td>{{ $o->pl_value }}</td>
                    <td class="text-right">{{ number_format((int) $o->link_capacity) }}</td>
                    <td class="text-right">{{ number_format((float) $o->link_util, 2) }}</td>
                    <td>{{ $o->link_owner }}</td>
                    <td class="truncate" title="{{ $o->propose_solution }}">{{ $o->propose_solution }}</td>
                    <td class="truncate" title="{{ $o->remark }}">{{ $o->remark }}</td>
                    <td class="text-right">{{ $o->jarak_odp !== null ? number_format((float)$o->jarak_odp,2) : '–' }}</td>
                    <td class="truncate" title="{{ $o->cek_nim_order }}">{{ $o->cek_nim_order }}</td>
                    <td>{{ $o->status_order }}</td>
                    <td>
                        @php $comments = $o->comments; @endphp
                        @if($comments->isEmpty())
                        <span class="text-gray-400">–</span>
                        @else
                        <div class="dash-scroll" style="max-height:96px;overflow:auto;">
                            <ul class="space-y-1">
                                @foreach($comments as $cm)
                                <li class="comment"><span class="font-semibold">{{ $cm->requestor }}</span> – {{ $cm->comment }}</li>
                                @endforeach
                            </ul>
                        </div>
                        @endif
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="21" class="text-center text-gray-400 py-6">Tidak ada data.</td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between mt-3 text-sm">
        <div id="capPageInfo2" class="text-gray-400">
            @if($orders->total())
            Menampilkan {{ $orders->firstItem() }}–{{ $orders->lastItem() }} dari {{ $orders->total() }}
            @else
            Menampilkan 0–0 dari 0
            @endif
        </div>
        <div class="flex items-center gap-2">
            <label for="capPerPage2" class="text-gray-400">Baris per halaman</label>
            <select id="capPerPage2" class="select-dark">
                <option value="20" {{ (int)($perPage ?? 25) === 20 ? 'selected' : '' }}>20</option>
                <option value="50" {{ (int)($perPage ?? 25) === 50 ? 'selected' : '' }}>50</option>
                <option value="100" {{ (int)($perPage ?? 25) === 100 ? 'selected' : '' }}>100</option>
            </select>
            <a id="capPrev2"
                class="btn-red {{ $orders->onFirstPage() ? 'opacity-50 pointer-events-none' : '' }}"
                href="{{ $orders->previousPageUrl() ?: '#' }}"
                role="button">Sebelumnya</a>
            <a id="capNext2"
                class="btn-red {{ $orders->hasMorePages() ? '' : 'opacity-50 pointer-events-none' }}"
                href="{{ $orders->nextPageUrl() ?: '#' }}"
                role="button">Berikutnya</a>
        </div>
    </div>
    <script>
        (function() {
            const sel = document.getElementById('capPerPage2');
            if (!sel) return;
            sel.addEventListener('change', function() {
                const url = new URL(window.location.href);
                url.searchParams.set('perPage', this.value);
                url.searchParams.delete('page'); // reset to first page
                window.location.href = url.toString();
            });
        })();
    </script>
</div>
@endsection