<?php

namespace App\Http\Controllers;

use App\Models\CoachPairingToken;
use App\Models\CoachTraineeRelationship;
use App\Models\User;
use App\Services\CoachAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class CoachConnectionController extends Controller
{
    public function index(Request $request)
    {
        $relationships = CoachTraineeRelationship::where('trainee_user_id', $request->user()->id)
            ->with('coach.coachProfile', 'subscriptions', 'assignments.plan')->latest()->get();

        return Inertia::render('Coaching/Index', ['relationships' => $relationships->map(fn ($row) => [
            ...$row->only('id', 'status', 'requested_at', 'approved_at', 'ended_at', 'ended_reason'),
            'coach' => $row->coach->coachProfile?->only('display_name', 'specialization', 'bio'),
            'subscriptions' => $row->subscriptions->map(fn ($sub) => $sub->summary()),
            'plans' => $row->assignments->filter(fn ($a) => $a->plan)->map(fn ($a) => ['id' => $a->plan->id, 'name' => $a->plan->name, 'status' => $a->status])->values(),
        ])]);
    }

    public function qr(Request $request)
    {
        $token = bin2hex(random_bytes(32));
        DB::transaction(function () use ($request, $token) {
            app(CoachAccess::class)->lockUsers($request->user());
            CoachPairingToken::where('trainee_user_id', $request->user()->id)->whereNull('used_at')->delete();
            CoachPairingToken::create(['trainee_user_id' => $request->user()->id, 'token_hash' => hash('sha256', $token), 'expires_at' => now()->addMinutes(10)]);
        });

        return response()->json(['url' => url('/coach/connect/'.$token), 'expires_at' => now()->addMinutes(10)->toIso8601String()])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    private function token(string $raw, bool $lock = false): CoachPairingToken
    {
        abort_unless(preg_match('/^[a-f0-9]{64}$/D', $raw), 404);

        return CoachPairingToken::where('token_hash', hash('sha256', $raw))->whereNull('used_at')->where('expires_at', '>', now())
            ->when($lock, fn ($q) => $q->lockForUpdate())->firstOrFail();
    }

    private function member(User $user): array
    {
        $parts = preg_split('/\s+/u', trim($user->name));

        return ['name' => $parts[0].(count($parts) > 1 ? ' '.mb_substr(end($parts), 0, 1).'.' : ''), 'training_code' => $user->training_code];
    }

    public function connect(Request $request, string $token)
    {
        $pairing = $this->token($token);
        abort_if($pairing->trainee_user_id === $request->user()->id, 422, 'You cannot coach yourself.');

        return Inertia::render('Coach/Connect', ['member' => $this->member($pairing->trainee), 'token' => $token])
            ->toResponse($request)->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function lookup(Request $request)
    {
        $request->merge(['training_code' => strtoupper(trim((string) $request->input('training_code')))]);
        $data = $request->validate(['training_code' => 'required|regex:/^TP-[2-9A-HJ-NP-Z]{8}$/D']);
        $user = User::where('training_code', $data['training_code'])->whereKeyNot($request->user()->id)->first();

        return response()->json(['member' => $user ? $this->member($user) : null])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, CoachAccess $access)
    {
        $request->merge(['training_code' => strtoupper(trim((string) $request->input('training_code')))]);
        $data = $request->validate(['training_code' => 'required_without:token|nullable|regex:/^TP-[2-9A-HJ-NP-Z]{8}$/D', 'token' => 'nullable|string|size:64']);
        $target = ! empty($data['token']) ? $this->token($data['token'])->trainee : User::where('training_code', $data['training_code'])->first();
        if (! $target || $target->id === $request->user()->id) {
            throw ValidationException::withMessages(['training_code' => 'Choose another member using their Training Planner ID.']);
        }
        DB::transaction(function () use ($request, $target, $access, $data) {
            $access->lockUsers($request->user(), $target);
            abort_unless($request->user()->isCoach(), 403);
            $token = ! empty($data['token']) ? $this->token($data['token'], true) : null;
            abort_if($token && $token->trainee_user_id !== $target->id, 403);
            $existing = CoachTraineeRelationship::where('coach_user_id', $request->user()->id)->where('trainee_user_id', $target->id)->whereIn('status', ['pending', 'active'])->lockForUpdate()->first();
            if (! $existing) {
                CoachTraineeRelationship::create(['coach_user_id' => $request->user()->id, 'trainee_user_id' => $target->id, 'status' => 'pending', 'requested_by' => $request->user()->id, 'requested_at' => now()]);
            }
            $token?->update(['used_at' => now()]);
        });

        return redirect('/coach/trainees')->with('success', 'Connection request sent. Training data stays private until they accept.');
    }

    public function respond(Request $request, CoachTraineeRelationship $relationship, CoachAccess $access)
    {
        $data = $request->validate(['action' => 'required|in:accept,decline,end', 'confirmed' => 'exclude_unless:action,end|required|accepted']);
        abort_unless(in_array($request->user()->id, [$relationship->trainee_user_id, $relationship->coach_user_id], true), 403);
        DB::transaction(function () use ($request, $relationship, $access, $data) {
            $access->lockUsers($relationship->coach, $relationship->trainee);
            $row = CoachTraineeRelationship::lockForUpdate()->findOrFail($relationship->id);
            if ($data['action'] !== 'end') {
                abort_unless($request->user()->id === $row->trainee_user_id && $row->status === 'pending', 403);
                if ($data['action'] === 'accept') {
                    abort_unless($row->coach->isCoach(), 403);
                    $row->update(['status' => 'active', 'approved_at' => now()]);

                    return;
                }
            }
            if ($row->status === 'ended') {
                return;
            }
            $row->update(['status' => 'ended', 'ended_at' => now(), 'ended_reason' => $data['action'] === 'decline' ? 'declined' : ($request->user()->id === $row->trainee_user_id ? 'disconnected' : 'coach_ended')]);
            $row->subscriptions()->whereIn('status', ['active', 'paused'])->update(['status' => 'ended', 'ended_at' => now()]);
            $row->assignments()->where('status', 'active')->update(['status' => 'ended', 'ended_at' => now()]);
        });

        return back()->with('success', $data['action'] === 'accept' ? 'Coach connected.' : 'Relationship ended. All training data is preserved.');
    }
}
