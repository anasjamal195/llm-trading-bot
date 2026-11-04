@extends('admin.layouts.app')
@section('title','Market · '.$symbol)
@section('content')
<div class="flex gap-2 text-sm mb-3">
@foreach(['M1','M15','H1','H4','D1'] as $tf)
<a href="{{ route('admin.market.symbol', ['symbol'=>$symbol,'tf'=>$tf]) }}" class="px-2 py-1 rounded border {{ $timeframe===$tf?'bg-slate-800 text-white':' ' }}">{{ $tf }}</a>
@endforeach
<span class="flex-1"></span>
<span class="text-xs text-slate-500">Trend {{ $snapshot->trend ?? '—' }} · Regime {{ $snapshot->regime ?? '—' }} · RSI {{ $snapshot->indicators['rsi14'] ?? '—' }} · ATR {{ $snapshot->atr ?? '—' }}</span>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<canvas id="candles" height="140"></canvas>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3">
<h2 class="font-semibold text-sm mb-2">Positions — {{ $symbol }}</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Dir</th><th>Entry</th><th>SL</th><th>TP</th><th>Status</th><th>P&L</th></tr></thead>
<tbody>@foreach($positions as $p)<tr class="border-t dark:border-slate-800"><td>{{ $p->id }}</td><td>{{ $p->direction }}</td><td>{{ $p->entry_price }}</td><td>{{ $p->stop_loss }}</td><td>{{ $p->take_profit }}</td><td>{{ $p->status }}</td><td>{{ $p->status==='OPEN'?$p->unrealized_pnl:$p->realized_pnl }}</td></tr>@endforeach</tbody></table>
</div>
@endsection
@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
const rows = @json($candles->map(fn($c)=>['t'=>$c->candle_time->format('m-d H:i'),'c'=>(float)$c->close,'h'=>(float)$c->high,'l'=>(float)$c->low]));
new Chart(document.getElementById('candles'),{type:'line',
data:{labels:rows.map(r=>r.t),datasets:[{label:'close',data:rows.map(r=>r.c),borderColor:'#3b82f6',pointRadius:0,tension:.1},{label:'high',data:rows.map(r=>r.h),borderColor:'#94a3b855',pointRadius:0},{label:'low',data:rows.map(r=>r.l),borderColor:'#94a3b855',pointRadius:0}]},
options:{plugins:{legend:{display:false}}}});
</script>
@endsection
