<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>CTO Panel • Usulan Order</title>
    @vite(['resources/css/app.css','resources/css/dashboard.css','resources/js/app.js'])
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=poppins:400,500,600&display=swap" rel="stylesheet" />
    <meta name="csrf-token" content="{{ csrf_token() }}">
</head>

<body>
    <div class="max-w-7xl mx-auto p-6">
        <div class="grid grid-cols-1 md:grid-cols-[240px_minmax(0,1fr)] gap-4 items-start">
            @include('partials.dashboard-sidebar')
            <div>
                <div class="dash-card">
                    <div class="dash-card-header">
                        <div>
                            <h2 class="dash-card-title">Usulan Order</h2>
                            <div class="dash-card-subtitle">Data dari tabel data_usulan_order</div>
                        </div>
                    </div>



                    <div class="overflow-x-auto dash-table-wrapper dash-scroll">
                        <table class="dash-table">
                            <thead>
                                <tr>
                                    <th class="w-10">#</th>
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
                                    <td>{{ ($orders->firstItem() + $i) }}</td>
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

                    <div class="dash-pagination">
                        <div>
                            @php $fi = $orders->firstItem(); $li = $orders->lastItem(); @endphp
                            Showing {{ $fi ?? 0 }}–{{ $li ?? 0 }} of {{ number_format($orders->total()) }} entries
                        </div>
                        <div>
                            {{ $orders->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>

</html>