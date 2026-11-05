<?php

namespace App\Services\Trading;

use App\Models\AgentTask;
use App\Models\PaperPosition;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Support\Facades\DB;

/**
 * Applies trade-management recommendations. FIXED TP/SL RULE:
 * - TP can never be modified.
 * - SL may only tighten, never widen.
 * - CLOSE_EARLY executes ONLY past the position's expected close time
 *   (max_hold_until) or when the thesis is broken; otherwise the request is
 *   rejected with a logged reason and the position runs to fixed TP/SL.
 */
class TradeManagementService
{
    public function __construct(private PaperBrokerService $broker) {}

    public function applyRecommendation(AgentTask $task, array $data): void
    {
        $ctx = $task->input_context ?? [];
        if (empty($ctx['paper_position_id'])) return;
        $position = PaperPosition::find($ctx['paper_position_id']);
        if (! $position || $position->status !== 'OPEN') return;

        $rec = $data['recommendation'] ?? 'NO_ACTION';
        if ($rec === 'CLOSE_EARLY') {
            if (now()->greaterThan($position->max_hold_until) || ! empty($data['thesis_broken'])) {
                $this->broker->closeEarly($position, 'AGENT_EARLY_EXIT');
            } else {
                app(\App\Services\System\AppLogger::class)->info('trading', "Early close blocked for position #{$position->id}: before expected close time");
            }
            return;
        }
        if ($rec === 'MODIFY_SL' && isset($data['new_sl'])) {
            $this->tightenSl($position, (float) $data['new_sl']);
        }
        if (isset($data['new_tp'])) {
            app(\App\Services\System\AppLogger::class)->warning('trading', "TP modification rejected for position #{$position->id}: TP is fixed");
        }
    }

    private function tightenSl(PaperPosition $position, float $newSl): void
    {
        DB::transaction(function () use ($position, $newSl) {
            $p = PaperPosition::where('id', $position->id)->lockForUpdate()->first();
            if (! $p || $p->status !== 'OPEN' || $newSl <= 0) return;
            $entry = (float) $p->entry_price;
            // Only allow tightening (toward profit, including past breakeven),
            // never widening back toward the original risk.
            if ($p->direction === 'BUY' && $newSl > (float) $p->stop_loss) {
                $p->update(['stop_loss' => $newSl]);
            } elseif ($p->direction === 'SELL' && $newSl < (float) $p->stop_loss) {
                $p->update(['stop_loss' => $newSl]);
            }
        });
    }
}
