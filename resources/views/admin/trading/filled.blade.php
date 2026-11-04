@extends('admin.layouts.app')
@section('title','Filled Orders')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 overflow-x-auto">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Symbol</th><th>Type</th><th>Fill</th><th>Costs</th><th>Ambiguous</th><th>Filled at</th></tr></thead>
<tbody>@foreach($orders as $o)<tr class="border-t dark:border-slate-800"><td>{{ $o->id }}</td><td>{{ $o->symbol }}</td><td>{{ $o->order_type }}</td><td>{{ $o->fill_price }}</td><td>{{ $o->position->costs ?? '—' }}</td><td>{{ $o->ambiguous_fill ? 'yes' : 'no' }}</td><td>{{ $o->filled_at }}</td></tr>@endforeach</tbody></table>
{{ $orders->links() }}
</div>
@endsection
