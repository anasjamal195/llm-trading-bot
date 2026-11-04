@extends('admin.layouts.app')
@section('title','Currency Intelligence')
@section('content')
<div class="grid md:grid-cols-2 gap-3">
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold text-sm mb-2">Rankings</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Ccy</th><th>Outlook</th><th>Sentiment</th><th>Conf</th><th>Expires</th><th>Updated</th></tr></thead>
<tbody>@foreach($currencies as $c)<tr class="border-t dark:border-slate-800"><td class="font-bold">{{ $c->currency }}</td><td>{{ $c->outlook }}</td><td>{{ $c->news_sentiment }}</td><td>{{ $c->confidence }}</td><td>{{ $c->expires_at }}</td><td>{{ $c->created_at->diffForHumans() }}</td></tr>@endforeach</tbody></table>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3">
<h2 class="font-semibold text-sm mb-2">Economic events</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Time</th><th>Event</th><th>Ccy</th><th>Impact</th></tr></thead>
<tbody>@foreach($events as $e)<tr class="border-t dark:border-slate-800"><td>{{ $e->event_time }}</td><td>{{ $e->title }}</td><td>{{ $e->currency }}</td><td>{{ $e->impact }}</td></tr>@endforeach</tbody></table>
</div>
</div>
<div class="rounded-lg border bg-white dark:bg-slate-900 dark:border-slate-800 p-3 mt-3">
<h2 class="font-semibold text-sm mb-2">News</h2>
<table class="w-full text-xs"><thead><tr class="text-left text-slate-500"><th>Published</th><th>Ccy</th><th>Headline</th><th>Sentiment</th><th>Impact</th></tr></thead>
<tbody>@foreach($news as $n)<tr class="border-t dark:border-slate-800"><td>{{ $n->published_at }}</td><td>{{ $n->currency }}</td><td><a class="underline" href="{{ $n->url }}" target="_blank" rel="noreferrer">{{ $n->headline }}</a><div class="text-slate-500">{{ $n->source }}</div></td><td>{{ $n->sentiment }}</td><td>{{ $n->impact }}</td></tr>@endforeach</tbody></table>
</div>
@endsection
