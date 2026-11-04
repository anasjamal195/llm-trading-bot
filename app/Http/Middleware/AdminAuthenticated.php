<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class AdminAuthenticated
{
    public function handle(Request $request, Closure $next)
    {
        if (! auth('admin')->check()) {
            if ($request->expectsJson() || $request->is('broadcasting/*')) return response()->json(['message' => 'Unauthenticated'], 401);
            return redirect()->route('admin.login');
        }
        if (! auth('admin')->user()->is_enabled) {
            auth('admin')->logout();
            return redirect()->route('admin.login')->withErrors(['email' => 'Account disabled']);
        }
        return $next($request);
    }
}
