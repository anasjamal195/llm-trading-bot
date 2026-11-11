@extends('admin.layouts.app')
@section('title','GOLD Backtest · '.$run->strategy_code)
@section('content')
@php $res = $run->results ?? []; @endphp
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mb-3 text-sm">
<a href="{{ route('admin.gold.index') }}" class="text-blue-600 hover:underline text-xs">← All strategies</a>
<h1 class="font-mono font-bold text-base mt-1">{{ $run->strategy_code }}</h1>
<div class="text-xs text-slate-500">{{ $run->symbol }} {{ $run->timeframe }} · {{ substr($run->from,0,10) }} → {{ substr($run->to,0,10) }}</div>
<div class="flex flex-wrap gap-4 mt-2 text-xs">
<span>Trades <b>{{ $res['trades'] ?? $trades->count() }}</b> / Wins <b>{{ $res['wins'] ?? '–' }}</b> ({{ $res['win_rate_pct'] ?? '–' }}%)</span>
<span>Net <b class="{{ (($res['net'] ?? 0) >= 0) ? 'text-green-600' : 'text-red-600' }}">{{ isset($res['net']) ? sprintf('%+.2f (%+.2f%%)', $res['net'], $res['net_pct']) : '–' }}</b></span>
<span>Max DD <b>{{ isset($res['max_drawdown_pct']) ? sprintf('%.2f%%', $res['max_drawdown_pct']) : '–' }}</b></span>
<span>Expectancy <b>{{ isset($res['expectancy']) ? sprintf('%+.2f', $res['expectancy']) : '–' }}</b></span>
<span>Costs <b>{{ $res['costs'] ?? '–' }}</b></span>
</div>
</div>

<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mb-3">
<div class="flex items-center gap-3 mb-2 text-xs">
<span class="text-slate-500">Chart: markers = entries/exits · <span class="text-blue-600">― entry</span> · <span class="text-red-600">- - stop</span> · <span class="text-green-600">- - take-profit/trail</span> (segments span each trade)</span>
<span class="flex-1"></span>
<button id="fit-all" class="rounded border px-2 py-1">Fit all</button>
</div>
<div id="chart" class="w-full" style="height:480px"></div>
</div>

<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<table class="w-full text-xs" id="trades">
<thead><tr class="text-left text-slate-500"><th>#</th><th>Fill</th><th>Dir</th><th>Entry</th><th>Exit</th><th>SL</th><th>TP</th><th>P&L</th><th>Reason</th><th></th></tr></thead>
<tbody>
@foreach($trades as $k => $t)
<tr class="border-t dark:border-slate-800 hover:bg-slate-50 dark:hover:bg-slate-800 cursor-pointer" data-trade="{{ $t->id }}" onclick="toggleDetail({{ $t->id }})">
<td>{{ $k + 1 }}</td><td class="font-mono">{{ substr($t->opened_at,0,16) }}</td>
<td><span class="px-1 rounded {{ $t->direction === 'BUY' ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $t->direction }}</span></td>
<td class="font-mono">{{ $t->entry }}</td><td class="font-mono">{{ $t->exit }}</td>
<td class="font-mono text-red-600">{{ $t->stop_loss }}</td><td class="font-mono text-green-600">{{ $t->take_profit ?? 'trail' }}</td>
<td class="font-mono font-semibold {{ $t->pnl >= 0 ? 'text-green-600' : 'text-red-600' }}">{{ sprintf('%+.2f', $t->pnl) }}</td>
<td>{{ $t->close_reason }} <span class="text-slate-400">{{ substr($t->closed_at,0,16) }}</span></td>
<td><button class="rounded border px-2 py-0.5 text-blue-600" onclick="event.stopPropagation();showTrade({{ $t->id }})">Show</button></td>
</tr>
<tr id="detail-{{ $t->id }}" class="hidden border-t dark:border-slate-800 bg-slate-50 dark:bg-slate-800/50">
<td colspan="10" class="px-4 py-2 text-slate-600 dark:text-slate-300">
<div>Volume <b>{{ $t->volume }}</b> · Costs <b>{{ $t->costs }}</b> · Fill <b class="font-mono">{{ $t->opened_at }}</b> → Close <b class="font-mono">{{ $t->closed_at }}</b></div>
<div class="mt-1">Rationale: <i>{{ $t->rationale ?? '–' }}</i></div>
</td>
</tr>
@endforeach
</tbody>
</table>
</div>
@endsection

@section('scripts')
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
const TF_SEC = {M1:60,M5:300,M15:900,M30:1800,H1:3600,H4:14400,D1:86400};
const tfSec = TF_SEC[@json($run->timeframe)] || 3600;
let chart, candleSeries;
const tradeOverlays = {}; // id -> overlay

async function init() {
  const res = await fetch(@json(route('admin.gold.candles', $run)));
  const data = await res.json();
  const el = document.getElementById('chart');
  chart = LightweightCharts.createChart(el, {
    width: el.clientWidth, height: 480,
    timeScale: { timeVisible: true, secondsVisible: false },
  });
  candleSeries = chart.addCandlestickSeries({ upColor: '#26a69a', downColor: '#ef5350', wickUpColor: '#26a69a', wickDownColor: '#ef5350' });
  candleSeries.setData(data.candles);

  const markers = [];
  for (const t of data.trades) {
    tradeOverlays[t.id] = t;
    const long = t.dir === 'BUY';
    markers.push({ time: t.open_t, position: long ? 'belowBar' : 'aboveBar', color: '#2962FF', shape: long ? 'arrowUp' : 'arrowDown', text: '#' + t.n + ' ' + t.dir + ' ' + t.entry });
    markers.push({ time: t.close_t, position: long ? 'aboveBar' : 'belowBar', color: t.pnl >= 0 ? '#26a69a' : '#ef5350', shape: 'circle', text: '#' + t.n + ' ' + (t.pnl >= 0 ? '+' : '') + t.pnl });
    // Entry (solid blue) + SL (red dashed) + TP (green dashed) segments over the trade window.
    const seg = (price, color, style) => {
      if (price === null) return;
      const s = chart.addLineSeries({ color, lineWidth: 1, lineStyle: style, priceLineVisible: false, lastValueVisible: false, crosshairMarkerVisible: false });
      s.setData([{ time: t.open_t, value: price }, { time: t.close_t, value: price }]);
    };
    seg(t.entry, '#2962FF', LightweightCharts.LineStyle.Solid);
    seg(t.sl, '#ef5350', LightweightCharts.LineStyle.Dashed);
    seg(t.tp, '#26a69a', LightweightCharts.LineStyle.Dashed);
  }
  markers.sort((a, b) => a.time - b.time);
  candleSeries.setMarkers(markers);
  chart.timeScale().fitContent();
  document.getElementById('fit-all').onclick = () => chart.timeScale().fitContent();
  window.addEventListener('resize', () => chart.applyOptions({ width: el.clientWidth }));
}

function showTrade(id) {
  const t = tradeOverlays[id];
  if (!t || !chart) return;
  const pad = 12 * tfSec;
  chart.timeScale().setVisibleRange({ from: t.open_t - pad, to: t.close_t + pad });
  document.getElementById('chart').scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function toggleDetail(id) {
  document.getElementById('detail-' + id).classList.toggle('hidden');
}

init();
</script>
@endsection
