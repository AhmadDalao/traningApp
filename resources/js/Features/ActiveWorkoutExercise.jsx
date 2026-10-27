import { useState } from "react";
import { Link, router } from "@inertiajs/react";
import { Plus } from "lucide-react";
import { Button, ConfirmDialog, formatWeight } from "../Components/UI";
import { Thumbnail } from "./ExerciseEditor";
import WorkoutSetRow from "./WorkoutSetRow";
import { formatDuration } from "./workout";

export default function ActiveWorkoutExercise({
    exercise,
    readOnly = false,
    onDirty,
    progressUrl,
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [skipping, setSkipping] = useState(false);
    const skipped = exercise.status === "skipped";
    const complete = exercise.sets.filter((set) => set.completed_at).length;
    function change(url, method, data = {}) {
        setBusy(true);
        setError("");
        router.visit(url, {
            method,
            data,
            preserveScroll: true,
            preserveState: true,
            onError: (errors) => setError(Object.values(errors)[0]),
            onFinish: () => setBusy(false),
        });
    }
    return (
        <section
            className={`panel workout-exercise ${skipped ? "is-skipped" : ""}`}
            aria-label={exercise.name}
        >
            <header className="workout-exercise-header">
                <Thumbnail exercise={exercise} />
                <div>
                    <h2>{exercise.name}</h2>
                    <p>
                        Planned: {exercise.planned_sets} ×{" "}
                        {exercise.planned_reps_text || "—"}
                        {exercise.planned_weight_kg != null
                            ? ` · ${formatWeight(exercise.planned_weight_kg)}`
                            : ""}
                    </p>
                    <small>
                        {complete} / {exercise.sets.length} sets completed ·{" "}
                        {exercise.tracking_mode === "duration"
                            ? "Duration"
                            : "Reps"}
                    </small>
                </div>
                <span
                    className={`badge ${skipped ? "skipped" : exercise.status === "completed" ? "completed" : "planned"}`}
                >
                    {skipped
                        ? "Skipped"
                        : exercise.status === "completed"
                          ? "Completed"
                          : readOnly
                            ? "Incomplete"
                            : "Active"}
                </span>
            </header>
            {readOnly && progressUrl && (
                <Link
                    href={progressUrl}
                    className="inline-link exercise-progress-link"
                >
                    View Exercise Progress
                </Link>
            )}
            {readOnly && !exercise.source_exercise_key && (
                <p className="workout-exercise-note">
                    This record has no saved exercise identity, so long-term
                    progress is unavailable.
                </p>
            )}
            {exercise.superset_group && (
                <div className="workout-superset">
                    <span className="superset-badge">
                        Superset {exercise.superset_group}
                    </span>
                </div>
            )}
            {exercise.notes && (
                <p className="workout-exercise-note">{exercise.notes}</p>
            )}
            {readOnly || skipped ? (
                <div className="logged-sets">
                    {exercise.sets.map((set) => (
                        <div className="logged-set" key={set.id}>
                            <strong>Set {set.set_number}</strong>
                            <span>{formatWeight(set.actual_weight_kg)}</span>
                            <span>
                                {exercise.tracking_mode === "duration"
                                    ? formatDuration(
                                          set.actual_duration_seconds,
                                      ) || "—"
                                    : set.actual_reps != null
                                      ? `${set.actual_reps} reps`
                                      : "—"}
                            </span>
                            <small>
                                {set.completed_at ? "✓ Done" : "Incomplete"}
                            </small>
                        </div>
                    ))}
                </div>
            ) : (
                <div className="workout-sets">
                    {exercise.sets.map((set) => (
                        <WorkoutSetRow
                            key={`${set.id}-${set.completed_at}-${set.actual_reps}-${set.actual_weight_kg}-${set.actual_duration_seconds}`}
                            set={set}
                            exercise={exercise}
                            onDirty={onDirty}
                        />
                    ))}
                </div>
            )}
            {!exercise.sets.length && (
                <p className="workout-exercise-note">No sets recorded.</p>
            )}
            {!readOnly && (
                <div className="workout-exercise-actions">
                    {!skipped && (
                        <Button
                            variant="secondary"
                            disabled={busy || exercise.sets.length >= 99}
                            onClick={() =>
                                change(
                                    `/session-exercises/${exercise.id}/sets`,
                                    "post",
                                )
                            }
                        >
                            <Plus size={17} />
                            Add set
                        </Button>
                    )}
                    <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => {
                            if (!skipped && complete) setSkipping(true);
                            else
                                change(
                                    `/session-exercises/${exercise.id}/status`,
                                    "patch",
                                    { status: skipped ? "active" : "skipped" },
                                );
                        }}
                    >
                        {skipped ? "Undo skip" : "Skip exercise"}
                    </Button>
                </div>
            )}
            {error && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
            {skipping && (
                <ConfirmDialog
                    title="Skip this exercise?"
                    description="You already completed sets for this exercise. Those results will stay saved, and the exercise will be marked Skipped."
                    url={`/session-exercises/${exercise.id}/status`}
                    method="patch"
                    data={{ status: "skipped", confirmed: true }}
                    confirmLabel="Skip exercise"
                    onClose={() => setSkipping(false)}
                />
            )}
        </section>
    );
}
