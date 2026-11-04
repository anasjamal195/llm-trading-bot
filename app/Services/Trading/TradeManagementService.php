<?php

namespace App\Services\Trading;

use App\Models\AgentTask;
use App\Models\PaperPosition;
use App\Services\PaperBroker\PaperBrokerService;

use Illuminate\Support\Facades\DB;

/**
 * Applies trade-management recommendations through the deterministic broker.
 * Only CLOSE_EARLY executes; SL/TP modifications are validated (never widen risk beyond original SL distance... simplified: SL may only tighten).
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
            $this->broker->closeEarly($position, 'AGENT_EARLY_EXIT');
            return;
        }
        if ($rec === 'MODIFY_SL' && isset($data['new_sl'])) {
            $this->tightenSl($position, (float) $data['new_sl']);
        }
        if ($rec === 'MODIFY_TP' && isset($data['new_tp'])) {
            DB::transaction(function () use ($position, $data) {
                $p = PaperPosition::where('id', $position->id)->lockForUpdate()->first();
                if ($p && $p->status === 'OPEN') $p->update(['take_profit' => (float) $data['new_tp']]);
            });
        }
    }

    private function tightenSl(PaperPosition $position, float $newSl): void
    {
        DB::transaction(function () use ($position, $newSl) {
            $p = PaperPosition::where('id', $position->id)->lockForUpdate()->first();
            if (! $p || $p->status !== 'OPEN' || $newSl <= 0) return;
            $entry = (float) $p->entry_price;
            // Only allow tightening (move SL toward profit), never widening.
            if ($p->direction === 'BUY' && $newSl > (float) $p->stop_loss && $newSl < $entry) {
                $p->update(['stop_loss' => $newSl]);
            } elseif ($p->direction === 'SELL' && $newSl < (float) $p->stop_loss && $newSl > $entry) {
                $p->update(['stop_loss' => $newSl]);
            }
        });
    }
}
