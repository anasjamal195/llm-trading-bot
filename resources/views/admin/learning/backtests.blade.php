@extends('admin.layouts.app')
@section('title','Backtesting')
@section('content')
<form method="POST" action="{{ route('admin.backtests.run') }}" class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 flex flex-wrap gap-2 text-sm mb-3">
@csrf
<select name="strategy_code" class="rounded border px-2 py-1 bg-white dark:bg-slate-900">@foreach(\App\Models\TradingStrategy::all() as $s)<option value="{{ $s->code }}">{{ $s->code }}</option>@endforeach</select>
<input name="symbol" value="EURUSD" class="rounded border px-2 py-1 w-24 bg-white dark:bg-slate-900">
<select name="timeframe" class="rounded border px-2 py-1 bg-white dark:bg-slate-900"><option>H1</option><option>H4</option><option>D1</option><option>M15</option></select>
<input name="from" type="date" class="rounded border px-2 py-1 bg-white dark:bg-slate-900" required>
<input name="to" type="date" class="rounded border px-2 py-1 bg-white dark:bg-slate-900" required>
<button class="rounded bg-slate-800 text-white px-3 py-1">Run walk-forward</button>
</form>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Strategy</th><th>Sym</th><th>TF</th><th>Range</th><th>Status</th><th>Result</th></tr></thead>
<tbody>@foreach($runs as $r)<tr class="border-t dark:border-slate-800"><td>{{ $r->id }}</td><td>{{ $r->strategy_code }}</td><td>{{ $r->symbol }}</td><td>{{ $r->timeframe }}</td><td>{{ $r->from }} → {{ $r->to }}</td><td>{{ $r->status }}</td><td>{{ json_encode($r->results) }}</td></tr>@endforeach</tbody></table>
{{ $runs->links() }}
</div>
@endsection
