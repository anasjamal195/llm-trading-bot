@extends('admin.layouts.app')
@section('title','Live Market')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<table class="w-full text-sm">
<thead><tr class="text-left text-xs text-slate-500"><th>Pair</th><th>Price</th><th>Trend</th><th>Regime</th><th>Score</th><th>Fit</th><th>Updated</th></tr></thead>
<tbody>
@foreach($symbols as $s)
<tr class="border-t dark:border-slate-800">
<td><a class="underline font-semibold" href="{{ route('admin.market.symbol',$s->symbol) }}">{{ $s->symbol }}</a></td>
<td data-price="{{ $s->symbol }}">{{ $s->last_snapshot->price ?? $s->last_candle->close ?? '—' }}</td>
<td>{{ $s->last_snapshot->trend ?? '—' }}</td>
<td>{{ $s->last_snapshot->regime ?? $s->ranking->regime ?? '—' }}</td>
<td>{{ $s->ranking->score ?? '—' }}</td>
<td>{{ $s->ranking ? ($s->ranking->suitable ? '✓' : '—') : '—' }}</td>
<td class="text-xs text-slate-500">{{ $s->last_candle->candle_time ?? '—' }}</td>
</tr>
@endforeach
</tbody></table>
</div>
@endsection
