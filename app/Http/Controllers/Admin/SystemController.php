<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

use App\Models\AuditLog;
use App\Models\BotSession;
use App\Models\PaperAccountSnapshot;
use App\Models\PaperOrder;
use App\Models\PaperPosition;
use App\Models\PaperTransaction;
use App\Models\Setting;
use App\Models\TradingAccount;
use App\Models\TradingSymbol;
use App\Models\TradeProposal;
use App\Services\Bot\BotControlService;

use Illuminate\Http\Request;


class SystemController extends Controller
{
    public function settings()
    {
        return view('admin.settings.index', [
            'symbols' => TradingSymbol::orderBy('symbol')->get(),
            'groups' => Setting::orderBy('group')->orderBy('key')->get()->groupBy('group'),
        ]);
    }

    public function saveSettings(Request $request)
    {
        $data = $request->validate(['settings' => 'array']);
        $encrypted = ['openrouter.api_key', 'news.api_key'];
        foreach ($data['settings'] ?? [] as $key => $value) {
            if (! preg_match('/^[a-z0-9._-]+$/i', $key)) continue;
            if (in_array($key, ['openrouter.api_key', 'news.api_key'], true) && ($value === '' || $value === null)) continue; // keep existing
            $group = explode('.', $key)[0];
            Setting::put($key, is_scalar($value) ? (string) $value : json_encode($value), $group, in_array($key, $encrypted, true));
        }
        // Toggle symbols.
        foreach (TradingSymbol::all() as $s) {
            $s->update(['is_enabled' => $request->boolean("sym_{$s->symbol}")]);
        }
        AuditLog::log('settings', 'update', [], auth('admin')->user());
        return back()->with('status', 'Settings saved');
    }

    public function bot(BotControlService $bot, Request $request)
    {
        $action = $request->input('action');
        $res = match ($action) {
            'start' => $bot->start(auth('admin')->user()->email),
            'pause' => $bot->pause(),
            'resume' => $bot->resume(),
            'stop' => $bot->stop($request->input('policy', 'preserve')),
            'kill' => tap(['ok' => true], fn () => $bot->killSwitch(true)),
            'unkill' => tap(['ok' => true], fn () => $bot->killSwitch(false)),
            default => ['ok' => false],
        };
        AuditLog::log('bot', 'control_' . $action, $res, auth('admin')->user());
        return back()->with('status', $action . ': ' . json_encode($res));
    }

    public function resetAccount(Request $request)
    {
        $request->validate(['confirm' => 'required|accepted']);
        \DB::transaction(function () {
            \App\Models\PaperOrder::query()->delete();
            \App\Models\PaperPosition::query()->delete();
            \App\Models\PaperTransaction::query()->delete();
            \App\Models\TradeProposal::query()->delete();
            $account = \App\Models\TradingAccount::where('is_active', true)->first();
            if ($account) {
                $balance = (float) Setting::get('risk.starting_balance', $account->initial_balance);
                $account->update(['balance' => $balance, 'equity' => $balance, 'realized_pnl' => 0, 'total_costs' => 0]);
                \App\Models\PaperTransaction::record($account, 'RESET', 0, null, 'Account reset by admin');
            }
        });
        AuditLog::log('trading', 'account_reset', [], auth('admin')->user());
        return back()->with('status', 'Paper account reset');
    }

    public function statusApi()
    {
        $bot = BotSession::current();
        $account = \App\Models\TradingAccount::where('is_active', true)->first();
        return response()->json([
            'bot' => ['status' => $bot->status, 'message' => $bot->status_message],
            'account' => $account ? ['balance' => $account->balance, 'equity' => $account->equity, 'realized' => $account->realized_pnl] : null,
            'open' => \App\Models\PaperPosition::where('status', 'OPEN')->count(),
            'pending' => \App\Models\PaperOrder::where('status', 'PENDING')->count(),
        ]);
    }
}
