<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\DelegationCreated;
use App\Modules\Iam\Events\DelegationRevoked;
use App\Modules\Iam\Exceptions\CannotDelegateToSelfException;
use App\Modules\Iam\Models\Delegation;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DelegationService
{
    use AuthenticatedUser;

    public function create(array $data, User $delegator): Delegation
    {
        try {
            return DB::transaction(function () use ($data, $delegator) {
                $delegate = User::where('public_id', $data['delegate_user_id'])->firstOrFail();

                if ($delegator->id === $delegate->id) {
                    throw new CannotDelegateToSelfException;
                }

                $delegation = Delegation::create([
                    'delegator_user_id' => $delegator->id,
                    'delegate_user_id' => $delegate->id,
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'scope_type' => (int) $data['scope_type'],
                    'blueprint_category_id' => $data['blueprint_category_id'] ?? null,
                    'stage_type_id' => $data['stage_type_id'] ?? null,
                    'is_active' => true,
                ]);

                event(new DelegationCreated($delegation));

                return $delegation;
            });
        } catch (CannotDelegateToSelfException $e) {
            Log::channel('iam')->warning('Self-delegation attempt detected', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'delegation.create',
                'entity_type' => 'delegation',
                'entity_id' => null,
                'performed_by' => $delegator->public_id,
            ]);
            throw $e;
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to create delegation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'delegation.create',
                'entity_type' => 'delegation',
                'entity_id' => null,
                'performed_by' => $delegator->public_id,
                'delegate_user_id' => $data['delegate_user_id'] ?? null,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function revoke(Delegation $delegation): Delegation
    {
        try {
            $delegation->update(['is_active' => false]);

            event(new DelegationRevoked($delegation));

            return $delegation->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to revoke delegation', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'delegation.revoke',
                'entity_type' => 'delegation',
                'entity_id' => $delegation->public_id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
