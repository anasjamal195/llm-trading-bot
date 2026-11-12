@extends('admin.layouts.app')
@section('title','GOLD Backtest')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-4">
<div class="flex items-center gap-2 mb-3">
<h1 class="font-bold text-base">GOLD Backtest Strategies</h1>
<span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 dark:bg-slate-800 text-slate-500">{{ $runs->total() }} runs</span>
<span class="flex-1"></span>
<span class="text-xs text-slate-400">Every <code>bot:backtest-gold-llm</code> run saves here automatically</span>
</div>
<div class="grid gap-2">
@foreach($runs as $r)
@php
  $res = $r->results ?? [];
  $net = $res['net'] ?? null;
  $pos = $net !== null && $net >= 0;
@endphp
<a href="{{ route('admin.gold.show', $r) }}" class="flex flex-wrap items-center gap-x-4 gap-y-1 rounded-lg border dark:border-slate-800 px-3 py-2.5 hover:border-blue-400 hover:shadow-sm transition">
<span class="font-mono text-xs font-semibold truncate max-w-xs">{{ $r->strategy_code }}</span>
<span class="text-[11px] px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800 text-slate-600 dark:text-slate-300">{{ $r->symbol }} · {{ $r->timeframe }}</span>
<span class="text-xs text-slate-500">{{ \Carbon\Carbon::parse($r->from)->format('M d, Y') }} → {{ \Carbon\Carbon::parse($r->to)->format('M d, Y') }}</span>
<span class="flex-1"></span>
<span class="text-xs text-slate-500">{{ $res['trades'] ?? '–' }} trades · {{ $res['win_rate_pct'] ?? '–' }}% win</span>
<span class="text-xs text-slate-500">DD {{ isset($res['max_drawdown_pct']) ? sprintf('%.2f%%', $res['max_drawdown_pct']) : '–' }}</span>
<span class="font-mono text-sm font-bold {{ $pos ? 'text-green-600' : 'text-red-600' }}">{{ $net !== null ? sprintf('%+.2f (%+.2f%%)', $net, $res['net_pct'] ?? 0) : '–' }}</span>
<span class="text-[11px] text-slate-400" title="{{ $r->created_at }}">ran {{ $r->created_at->diffForHumans() }}</span>
<span class="text-blue-600 text-xs">Open →</span>
</a>
@endforeach
</div>
<div class="mt-3">{{ $runs->links() }}</div>
</div>
@endsection
