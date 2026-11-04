<?php

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\SystemController;
use App\Http\Controllers\Admin\TradingController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => redirect()->route('admin.dashboard'));

Route::prefix('admin')->name('admin.')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post')->middleware('throttle:10,1');
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::middleware('admin.auth')->group(function () {
        Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
        Route::get('market', [DashboardController::class, 'market'])->name('market');
        Route::get('market/{symbol}', [DashboardController::class, 'symbol'])->name('market.symbol');
        Route::get('currencies', [DashboardController::class, 'currencies'])->name('currencies');
        Route::get('activity', [DashboardController::class, 'activity'])->name('activity');
        Route::get('logs', [SystemController::class, 'logs'])->name('logs');

        Route::get('proposals', [TradingController::class, 'proposals'])->name('proposals');
        Route::get('orders/pending', [TradingController::class, 'pendingOrders'])->name('orders.pending');
        Route::get('orders/filled', [TradingController::class, 'filledOrders'])->name('orders.filled');
        Route::post('orders/{order}/cancel', [TradingController::class, 'cancelOrder'])->name('orders.cancel');
        Route::get('trades/open', [TradingController::class, 'openTrades'])->name('trades.open');
        Route::post('trades/{position}/close', [TradingController::class, 'closeTrade'])->name('trades.close');
        Route::get('trades/closed', [TradingController::class, 'closedTrades'])->name('trades.closed');
        Route::get('trades/{position}', [TradingController::class, 'tradeDetail'])->name('trades.detail');
        Route::get('analytics', [TradingController::class, 'analytics'])->name('analytics');

        Route::get('agents', [TradingController::class, 'agents'])->name('agents');
        Route::post('agents/{agent}/run', [TradingController::class, 'runAgent'])->name('agents.run');
        Route::post('agents/{agent}/toggle', [TradingController::class, 'toggleAgent'])->name('agents.toggle');

        Route::get('learning', [TradingController::class, 'learning'])->name('learning');
        Route::get('backtests', [TradingController::class, 'backtests'])->name('backtests');
        Route::post('backtests', [TradingController::class, 'runBacktest'])->name('backtests.run');

        Route::get('settings', [SystemController::class, 'settings'])->name('settings');
        Route::post('settings', [SystemController::class, 'saveSettings'])->name('settings.save');
        Route::post('bot', [SystemController::class, 'bot'])->name('bot');
        Route::post('account/reset', [SystemController::class, 'resetAccount'])->name('account.reset');
        Route::get('api/status', [SystemController::class, 'statusApi'])->name('api.status');
    });
});
