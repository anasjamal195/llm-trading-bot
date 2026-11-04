@extends('admin.layouts.app')
@section('title','Trade #'.$position->id)
@section('content')
<div class="grid md:grid-cols-2 gap-3 text-sm">
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold mb-2">Position</h2>
<dl class="grid grid-cols-2 gap-1 text-xs">
<dt class="text-slate-500">Symbol</dt><dd>{{ $position->symbol }} {{ $position->direction }}</dd>
<dt class="text-slate-500">Entry → Exit</dt><dd>{{ $position->entry_price }} → {{ $position->exit_price ?? 'open @ '.$position->current_price }}</dd>
<dt class="text-slate-500">SL / TP</dt><dd>{{ $position->stop_loss }} / {{ $position->take_profit }}</dd>
<dt class="text-slate-500">Volume</dt><dd>{{ $position->volume }} lots</dd>
<dt class="text-slate-500">P&L</dt><dd>{{ $position->status==='OPEN' ? $position->unrealized_pnl : $position->realized_pnl }} (costs {{ $position->costs }})</dd>
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
