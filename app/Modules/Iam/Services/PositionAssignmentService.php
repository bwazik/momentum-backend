<?php

namespace App\Modules\Iam\Services;

use App\Models\User;
use App\Modules\Iam\Events\PositionAssigned;
use App\Modules\Iam\Events\PositionEnded;
use App\Modules\Iam\Events\PrimaryPositionChanged;
use App\Modules\Iam\Models\UserPositionAssignment;
use App\Modules\Organization\Models\Position;
use App\Traits\AuthenticatedUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PositionAssignmentService
{
    use AuthenticatedUser;

    public function assign(User $user, string $positionPublicId, array $options = []): UserPositionAssignment
    {
        try {
            return DB::transaction(function () use ($user, $positionPublicId, $options) {
                $position = Position::where('public_id', $positionPublicId)->firstOrFail();
                $isPrimary = $options['is_primary'] ?? true;

                if ($isPrimary) {
                    UserPositionAssignment::where('user_id', $user->id)
                        ->whereNull('ended_at')
                        ->update(['is_primary' => false]);
                }

                $assignment = UserPositionAssignment::create([
                    'user_id' => $user->id,
                    'position_id' => $position->id,
                    'is_primary' => $isPrimary,
                    'started_at' => now(),
                ]);

                event(new PositionAssigned($assignment));

                return $assignment;
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to assign user to position', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'position_assignment.assign',
                'entity_type' => 'user_position_assignment',
                'entity_id' => null,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'user_id' => $user->id,
                'position_id' => $positionPublicId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function end(UserPositionAssignment $assignment): UserPositionAssignment
    {
        try {
            $assignment->update(['ended_at' => now()]);

            event(new PositionEnded($assignment));

            return $assignment->fresh();
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to end position assignment', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'position_assignment.end',
                'entity_type' => 'user_position_assignment',
                'entity_id' => $assignment->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function setPrimary(UserPositionAssignment $assignment): UserPositionAssignment
    {
        try {
            return DB::transaction(function () use ($assignment) {
                UserPositionAssignment::where('user_id', $assignment->user_id)
                    ->whereNull('ended_at')
                    ->update(['is_primary' => false]);

                $assignment->update(['is_primary' => true]);

                event(new PrimaryPositionChanged($assignment));

                return $assignment->fresh();
            });
        } catch (\Throwable $e) {
            Log::channel('iam')->error('Failed to set primary position', [
                'tenant_slug' => tenant()?->slug ?? 'central',
                'action' => 'position_assignment.set_primary',
                'entity_type' => 'user_position_assignment',
                'entity_id' => $assignment->id,
                'performed_by' => $this->user()?->public_id ?? 'system',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }
}
