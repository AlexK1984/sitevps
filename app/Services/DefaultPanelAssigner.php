<?php

namespace App\Services;

use App\Models\Panel;
use App\Models\PaymentSettings;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DefaultPanelAssigner
{
    public function assignIfEligible(User $user): ?Panel
    {
        return DB::transaction(function () use ($user): ?Panel {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);

            if ($user->assigned_panel_id || $user->subscription()->exists()) {
                return null;
            }

            $defaultPanelId = PaymentSettings::query()
                ->whereKey(1)
                ->lockForUpdate()
                ->value('default_panel_id');

            if (! $defaultPanelId) {
                return null;
            }

            $panel = Panel::query()->lockForUpdate()->find($defaultPanelId);
            if (! $panel || $panel->connection_status !== 'online') {
                return null;
            }

            $user->update(['assigned_panel_id' => $panel->id]);

            return $panel;
        });
    }
}
