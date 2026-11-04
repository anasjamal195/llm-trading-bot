<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin login · LLM Trading Bot</title>@vite(['resources/css/app.css'])</head>
<body class="min-h-screen bg-slate-950 text-slate-100 flex items-center justify-center">
<form method="POST" action="{{ route('admin.login.post') }}" class="w-80 bg-slate-900 border border-slate-800 rounded-lg p-6 space-y-3">
@csrf
<h1 class="font-bold text-lg">LLM Trading Bot</h1>
<p class="text-xs text-slate-400">Administrator sign in</p>
@if($errors->any())<div class="text-xs text-red-400">{{ $errors->first() }}</div>@endif
<input name="email" type="email" required value="{{ old('email') }}" placeholder="Email" class="w-full rounded bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
<input name="password" type="password" required placeholder="Password" class="w-full rounded bg-slate-800 border border-slate-700 px-3 py-2 text-sm">
<button class="w-full rounded bg-emerald-600 hover:bg-emerald-500 px-3 py-2 text-sm font-semibold">Sign in</button>
</form>
</body></html>
