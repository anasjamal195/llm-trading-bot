@extends('admin.layouts.app')
@section('title','Pending Orders')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Type</th><th>Entry</th><th>SL</th><th>TP</th><th>Vol</th><th>Risk</th><th>Expires</th><th></th></tr></thead>
<tbody>@foreach($orders as $o)<tr class="border-t dark:border-slate-800"><td>{{ $o->id }}</td><td>{{ $o->symbol }}</td><td>{{ $o->order_type }}</td><td>{{ $o->entry_price }}</td><td>{{ $o->stop_loss }}</td><td>{{ $o->take_profit }}</td><td>{{ $o->volume }}</td><td>{{ $o->risk_amount }}</td><td>{{ $o->expires_at->diffForHumans() }}</td><td><form method="POST" action="{{ route('admin.orders.cancel',$o) }}">@csrf<button class="text-red-500 underline">Cancel</button></form></td></tr>@endforeach</tbody></table>
{{ $orders->links() }}
</div>
@endsection
