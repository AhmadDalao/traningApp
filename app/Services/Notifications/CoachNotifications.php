<?php

namespace App\Services\Notifications;

use App\Models\CoachTraineeRelationship;
use App\Models\NotificationPreference;
use App\Models\TrainingPlan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoachNotifications
{
    public function connection(CoachTraineeRelationship $relationship, bool $accepted = false): void
    {
        $userId = $accepted ? $relationship->coach_user_id : $relationship->trainee_user_id;
        $kind = $accepted ? 'coach_accepted' : 'coach_request';
        $this->queue($userId, $kind, $kind.':'.$relationship->id, $relationship->id,
            $accepted ? 'Coaching request accepted' : 'New coaching request',
            $accepted ? 'A member accepted your coaching request.' : 'You have a coaching request to review.',
            $accepted ? '/coach/trainees/'.$relationship->trainee_user_id : '/my-coaching');
    }

    public function plan(?User $coach, TrainingPlan $plan, string $kind, string $eventKey): void
    {
        if (! $coach || $coach->id === $plan->user_id) {
            return;
        }
        $relationship = CoachTraineeRelationship::where('coach_user_id', $coach->id)->where('trainee_user_id', $plan->user_id)->where('status', 'active')->first();
        if (! $relationship) {
            return;
        }
        $titles = ['assigned' => 'New training plan', 'schedule' => 'Training schedule updated', 'pause' => 'Program paused', 'resume' => 'Program resumed', 'shift' => 'Program schedule shifted', 'end' => 'Program ended'];
        $this->queue($plan->user_id, 'coach_'.$kind, $kind.':'.$plan->id.':'.$eventKey, $relationship->id,
            $titles[$kind], 'Your coach updated your training. Open Training Planner to review the details.', '/notifications/open/plan/'.$plan->id);
    }

    private function queue(int $userId, string $kind, string $key, int $relationshipId, string $title, string $body, string $url): void
    {
        DB::afterCommit(function () use ($userId, $kind, $key, $relationshipId, $title, $body, $url) {
            try {
                if (NotificationPreference::settings($userId)['coach_updates']) {
                    app(PushDeliveries::class)->enqueue($userId, $kind, $key, ['relationship_id' => $relationshipId], compact('title', 'body', 'url'), now()->addDay());
                }
            } catch (\Throwable) {
                Log::warning('Coach notification could not be queued.', ['kind' => $kind]);
            }
        });
    }
}
