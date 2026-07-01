<?php

namespace App\Modules\Iam\Services;

use App\Modules\Iam\Events\DelegationExpired;
use App\Modules\Iam\Models\Delegation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelegationExpiryService
{
    public function expire(): int
    {
        $count = 0;

        try {
            Delegation::with('delegator')
                ->where('is_active', true)
                ->where('ends_at', '<', now())
                ->chunkById(500, function ($delegations) use (&$count) {
                    DB::transaction(function () use ($delegations, &$count) {
                        foreach ($delegations as $delegation) {
                            $delegation->update(['is_active' => false]);
                            event(new DelegationExpired($delegation));
                            $count++;
                        }
                    });
                });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to expire delegations', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'delegation.expire',
                'entity_type' => 'delegation',
                'entity_id' => null,
                'performed_by' => 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }

        return $count;
    }
}
