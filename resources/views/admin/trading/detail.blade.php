@extends('admin.layouts.app')
@section('title','Trade #'.$position->id)
@section('content')
<div class="grid md:grid-cols-2 gap-3 text-sm">
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 md:col-span-2">
<div class="flex gap-2 text-xs mb-2 items-center">
<span class="font-semibold">Position chart — {{ $position->symbol }} <span id="chart-tf" class="text-slate-500">M1</span></span>
@foreach(['M1','M5','M15','H1'] as $tf)<button data-tf="{{ $tf }}" class="tfbtn px-2 py-0.5 rounded border {{ $tf==='M1'?'bg-slate-800 text-white':'' }}">{{ $tf }}</button>@endforeach
<span class="flex-1"></span><span id="chart-live" class="text-slate-500"></span>
</div>
<div id="tv" style="height:340px"></div>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold mb-2">Position</h2>
<dl class="grid grid-cols-2 gap-1 text-xs">
<dt class="text-slate-500">Symbol</dt><dd>{{ $position->symbol }} {{ $position->direction }}</dd>
<dt class="text-slate-500">Entry → Exit</dt><dd>{{ $position->entry_price }} → {{ $position->exit_price ?? 'open @ '.$position->current_price }}</dd>
<dt class="text-slate-500">SL / TP</dt><dd>{{ $position->stop_loss }} / {{ $position->take_profit }}</dd>
<dt class="text-slate-500">Peak</dt><dd>{{ $position->peak_price ?? '—' }}</dd>
<dt class="text-slate-500">Volume</dt><dd>{{ $position->volume }} lots</dd>
<dt class="text-slate-500">P&L</dt><dd>{{ $position->status==='OPEN' ? $position->unrealized_pnl : $position->realized_pnl }} ({{ $position->movePct() }}% · {{ $position->rMultiple() ?? '—' }}R)</dd>
<dt class="text-slate-500">Costs</dt><dd>{{ $position->costs }}</dd>
<dt class="text-slate-500">Reason</dt><dd>{{ $position->close_reason ?? '—' }}</dd>
<dt class="text-slate-500">Strategy</dt><dd>{{ $position->strategy_code ?? '—' }}</dd>
<dt class="text-slate-500">Thesis</dt><dd>{{ $position->thesis ?? '—' }}</dd>
</dl>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold mb-2">Proposal & validation</h2>
@if($proposal)<dl class="grid grid-cols-2 gap-1 text-xs">
<dt class="text-slate-500">Confidence</dt><dd>{{ $proposal->confidence }}</dd>
<dt class="text-slate-500">Rationale</dt><dd>{{ $proposal->rationale }}</dd>
<dt class="text-slate-500">Invalidation</dt><dd>{{ $proposal->invalidation }}</dd>
<dt class="text-slate-500">Model</dt><dd>{{ $proposal->model }}</dd>
</dl>@else<p class="text-xs text-slate-500">No linked proposal.</p>@endif
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold mb-2">Post-trade analysis</h2>
@if($analysis)<div class="text-xs space-y-1"><p><b>Prediction:</b> {{ $analysis->prediction_summary }}</p><p><b>Outcome:</b> {{ $analysis->outcome_summary }}</p><p>Entry {{ $analysis->entry_quality }} · Exit {{ $analysis->exit_quality }}</p><p><b>Lessons:</b> {{ $analysis->lessons }}</p></div>
@else<p class="text-xs text-slate-500">Pending analysis.</p>@endif
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold mb-2">Ledger</h2>
<table class="w-full text-xs"><tbody>@foreach($transactions as $t)<tr class="border-t dark:border-slate-800"><td>{{ $t->type }}</td><td>{{ $t->amount }}</td><td>{{ $t->notes }}</td></tr>@endforeach</tbody></table>
</div>
</div>
@endsection
@section('scripts')
<script src="https://unpkg.com/lightweight-charts@4.2.0/dist/lightweight-charts.standalone.production.js"></script>
<script>
(function(){
const sym = @json($position->symbol);
const entry = parseFloat(@json($position->entry_price));
const sl = parseFloat(@json($position->stop_loss));
const tp = parseFloat(@json($position->take_profit));
const isBuy = @json($position->direction) === 'BUY';
const exitP = @json($position->exit_price);
const openedAt = @json($position->opened_at ? $position->opened_at->timestamp : null);
const closedAt = @json($position->closed_at ? $position->closed_at->timestamp : null);
const el = document.getElementById('tv');
const chart = LightweightCharts.createChart(el, {layout:{background:{color:'transparent'},textColor:'#94a3b8'},grid:{vertLines:{color:'#1e293b33'},horzLines:{color:'#1e293b33'}},timeScale:{timeVisible:true,secondsVisible:false}});
const series = chart.addCandlestickSeries({upColor:'#10b981',downColor:'#ef4444',wickUpColor:'#10b981',wickDownColor:'#ef4444'});
let curTf = 'M1';
function lines(){
  series.createPriceLine({price:entry,title:'Entry '+entry,color:'#3b82f6',lineWidth:2,lineStyle:LightweightCharts.LineStyle.Solid,axisLabelVisible:true});
  series.createPriceLine({price:sl,title:'SL '+sl,color:'#ef4444',lineWidth:1,lineStyle:LightweightCharts.LineStyle.Dashed,axisLabelVisible:true});
  series.createPriceLine({price:tp,title:'TP '+tp,color:'#10b981',lineWidth:1,lineStyle:LightweightCharts.LineStyle.Dashed,axisLabelVisible:true});
}
async function load(tf){
  curTf = tf;
  document.getElementById('chart-tf').textContent = tf;
  document.querySelectorAll('.tfbtn').forEach(b=>b.classList.toggle('bg-slate-800',b.dataset.tf===tf));
  const r = await fetch(`/admin/api/candles?symbol=${sym}&tf=${tf}&limit=200`,{headers:{'Accept':'application/json'}});
  const j = await r.json();
  series.setData(j.candles);
  const snap = t => { if(!t) return null; let best=j.candles[0]?.time; for(const c of j.candles){ if(c.time<=t) best=c.time; else break; } return best; };
  series.setMarkers([
    ...(snap(openedAt)?[{time:snap(openedAt),position:isBuy?'belowBar':'aboveBar',color:'#3b82f6',shape:isBuy?'arrowUp':'arrowDown',text:(isBuy?'Buy ':'Sell ')+entry}]:[]),
    ...(snap(closedAt)&&exitP?[{time:snap(closedAt),position:isBuy?'aboveBar':'belowBar',color:'#f59e0b',shape:isBuy?'arrowDown':'arrowUp',text:'Close '+exitP}]:[]),
  ]);
  chart.timeScale().scrollToRealTime();
  document.getElementById('chart-live').textContent = j.candles.length+' bars';
}
document.querySelectorAll('.tfbtn').forEach(b=>b.addEventListener('click',()=>load(b.dataset.tf)));
lines(); load('M1');
})();
</script>
@endsection
