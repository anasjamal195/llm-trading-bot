@extends('admin.layouts.app')
@section('title','System Activity')
@section('content')
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Time</th><th>Category</th><th>Action</th><th>By</th><th>Context</th></tr></thead>
<tbody>@foreach($logs as $l)<tr class="border-t dark:border-slate-800"><td>{{ $l->created_at }}</td><td>{{ $l->category }}</td><td>{{ $l->action }}</td><td>{{ $l->admin_user_id }}</td><td class="max-w-md truncate" title="{{ json_encode($l->context) }}">{{ json_encode($l->context) }}</td></tr>@endforeach</tbody></table>
{{ $logs->links() }}
</div>
@endsection
