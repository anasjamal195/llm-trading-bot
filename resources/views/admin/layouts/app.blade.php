<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>@yield('title', 'Dashboard') · LLM Trading Bot</title>
@vite(['resources/css/app.css', 'resources/js/app.js'])
<script>
window.PUSHER_CONF = {
  key: @json(env('VITE_PUSHER_APP_KEY')),
  host: @json(env('VITE_PUSHER_HOST', '127.0.0.1')),
  port: @json(env('VITE_PUSHER_PORT', 6001)),
  scheme: @json(env('VITE_PUSHER_SCHEME', 'http')),
  cluster: @json(env('VITE_PUSHER_APP_CLUSTER', 'mt1')),
};
</script>
</head>
<body class="h-full bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
<div class="flex min-h-full">
  <aside class="w-60 shrink-0 bg-slate-900 text-slate-200 hidden md:flex md:flex-col">
    <div class="px-5 py-4 border-b border-slate-800">
      <div class="font-bold text-white">LLM Trading Bot</div>
      <div class="text-xs text-slate-400">Paper trading terminal</div>
    </div>
    <nav class="flex-1 overflow-y-auto px-3 py-3 space-y-1 text-sm">
      @php
      $nav = [
        'Overview' => [['admin.dashboard','Dashboard','/']],
        'Market' => [['admin.market','Live Market','market'],['admin.currencies','Currency Intel','currencies']],
        'Trading' => [['admin.proposals','Trade Proposals','proposals'],['admin.orders.pending','Pending Orders','orders/pending'],['admin.orders.filled','Filled Orders','orders/filled'],['admin.trades.open','Open Trades','trades/open'],['admin.trades.closed','Closed Trades','trades/closed'],['admin.analytics','Performance','analytics']],
        'Intelligence' => [['admin.agents','AI Agents','agents'],['admin.learning','Learning','learning'],['admin.backtests','Backtesting','backtests']],
        'System' => [['admin.activity','System Activity','activity'],['admin.settings','Settings','settings']],
      ];
      @endphp
      @foreach($nav as $section => $links)
        <div class="px-2 pt-3 pb-1 text-[11px] uppercase tracking-wider text-slate-500">{{ $section }}</div>
        @foreach($links as [$route,$label,$_])
          <a href="{{ route($route) }}" class="block rounded px-3 py-1.5 {{ request()->routeIs($route) ? 'bg-slate-700 text-white' : 'hover:bg-slate-800' }}">{{ $label }}</a>
        @endforeach
      @endforeach
    </nav>
    <div class="p-3 border-t border-slate-800 text-xs">
      <div class="text-slate-400">{{ auth('admin')->user()->email ?? '' }}</div>
      <form method="POST" action="{{ route('admin.logout') }}" class="mt-1">@csrf<button class="text-red-400 hover:text-red-300">Sign out</button></form>
    </div>
  </aside>
  <div class="flex-1 min-w-0">
    <header class="sticky top-0 z-10 bg-white/90 dark:bg-slate-900/90 backdrop-blur border-b border-slate-200 dark:border-slate-800 px-4 py-2.5 flex items-center gap-3">
      <span id="bot-badge" class="text-xs font-semibold px-2 py-1 rounded bg-slate-200 dark:bg-slate-700">…</span>
      <span class="text-sm text-slate-500">@yield('title','Dashboard')</span>
      <span class="flex-1"></span>
      <span id="ws-dot" class="text-xs text-slate-400" title="websocket">● WS</span>
      <button onclick="document.documentElement.classList.toggle('dark');localStorage.setItem('theme',document.documentElement.classList.contains('dark')?'dark':'light')" class="text-xs border rounded px-2 py-1">Theme</button>
    </header>
    <script>if(localStorage.getItem('theme')==='dark'||(!localStorage.getItem('theme')&&matchMedia('(prefers-color-scheme: dark)').matches))document.documentElement.classList.add('dark')</script>
    <main class="p-4 max-w-7xl mx-auto">
      @if(session('status'))<div class="mb-3 rounded border border-green-300 bg-green-50 dark:bg-green-950 px-3 py-2 text-sm">{{ session('status') }}</div>@endif
      @yield('content')
    </main>
  </div>
</div>
@yield('scripts')
</body>
</html>
