@extends('admin.layouts.app')
@section('title','GOLD Backtest · '.$run->strategy_code)
@section('content')
@php
  $res = $run->results ?? [];
  $net = $res['net'] ?? null;
  $dur = function ($a, $b) {
    $s = max(0, strtotime($b) - strtotime($a));
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
  };
  $when = fn ($t) => \Carbon\Carbon::parse($t)->format('M d, h:i A');
@endphp
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-4 mb-3">
<a href="{{ route('admin.gold.index') }}" class="text-blue-600 hover:underline text-xs">← All strategies</a>
<div class="flex flex-wrap items-baseline gap-x-3 mt-1">
<h1 class="font-mono font-bold text-base">{{ $run->strategy_code }}</h1>
<span class="text-xs px-1.5 py-0.5 rounded bg-slate-100 dark:bg-slate-800">{{ $run->symbol }} · {{ $run->timeframe }}</span>
<span class="text-xs text-slate-500">{{ \Carbon\Carbon::parse($run->from)->format('M d, Y') }} → {{ \Carbon\Carbon::parse($run->to)->format('M d, Y') }} · ran {{ $run->created_at->diffForHumans() }} · all times UTC</span>
</div>
<div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mt-3 text-center">
<div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2 py-2"><div class="text-[11px] text-slate-500">TRADES / WINS</div><div class="font-bold">{{ $res['trades'] ?? $trades->count() }} / {{ $res['wins'] ?? '–' }}</div><div class="text-[11px] text-slate-500">{{ $res['win_rate_pct'] ?? '–' }}% win</div></div>
<div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2 py-2"><div class="text-[11px] text-slate-500">NET P&L</div><div class="font-bold {{ ($net ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ $net !== null ? sprintf('%+.2f', $net) : '–' }}</div><div class="text-[11px] {{ ($res['net_pct'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ isset($res['net_pct']) ? sprintf('%+.2f%% of start', $res['net_pct']) : '' }}</div></div>
<div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2 py-2"><div class="text-[11px] text-slate-500">MAX DRAWDOWN</div><div class="font-bold text-red-600">{{ isset($res['max_drawdown_pct']) ? sprintf('%.2f%%', $res['max_drawdown_pct']) : '–' }}</div><div class="text-[11px] text-slate-500">peak → trough</div></div>
<div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2 py-2"><div class="text-[11px] text-slate-500">EXPECTANCY</div><div class="font-bold">{{ isset($res['expectancy']) ? sprintf('%+.2f / trade', $res['expectancy']) : '–' }}</div><div class="text-[11px] text-slate-500">costs {{ $res['costs'] ?? '–' }}</div></div>
<div class="rounded-lg bg-slate-50 dark:bg-slate-800/60 px-2 py-2"><div class="text-[11px] text-slate-500">EVALS</div><div class="font-bold">{{ $res['evaluations'] ?? '–' }}</div><div class="text-[11px] text-slate-500">holds {{ $res['holds'] ?? '–' }} · brakes {{ $res['brakes'] ?? 0 }}</div></div>
</div>
</div>

<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-4 mb-3">
<div class="flex items-center gap-3 mb-2 text-xs text-slate-500">
<span>🔵 entry · <span class="text-red-600">┄ stop</span> · <span class="text-green-600">┄ take-profit</span> · ▲▼ markers = fill / exit</span>
<span class="flex-1"></span>
<button id="fit-all" class="rounded border px-2 py-1 text-slate-700 dark:text-slate-200">Fit all</button>
</div>
<div id="chart" class="w-full" style="height:480px"></div>
<div id="chart-err" class="hidden text-xs text-red-600 mt-2"></div>
</div>

<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-4">
<h2 class="font-bold text-sm mb-2">Trades <span class="text-slate-400 font-normal">({{ $trades->count() }} — click a row for details)</span></h2>
<div class="grid gap-1.5">
@foreach($trades as $k => $t)
@php
  $win = $t->pnl >= 0;
  $pct = $res['starting_balance'] ?? 10000;
@endphp
<div class="rounded-lg border dark:border-slate-800 overflow-hidden">
<button class="w-full flex flex-wrap items-center gap-x-3 gap-y-0.5 px-3 py-2 text-left hover:bg-slate-50 dark:hover:bg-slate-800/60" onclick="document.getElementById('detail-{{ $t->id }}').classList.toggle('hidden')">
<span class="text-[11px] text-slate-400 w-7">#{{ $k + 1 }}</span>
<span class="text-[11px] px-1.5 py-0.5 rounded font-semibold {{ $t->direction === 'BUY' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">{{ $t->direction }}</span>
<span class="text-xs text-slate-600 dark:text-slate-300">opened <b>{{ $when($t->opened_at) }}</b> <span class="text-slate-400">· held {{ $dur($t->opened_at, $t->closed_at) }}</span></span>
<span class="flex-1"></span>
<span class="font-mono text-xs">{{ number_format($t->entry, 2) }} → {{ number_format($t->exit, 2) }}</span>
<span class="font-mono text-sm font-bold {{ $win ? 'text-green-600' : 'text-red-600' }}">{{ sprintf('%+.2f', $t->pnl) }}</span>
<button class="text-[11px] rounded border px-2 py-0.5 text-blue-600" onclick="event.stopPropagation();showTrade({{ $t->id }})">Show ⌖</button>
</button>
<div id="detail-{{ $t->id }}" class="hidden px-3 py-2 text-xs bg-slate-50 dark:bg-slate-800/50 border-t dark:border-slate-800 grid sm:grid-cols-2 gap-x-6 gap-y-1">
<div>Close reason: <b>{{ $t->close_reason }}</b> at {{ $when($t->closed_at) }}</div>
<div>Volume <b>{{ $t->volume }}</b> · Costs <b>{{ $t->costs }}</b></div>
<div>Stop-loss <b class="font-mono text-red-600">{{ $t->stop_loss }}</b> · Take-profit <b class="font-mono text-green-600">{{ $t->take_profit ?? 'trailing' }}</b></div>
<div class="sm:col-span-2 text-slate-500">LLM rationale: <i>{{ $t->rationale ?? '–' }}</i></div>
</div>
</div>
@endforeach
</div>
</div>
@endsection

@section('scripts')
<script src="https://unpkg.com/lightweight-charts@4.2.3/dist/lightweight-charts.standalone.production.js"></script>
<script>
const TF_SEC = {M1:60,M5:300,M15:900,M30:1800,H1:3600,H4:14400,D1:86400};
const tfSec = TF_SEC[@json($run->timeframe)] || 3600;
const API = '/admin/api/backtest/gold/{{ $run->id }}/candles'; // relative: inherits page protocol, no mixed-content
let chart, candleSeries;
const tradeOverlays = {};

async function init() {
  const err = document.getElementById('chart-err');
  try {
    if (typeof LightweightCharts === 'undefined') throw new Error('chart library failed to load (CDN blocked?)');
    const res = await fetch(API, { headers: { 'Accept': 'application/json' } });
    if (!res.ok) throw new Error('candles API HTTP ' + res.status);
    const data = await res.json();
    if (!data.candles || !data.candles.length) throw new Error('no candles returned');
    const el = document.getElementById('chart');
    chart = LightweightCharts.createChart(el, { width: el.clientWidth, height: 480, timeScale: { timeVisible: true } });
    candleSeries = chart.addCandlestickSeries({});
    candleSeries.setData(data.candles);
    const markers = [];
    for (const t of data.trades) {
      tradeOverlays[t.id] = t;
      const long = t.dir === 'BUY';
      markers.push({ time: t.open_t, position: long ? 'belowBar' : 'aboveBar', color: '#2962FF', shape: long ? 'arrowUp' : 'arrowDown', text: '#' + t.n + ' ' + t.dir });
      markers.push({ time: t.close_t, position: long ? 'aboveBar' : 'belowBar', color: t.pnl >= 0 ? '#26a69a' : '#ef5350', shape: 'circle', text: '#' + t.n + ' ' + (t.pnl >= 0 ? '+' : '') + t.pnl.toFixed(2) });
      const seg = (price, color, dashed) => {
        if (price === null) return;
        const s = chart.addLineSeries({ color, lineWidth: 1, lineStyle: dashed ? LightweightCharts.LineStyle.Dashed : LightweightCharts.LineStyle.Solid, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
        s.setData([{ time: t.open_t, value: price }, { time: t.close_t, value: price }]);
      };
      seg(t.entry, '#2962FF', false);
      seg(t.sl, '#ef5350', true);
      seg(t.tp, '#26a69a', true);
    }
    markers.sort((a, b) => a.time - b.time);
    candleSeries.setMarkers(markers);
    chart.timeScale().fitContent();
    document.getElementById('fit-all').onclick = () => chart.timeScale().fitContent();
    window.addEventListener('resize', () => chart.applyOptions({ width: el.clientWidth }));
  } catch (e) {
    err.textContent = 'Chart failed to load: ' + e.message;
    err.classList.remove('hidden');
  }
}

function showTrade(id) {
  const t = tradeOverlays[id];
  if (!t || !chart) return;
  const pad = 12 * tfSec;
  chart.timeScale().setVisibleRange({ from: t.open_t - pad, to: t.close_t + pad });
  document.getElementById('chart').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

init();
</script>
@endsection
