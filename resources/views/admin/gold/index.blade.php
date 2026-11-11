@extends('admin.layouts.app')
@section('title','GOLD Backtest')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<p class="text-xs text-slate-500 mb-2">Every run of <code>bot:backtest-gold-llm</code> is saved here automatically (run <code>--name</code> becomes the strategy name).</p>
<table class="w-full text-xs">
<thead><tr class="text-left text-slate-500"><th class="py-1">Strategy</th><th>Symbol</th><th>TF</th><th>Range</th><th>Trades</th><th>Win%</th><th>Net</th><th>Net%</th><th>Max DD</th><th></th></tr></thead>
<tbody>
@foreach($runs as $r)
@php $res = $r->results ?? []; @endphp
<tr class="border-t dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800">
<td class="py-1 font-mono">{{ $r->strategy_code }}</td>
<td>{{ $r->symbol }}</td><td>{{ $r->timeframe }}</td>
<td>{{ substr($r->from,0,10) }} → {{ substr($r->to,0,10) }}</td>
<td>{{ $res['trades'] ?? '–' }}</td>
<td>{{ $res['win_rate_pct'] ?? '–' }}%</td>
<td class="{{ (($res['net'] ?? 0) >= 0) ? 'text-green-600' : 'text-red-600' }} font-semibold">{{ isset($res['net']) ? sprintf('%+.2f', $res['net']) : '–' }}</td>
<td>{{ isset($res['net_pct']) ? sprintf('%+.2f%%', $res['net_pct']) : '–' }}</td>
<td>{{ isset($res['max_drawdown_pct']) ? sprintf('%.2f%%', $res['max_drawdown_pct']) : '–' }}</td>
<td><a href="{{ route('admin.gold.show', $r) }}" class="text-blue-600 hover:underline">Open →</a></td>
</tr>
@endforeach
</tbody>
</table>
{{ $runs->links() }}
</div>
@endsection
