@extends('admin.layouts.app')
@section('title','AI Agents')
@section('content')
<div class="grid md:grid-cols-2 gap-3">
@foreach($agents as $a)
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 text-sm">
<div class="flex items-center gap-2"><b>{{ $a['name'] }}</b><span class="text-xs px-1.5 rounded {{ $a['enabled']?'bg-emerald-100 text-emerald-800':'bg-slate-200 text-slate-600' }}">{{ $a['enabled']?'enabled':'disabled' }}</span><span class="flex-1"></span>
<form method="POST" action="{{ route('admin.agents.toggle',$a['name']) }}">@csrf<button class="text-xs underline">toggle</button></form></div>
<div class="text-xs text-slate-500 mt-1">model: {{ $a['model'] }} · calls {{ $a['calls'] }} · tokens {{ $a['tokens'] }} · ${{ $a['cost'] }} · errors {{ $a['errors'] }}</div>
<div class="text-xs mt-1">last: {{ $a['last'] ? $a['last']->status.' '.$a['last']->created_at->diffForHumans() : 'never' }}</div>
<form method="POST" action="{{ route('admin.agents.run',$a['name']) }}" class="mt-2 flex gap-1">@csrf
<input name="symbol" placeholder="SYM" class="w-20 text-xs rounded border px-1 py-0.5 bg-white dark:bg-slate-900">
<input name="currency" placeholder="CCY" class="w-16 text-xs rounded border px-1 py-0.5 bg-white dark:bg-slate-900">
<button class="text-xs rounded bg-slate-800 text-white px-2 py-0.5">Run now</button></form>
</div>
@endforeach
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3">
<h2 class="font-semibold text-sm mb-2">Agent tasks</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>ID</th><th>Agent</th><th>Type</th><th>Sym</th><th>Status</th><th>Retries</th><th>Error</th><th>When</th></tr></thead>
<tbody>@foreach($tasks as $t)<tr class="border-t dark:border-slate-800"><td>{{ $t->id }}</td><td>{{ $t->agent_name }}</td><td>{{ $t->task_type }}</td><td>{{ $t->symbol }}</td><td>{{ $t->status }}</td><td>{{ $t->retry_count }}</td><td class="max-w-xs truncate" title="{{ $t->error_message }}">{{ $t->error_message }}</td><td>{{ $t->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table>
{{ $tasks->links() }}
</div>
@endsection
