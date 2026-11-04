@extends('admin.layouts.app')
@section('title','Closed Trades')
@section('content')
<form method="GET" class="flex gap-2 mb-3 text-sm">
<input name="symbol" value="{{ request('symbol') }}" placeholder="Symbol" class="rounded border px-2 py-1 bg-white dark:bg-slate-900">
<input name="strategy" value="{{ request('strategy') }}" placeholder="Strategy" class="rounded border px-2 py-1 bg-white dark:bg-slate-900">
<button class="rounded bg-slate-800 text-white px-3 py-1">Filter</button>
</form>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Dir</th><th>Strategy</th><th>Entry</th><th>Exit</th><th>P&L</th><th>Reason</th><th>Amb</th><th>Closed</th></tr></thead>
<tbody>@foreach($positions as $p)<tr class="border-t dark:border-slate-800"><td><a class="underline" href="{{ route('admin.trades.detail',$p) }}">{{ $p->id }}</a></td><td>{{ $p->symbol }}</td><td>{{ $p->direction }}</td><td>{{ $p->strategy_code }}</td><td>{{ $p->entry_price }}</td><td>{{ $p->exit_price }}</td><td class="{{ $p->realized_pnl<0?'text-red-500':'text-emerald-500' }}">{{ $p->realized_pnl }}</td><td>{{ $p->close_reason }}</td><td>{{ $p->ambiguous_exit?'yes':'no' }}</td><td>{{ $p->closed_at }}</td></tr>@endforeach</tbody></table>
{{ $positions->links() }}
</div>
@endsection
