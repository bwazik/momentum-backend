<?php

namespace App\Modules\Tracking\Resources;

use App\Modules\Task\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class TaskSlaHealthResource extends JsonResource
{
    private Collection $timers;

    public function __construct(Task $task, Collection $timers)
    {
        parent::__construct($task);
        $this->timers = $timers;
    }

    public function toArray(Request $request): array
    {
        return [
            'task_id' => $this->public_id,
            'overall_health' => $this->computeOverallHealth(),
            'timers' => SlaTimerInstanceResource::collection($this->timers),
        ];
    }

    private function computeOverallHealth(): string
    {
        if ($this->timers->where('status.value', 3)->isNotEmpty()) {
            return 'breached';
        }

        if ($this->timers->where('status.value', 2)->isNotEmpty()) {
            return 'warning';
        }

        if ($this->timers->where('status.value', 1)->isNotEmpty()) {
            return 'on_track';
        }

        return 'none';
    }
}
