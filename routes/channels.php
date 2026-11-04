<?php

use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('bot.status', fn () => auth('admin')->check());
Broadcast::channel('market.updates', fn () => auth('admin')->check());
Broadcast::channel('market.analysis', fn () => auth('admin')->check());
Broadcast::channel('currency.intelligence', fn () => auth('admin')->check());
Broadcast::channel('agents.activity', fn () => auth('admin')->check());
Broadcast::channel('trading.proposals', fn () => auth('admin')->check());
Broadcast::channel('trading.orders', fn () => auth('admin')->check());
Broadcast::channel('trading.positions', fn () => auth('admin')->check());
Broadcast::channel('trading.performance', fn () => auth('admin')->check());
Broadcast::channel('system.activity', fn () => auth('admin')->check());
