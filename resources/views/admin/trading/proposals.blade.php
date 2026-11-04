@extends('admin.layouts.app')
@section('title','Trade Proposals')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Dir</th><th>Type</th><th>Entry</th><th>SL</th><th>TP</th><th>RR</th><th>Strategy</th><th>Conf</th><th>Status</th><th>Validation</th><th>When</th></tr></thead>
<tbody>@foreach($proposals as $p)<tr class="border-t dark:border-slate-800"><td>{{ $p->id }}</td><td>{{ $p->symbol }}</td><td>{{ $p->direction }}</td><td>{{ $p->order_type }}</td><td>{{ $p->entry }}</td><td>{{ $p->stop_loss }}</td><td>{{ $p->take_profit }}</td><td>{{ $p->risk_reward }}</td><td>{{ $p->strategy_code }}</td><td>{{ $p->confidence }}</td><td>{{ $p->status }}</td><td class="max-w-xs truncate" title="{{ $p->validation_result }}">{{ $p->validation_result }}</td><td>{{ $p->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table>
{{ $proposals->links() }}
</div>
@endsection
