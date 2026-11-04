@extends('admin.layouts.app')
@section('title','Overview')
@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
  @php
  $cards = [
    ['Bot', $bot->status, $bot->status_message ?? ''],
    ['Balance', number_format($account->balance ?? 0, 2), 'USD'],
    ['Equity', number_format($equity, 2), 'incl. unrealized '.number_format($unrealized,2)],
    ['Daily P&L', number_format(\App\Models\PaperPosition::where('status','CLOSED')->whereDate('closed_at', today())->sum('realized_pnl'), 2), today()->toDateString()],
    ['Open', $openCount, 'positions'],
    ['Pending', $pendingCount, 'orders'],
    ['Win rate', ($metrics['win_rate'] ?? '-') . '%', $metrics['total_trades'].' trades'],
    ['Profit factor', $metrics['profit_factor'] ?? '-', 'net '.number_format($metrics['net_profit'],2)],
  ];
  @endphp
  @foreach($cards as [$label,$value,$sub])
  <div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
    <div class="text-xs text-slate-500">{{ $label }}</div>
    <div class="text-xl font-bold">{{ $value }}</div>
    <div class="text-xs text-slate-500">{{ $sub }}</div>
  </div>
  @endforeach
</div>
<div class="grid md:grid-cols-2 gap-3 mt-3">
  <div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
    <h2 class="font-semibold text-sm mb-2">Equity curve</h2>
    <canvas id="equity" height="120"></canvas>
  </div>
  <div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
    <h2 class="font-semibold text-sm mb-2">Pair rankings</h2>
    <table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Symbol</th><th>Score</th><th>Regime</th><th>Fit</th></tr></thead>
    <tbody>@foreach($rankings as $r)<tr class="border-t dark:border-slate-800"><td>{{ $r->symbol }}</td><td>{{ $r->score }}</td><td>{{ $r->regime }}</td><td>{{ $r->suitable ? '✓' : '—' }}</td></tr>@endforeach</tbody></table>
  </div>
  <div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
    <h2 class="font-semibold text-sm mb-2">Currency priorities</h2>
    <table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Ccy</th><th>Outlook</th><th>Conf</th><th>Updated</th></tr></thead>
    <tbody>@foreach($currencies as $c)<tr class="border-t dark:border-slate-800"><td>{{ $c->currency }}</td><td>{{ $c->outlook }}</td><td>{{ $c->confidence }}</td><td>{{ $c->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table>
  </div>
  <div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
    <h2 class="font-semibold text-sm mb-2">Recent news</h2>
    <ul class="text-xs space-y-1">@foreach($news as $n)<li><span class="text-slate-500">{{ $n->currency }} · {{ $n->impact }}</span> {{ $n->headline }}</li>@endforeach</ul>
  </div>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3">
  <h2 class="font-semibold text-sm mb-2">Recent positions</h2>
  <table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Dir</th><th>Entry</th><th>Exit</th><th>P&L</th><th>Status</th></tr></thead>
  <tbody>@foreach($recentPositions as $p)<tr class="border-t dark:border-slate-800"><td><a class="underline" href="{{ route('admin.trades.detail',$p) }}">{{ $p->id }}</a></td><td>{{ $p->symbol }}</td><td>{{ $p->direction }}</td><td>{{ $p->entry_price }}</td><td>{{ $p->exit_price ?? '—' }}</td><td class="{{ $p->realized_pnl < 0 ? 'text-red-500' : 'text-emerald-500' }}">{{ $p->status==='OPEN' ? $p->unrealized_pnl : $p->realized_pnl }}</td><td>{{ $p->status }}</td></tr>@endforeach</tbody></table>
</div>
@endsection
@section('scripts')
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('equity'), {type:'line',
  data:{labels:@json($equityCurve->pluck('snapshot_date')), datasets:[{data:@json($equityCurve->pluck('equity')), borderColor:'#10b981', pointRadius:0, tension:.2}]},
  options:{plugins:{legend:{display:false}}, scales:{x:{display:false}}}});
</script>
@endsection
