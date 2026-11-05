@extends('admin.layouts.app')
@section('title','Open Trades')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Dir</th><th>Entry</th><th>Mark</th><th>SL</th><th>TP</th><th>uP&L</th><th>%</th><th>R</th><th>Held</th><th>Thesis</th><th></th></tr></thead>
<tbody>@foreach($positions as $p)<tr class="border-t dark:border-slate-800" data-position="{{ $p->id }}"><td><a class="underline" href="{{ route('admin.trades.detail',$p) }}">{{ $p->id }}</a></td><td>{{ $p->symbol }}</td><td>{{ $p->direction }}</td><td>{{ $p->entry_price }}</td><td data-mark>{{ $p->current_price }}</td><td>{{ $p->stop_loss }}</td><td>{{ $p->take_profit }}</td><td data-upl>{{ $p->unrealized_pnl }}</td><td data-pct>{{ $p->movePct() }}%</td><td>{{ $p->rMultiple() ?? '—' }}R</td><td>{{ $p->opened_at->diffForHumans() }}</td><td class="max-w-xs truncate" title="{{ $p->thesis }}">{{ $p->thesis }}</td><td><form method="POST" action="{{ route('admin.trades.close',$p) }}">@csrf<button class="text-red-500 underline">Close</button></form></td></tr>@endforeach</tbody></table>
{{ $positions->links() }}
</div>
@endsection
