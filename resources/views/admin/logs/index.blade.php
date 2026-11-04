@extends('admin.layouts.app')
@section('title','System Logs')
@section('content')
<form method="GET" class="flex gap-2 mb-3 text-sm">
<select name="level" class="rounded border px-2 py-1 bg-white dark:bg-slate-900"><option value="">all levels</option>@foreach(['debug','info','warning','error'] as $l)<option {{ request('level')===$l?'selected':'' }}>{{ $l }}</option>@endforeach</select>
<input name="component" value="{{ request('component') }}" placeholder="component" class="rounded border px-2 py-1 bg-white dark:bg-slate-900">
<button class="rounded bg-slate-800 text-white px-3 py-1">Filter</button>
<span class="flex-1"></span><span class="text-xs text-slate-500 self-center">File logs: storage/logs/laravel.log · trading.log · agents.log (30-day rotation). Secrets are redacted.</span>
</form>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Time</th><th>Level</th><th>Component</th><th>Message</th><th>Context</th></tr></thead>
<tbody>@foreach($logs as $l)<tr class="border-t dark:border-slate-800"><td class="whitespace-nowrap">{{ $l->created_at }}</td><td><span class="px-1.5 rounded {{ $l->level==='error'?'bg-red-100 text-red-800':($l->level==='warning'?'bg-amber-100 text-amber-800':'bg-slate-100 text-slate-700') }}">{{ $l->level }}</span></td><td>{{ $l->component }}</td><td>{{ $l->message }}</td><td class="max-w-md truncate" title="{{ json_encode($l->context) }}">{{ json_encode($l->context) }}</td></tr>@endforeach</tbody></table>
{{ $logs->links() }}
</div>
@endsection
