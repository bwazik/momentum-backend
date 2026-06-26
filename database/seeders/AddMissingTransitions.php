<?php

namespace Database\Seeders;

use App\Modules\Blueprint\Enums\TransitionType;
use App\Modules\Blueprint\Models\Blueprint;
use App\Modules\Blueprint\Models\BlueprintTransition;
use Illuminate\Database\Seeder;

class AddMissingTransitions extends Seeder
{
    public function run(): void
    {
        $blueprints = Blueprint::with(['stages' => function ($q) {
            $q->orderBy('sequence_order');
        }])->get();

        if ($blueprints->isEmpty()) {
            $this->command?->info('No blueprints found. Nothing to do.');

            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($blueprints as $bp) {
            $stages = $bp->stages;

            for ($i = 0; $i < $stages->count() - 1; $i++) {
                $exists = BlueprintTransition::where('blueprint_id', $bp->id)
                    ->where('from_stage_id', $stages[$i]->id)
                    ->where('to_stage_id', $stages[$i + 1]->id)
                    ->where('transition_type', TransitionType::Advance)
                    ->exists();

                if (! $exists) {
                    BlueprintTransition::create([
                        'blueprint_id' => $bp->id,
                        'from_stage_id' => $stages[$i]->id,
                        'to_stage_id' => $stages[$i + 1]->id,
                        'transition_type' => TransitionType::Advance,
                        'return_reason_required' => false,
                    ]);
                    $created++;
                } else {
                    $skipped++;
                }
            }
        }

        $edmBp = $blueprints->first(function ($bp) {
            return $bp->name_en === 'E-System Development';
        });

        if ($edmBp && $edmBp->stages->count() >= 2) {
            $stages = $edmBp->stages;
            $exists = BlueprintTransition::where('blueprint_id', $edmBp->id)
                ->where('from_stage_id', $stages[1]->id)
                ->where('to_stage_id', $stages[0]->id)
                ->where('transition_type', TransitionType::Return)
                ->exists();

            if (! $exists) {
                BlueprintTransition::create([
                    'blueprint_id' => $edmBp->id,
                    'from_stage_id' => $stages[1]->id,
                    'to_stage_id' => $stages[0]->id,
                    'transition_type' => TransitionType::Return,
                    'return_reason_required' => true,
                ]);
                $created++;
            } else {
                $skipped++;
            }
        }

        $this->command?->info("Blueprint transitions: {$created} created, {$skipped} skipped.");
    }
}
