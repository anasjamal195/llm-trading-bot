@extends('admin.layouts.app')
@section('title','Performance Analytics')
@section('content')
<div class="grid grid-cols-2 md:grid-cols-4 gap-3">
@foreach([['Trades',$global['total_trades'],''],['Win rate',($global['win_rate']??'-').'%', ''],['Net',number_format($global['net_profit'],2),''],['Profit factor',$global['profit_factor'] ?? '-', ''],['Expectancy',number_format($global['expectancy'],2),''],['Max DD',number_format($global['max_drawdown'],2),''],['Costs',number_format($global['costs'],2),''],['Avg W/L', $global['avg_win'].' / '.$global['avg_loss'],'']] as [$l,$v,$s])
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3"><div class="text-xs text-slate-500">{{ $l }}</div><div class="text-xl font-bold">{{ $v }}</div></div>
@endforeach
</div>
<div class="grid md:grid-cols-2 gap-3 mt-3">
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3"><h2 class="font-semibold text-sm mb-2">By strategy</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Strategy</th><th>Trades</th><th>WR%</th><th>Net</th><th>PF</th></tr></thead>
<tbody>@foreach($byStrategy as $s=>$m)<tr class="border-t dark:border-slate-800"><td>{{ $s }}</td><td>{{ $m['total_trades'] }}</td><td>{{ $m['win_rate'] }}</td><td>{{ $m['net_profit'] }}</td><td>{{ $m['profit_factor'] }}</td></tr>@endforeach</tbody></table></div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3"><h2 class="font-semibold text-sm mb-2">By pair</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Pair</th><th>Trades</th><th>WR%</th><th>Net</th><th>PF</th></tr></thead>
<tbody>@foreach($bySymbol as $s=>$m)<tr class="border-t dark:border-slate-800"><td>{{ $s }}</td><td>{{ $m['total_trades'] }}</td><td>{{ $m['win_rate'] }}</td><td>{{ $m['net_profit'] }}</td><td>{{ $m['profit_factor'] }}</td></tr>@endforeach</tbody></table></div>
</div>
@endsection
