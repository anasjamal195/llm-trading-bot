@extends('admin.layouts.app')
@section('title','Settings & Bot Control')
@section('content')
<div class="grid md:grid-cols-2 gap-3">
<form method="POST" action="{{ route('admin.bot') }}" class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 text-sm space-y-2">
@csrf
<h2 class="font-semibold">Bot control</h2>
<div class="flex flex-wrap gap-2">
<button name="action" value="start" class="rounded bg-emerald-600 text-white px-3 py-1">Start</button>
<button name="action" value="pause" class="rounded bg-amber-500 text-white px-3 py-1">Pause</button>
<button name="action" value="resume" class="rounded bg-sky-600 text-white px-3 py-1">Resume</button>
<button name="action" value="stop" class="rounded bg-slate-600 text-white px-3 py-1">Stop</button>
<button name="action" value="kill" class="rounded bg-red-600 text-white px-3 py-1" onclick="return confirm('Engage kill switch?')">KILL</button>
<button name="action" value="unkill" class="rounded border px-3 py-1">Release kill</button>
</div>
<label class="block text-xs">Stop policy <select name="policy" class="rounded border px-1 bg-white dark:bg-slate-900"><option value="preserve">preserve positions</option><option value="close_all">close all</option></select></label>
</form>
<form method="POST" action="{{ route('admin.account.reset') }}" class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 text-sm space-y-2" onsubmit="return confirm('Reset paper account? Full audit trail kept.')">@csrf
<h2 class="font-semibold">Paper account</h2>
<label class="text-xs"><input type="checkbox" name="confirm" value="1" required> I confirm reset</label>
<button class="rounded bg-red-600 text-white px-3 py-1 text-sm">Reset virtual account</button>
</form>
</div>
<form method="POST" action="{{ route('admin.settings.save') }}" class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3 text-sm space-y-3">
@csrf
<h2 class="font-semibold">Monitored symbols</h2>
<div class="flex flex-wrap gap-3">@foreach($symbols as $s)<label class="text-xs"><input type="checkbox" name="sym_{{ $s->symbol }}" value="1" {{ $s->is_enabled?'checked':'' }}> {{ $s->symbol }}</label>@endforeach</div>
@foreach($groups as $group => $items)
<h2 class="font-semibold capitalize">{{ $group }}</h2>
<div class="grid md:grid-cols-2 gap-2">
@foreach($items as $item)
<label class="text-xs block">{{ $item->key }}
@if(in_array($item->key, ['openrouter.api_key','news.api_key','webhook.signals_secret']))<input type="password" name="settings[{{ $item->key }}]" placeholder="(stored — leave blank to keep)" autocomplete="new-password" class="w-full rounded border px-2 py-1 bg-white dark:bg-slate-900">
@else<input name="settings[{{ $item->key }}]" value="{{ $item->is_encrypted ? '' : $item->value }}" placeholder="{{ $item->is_encrypted ? '(encrypted — leave blank to keep)' : '' }}" class="w-full rounded border px-2 py-1 bg-white dark:bg-slate-900">@endif</label>
@endforeach
</div>
@endforeach
<button class="rounded bg-slate-800 text-white px-4 py-1.5">Save settings</button>
</form>
@endsection
