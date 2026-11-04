@extends('admin.layouts.app')
@section('title','Learning & Improvements')
@section('content')
<div class="grid md:grid-cols-2 gap-3">
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold text-sm mb-2">Recent post-trade analyses</h2>
@foreach($analyses as $a)<div class="text-xs border-t dark:border-slate-800 py-1"><b>#{{ $a->paper_position_id }}</b> entry {{ $a->entry_quality }} · exit {{ $a->exit_quality }} — {{ $a->lessons }}</div>@endforeach
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold text-sm mb-2">Improvement proposals</h2>
@foreach($proposals as $p)<div class="text-xs border-t dark:border-slate-800 py-1"><b>{{ $p->title }}</b> [{{ $p->status }}]<div class="text-slate-500">{{ $p->hypothesis }}</div></div>@endforeach
</div>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3">
<h2 class="font-semibold text-sm mb-2">Strategy evaluations</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Strategy</th><th>v</th><th>Trades</th><th>WR%</th><th>PF</th><th>Net</th><th>Verdict</th></tr></thead>
<tbody>@foreach($evaluations as $e)<tr class="border-t dark:border-slate-800"><td>{{ $e->tradingStrategy->code ?? $e->trading_strategy_id }}</td><td>{{ $e->version }}</td><td>{{ $e->total_trades }}</td><td>{{ $e->win_rate }}</td><td>{{ $e->profit_factor }}</td><td>{{ $e->net_profit }}</td><td>{{ $e->verdict }}</td></tr>@endforeach</tbody></table>
</div>
@endsection
