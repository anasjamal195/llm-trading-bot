@extends('admin.layouts.app')
@section('title','GOLD Backtest · '.$run->strategy_code)
@section('content')
@php
  $res = $run->results ?? [];
  $net = $res['net'] ?? null;
  $up = ($net ?? 0) >= 0;
  $dur = function ($a, $b) {
    $s = max(0, strtotime($b) - strtotime($a));
    $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60);
    return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
  };
  $when = fn ($t) => \Carbon\Carbon::parse($t)->format('M d, h:i A');
@endphp
<div class="rounded-xl border mb-3 overflow-hidden {{ $up ? 'border-green-200 dark:border-green-900' : 'border-red-200 dark:border-red-900' }}">
<div class="px-4 py-4 {{ $up ? 'bg-gradient-to-r from-green-50 to-emerald-50 dark:from-green-950 dark:to-emerald-950' : 'bg-gradient-to-r from-red-50 to-rose-50 dark:from-red-950 dark:to-rose-950' }}">
<a href="{{ route('admin.gold.index') }}" class="text-blue-600 hover:underline text-xs">← All strategies</a>
<div class="flex flex-wrap items-end gap-x-4 gap-y-1 mt-1">
<div class="min-w-0">
<h1 class="font-mono font-bold text-base truncate">{{ $run->strategy_code }}</h1>
<div class="text-xs text-slate-500 mt-0.5"><span class="px-1.5 py-0.5 rounded bg-white/70 dark:bg-black/30">{{ $run->symbol }} · {{ $run->timeframe }}</span> {{ \Carbon\Carbon::parse($run->from)->format('M d, Y') }} → {{ \Carbon\Carbon::parse($run->to)->format('M d, Y') }} · ran {{ $run->created_at->diffForHumans() }} · UTC</div>
</div>
<span class="flex-1"></span>
<div class="text-right shrink-0">
<div class="text-[11px] font-semibold tracking-wider {{ $up ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">TOTAL RETURN</div>
<div class="font-mono font-extrabold text-3xl {{ $up ? 'text-green-600' : 'text-red-600' }}">{{ isset($res['net_pct']) ? sprintf('%+.2f%%', $res['net_pct']) : '–' }}</div>
<div class="font-mono text-xs {{ $up ? 'text-green-700 dark:text-green-300' : 'text-red-700 dark:text-red-300' }}">{{ $net !== null ? sprintf('%+.2f of %.0f start', $net, $res['starting_balance'] ?? 10000) : '' }}</div>
</div>
</div>
</div>
<div class="grid grid-cols-2 sm:grid-cols-5 gap-px bg-slate-100 dark:bg-slate-800 text-center">
<div class="bg-white dark:bg-slate-900 px-2 py-2.5"><div class="text-[11px] text-slate-500">TRADES / WINS</div><div class="font-bold text-base">{{ $res['trades'] ?? $trades->count() }} / {{ $res['wins'] ?? '–' }}</div><div class="text-[11px] font-semibold {{ ($res['win_rate_pct'] ?? 0) >= 50 ? 'text-green-600' : 'text-amber-600' }}">{{ $res['win_rate_pct'] ?? '–' }}% win</div></div>
<div class="bg-white dark:bg-slate-900 px-2 py-2.5"><div class="text-[11px] text-slate-500">MAX DRAWDOWN</div><div class="font-bold text-base text-red-600">{{ isset($res['max_drawdown_pct']) ? sprintf('%.2f%%', $res['max_drawdown_pct']) : '–' }}</div><div class="text-[11px] text-slate-500">peak → trough</div></div>
<div class="bg-white dark:bg-slate-900 px-2 py-2.5"><div class="text-[11px] text-slate-500">EXPECTANCY</div><div class="font-bold text-base {{ ($res['expectancy'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ isset($res['expectancy']) ? sprintf('%+.2f', $res['expectancy']) : '–' }}</div><div class="text-[11px] text-slate-500">per trade · costs {{ $res['costs'] ?? '–' }}</div></div>
<div class="bg-white dark:bg-slate-900 px-2 py-2.5"><div class="text-[11px] text-slate-500">EVALS</div><div class="font-bold text-base">{{ $res['evaluations'] ?? '–' }}</div><div class="text-[11px] text-slate-500">holds {{ $res['holds'] ?? '–' }} · brakes {{ $res['brakes'] ?? 0 }}</div></div>
<div class="bg-white dark:bg-slate-900 px-2 py-2.5"><div class="text-[11px] text-slate-500">FINAL EQUITY</div><div class="font-bold text-base">{{ isset($res['final_equity']) ? number_format($res['final_equity'], 2) : '–' }}</div><div class="text-[11px] text-slate-500">mode {{ $res['mode'] ?? '–' }}</div></div>
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
@endphp
<div class="rounded-lg border dark:border-slate-800 overflow-hidden mb-2">
<button class="w-full flex items-center gap-3 px-3 py-2.5 text-left hover:bg-slate-50 dark:hover:bg-slate-800/60" onclick="document.getElementById('detail-{{ $t->id }}').classList.toggle('hidden')">
<span class="text-[11px] text-slate-400 w-8 shrink-0">#{{ $k + 1 }}</span>
<span class="text-[11px] px-2 py-0.5 rounded font-bold shrink-0 {{ $win ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' }}">{{ $win ? 'WIN' : 'LOSS' }}</span>
<span class="text-[11px] px-2 py-0.5 rounded font-semibold shrink-0 {{ $t->direction === 'BUY' ? 'bg-sky-100 text-sky-800 dark:bg-sky-900 dark:text-sky-200' : 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200' }}">{{ $t->direction }}</span>
<span class="text-xs text-slate-600 dark:text-slate-300 min-w-0">opened <b>{{ $when($t->opened_at) }}</b> <span class="text-slate-400">· held {{ $dur($t->opened_at, $t->closed_at) }}</span></span>
<span class="flex-1"></span>
<span class="font-mono text-xs text-slate-500 shrink-0">{{ number_format($t->entry, 2) }} → {{ number_format($t->exit, 2) }}</span>
<span class="font-mono text-base font-extrabold w-28 text-center shrink-0 px-2 py-1 rounded-lg {{ $win ? 'text-green-700 bg-green-100 dark:bg-green-900 dark:text-green-200' : 'text-red-700 bg-red-100 dark:bg-red-900 dark:text-red-200' }}">{{ $t->pnl_pct !== null ? sprintf('%+.2f%%', $t->pnl_pct) : '–' }}</span>
<span class="shrink-0"><span class="inline-block text-[11px] rounded border px-2 py-1 text-blue-600" onclick="event.stopPropagation();showTrade({{ $t->id }})">Show ⌖</span></span>
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
