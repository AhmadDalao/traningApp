import { useCallback, useEffect, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import { Check, Clock3 } from "lucide-react";
import {
    Breadcrumbs,
    Button,
    ButtonLink,
    ConfirmDialog,
    PageHeader,
} from "../../Components/UI";
import ActiveWorkoutExercise from "../../Features/ActiveWorkoutExercise";
import RestTimer from "../../Features/RestTimer";
import { formatDuration, workoutProgress } from "../../Features/workout";

export default function Show({ entry }) {
    const { timezone } = usePage().props;
    const [now, setNow] = useState(Date.now());
    const [confirm, setConfirm] = useState(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState("");
    const [dirtySets, setDirtySets] = useState({});
    const onDirty = useCallback(
        (id, dirty) => setDirtySets((current) => ({ ...current, [id]: dirty })),
        [],
    );
    const unsaved = Object.values(dirtySets).some(Boolean);
    const exercises = entry.session_exercises;
    const progress = workoutProgress(exercises);
    const url = `/schedule-entries/${entry.id}/workout`;
    useEffect(() => {
        const timer = setInterval(() => setNow(Date.now()), 1000);
        return () => clearInterval(timer);
    }, []);
    useEffect(() => {
        const warn = (event) => {
            event.preventDefault();
            event.returnValue = "";
        };
        if (unsaved) window.addEventListener("beforeunload", warn);
        return () => window.removeEventListener("beforeunload", warn);
    }, [unsaved]);
    function finish() {
        setError("");
        if (unsaved) {
            setError("Save your changed sets before finishing.");
            return;
        }
        if (progress.incompleteSets) {
            setConfirm("finish");
            return;
        }
        setBusy(true);
        router.post(
            `${url}/finish`,
            {},
            {
                onError: (errors) => {
                    setError(Object.values(errors)[0]);
                    if (errors.incomplete_sets) setConfirm("finish");
                },
                onFinish: () => setBusy(false),
            },
        );
    }
    return (
        <>
            <Head title={`Training · ${entry.title}`} />
            <Breadcrumbs
                items={[
                    { label: "Calendar", href: "/calendar" },
                    {
                        label: entry.title,
                        href: `/schedule-entries/${entry.id}`,
                    },
                    { label: "Active workout" },
                ]}
            />
            <PageHeader
                eyebrow="ACTIVE WORKOUT"
                title={entry.title}
                description="Log what you do. Each saved set stays with this workout."
            >
                <Button disabled={busy} onClick={finish}>
                    <Check size={18} />
                    Finish workout
                </Button>
            </PageHeader>
            <section className="panel workout-progress">
                <div>
                    <span className="badge workout-active">In progress</span>
                    <small>
                        Started{" "}
                        {new Date(entry.started_at).toLocaleTimeString(
                            "en-GB",
                            {
                                timeZone: timezone,
                                hour: "2-digit",
                                minute: "2-digit",
                            },
                        )}
                    </small>
                </div>
                <div>
                    <span className="meta-label">
                        <Clock3 size={15} /> Elapsed
                    </span>
                    <strong className="timer-digits">
                        {formatDuration(
                            (now - new Date(entry.started_at).getTime()) / 1000,
                        )}
                    </strong>
                </div>
                <div>
                    <span className="meta-label">Exercises completed</span>
                    <strong>
                        {progress.completedExercises} / {progress.exercises}
                    </strong>
                </div>
                <div>
                    <span className="meta-label">Sets completed</span>
                    <strong>
                        {progress.completedSets} / {progress.sets}
                    </strong>
                </div>
            </section>
            {unsaved && (
                <p className="workout-notice" role="status">
                    You have unsaved set changes. Tap Done or Save before
                    leaving.
                </p>
            )}
            {error && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
            <div className="active-workout-exercises">
                {exercises.map((exercise) => (
                    <ActiveWorkoutExercise
                        key={exercise.id}
                        exercise={exercise}
                        onDirty={onDirty}
                    />
                ))}
            </div>
            {!exercises.length && (
                <p className="panel workout-notice">
                    No exercises were attached to this session. You can still
                    finish or discard the workout.
                </p>
            )}
            <RestTimer />
            <div className="workout-footer">
                <Button disabled={busy} onClick={finish}>
                    <Check size={18} />
                    Finish workout
                </Button>
                <ButtonLink
                    variant="secondary"
                    href={`/schedule-entries/${entry.id}`}
                >
                    Back to session
                </ButtonLink>
                <button
                    className="text-button danger-text"
                    onClick={() => setConfirm("discard")}
                >
                    Discard workout
                </button>
            </div>
            {confirm === "finish" && (
                <ConfirmDialog
                    title="Finish with incomplete sets?"
                    description={`${progress.incompleteSets} ${progress.incompleteSets === 1 ? "set is" : "sets are"} still incomplete. Saved results will be kept; incomplete sets won’t be marked as done.`}
                    url={`${url}/finish`}
                    method="post"
                    data={{ confirm_incomplete: true }}
                    confirmLabel="Finish anyway"
                    cancelLabel="Continue workout"
                    variant="primary"
                    onClose={() => setConfirm(null)}
                />
            )}
            {confirm === "discard" && (
                <ConfirmDialog
                    title="Discard workout?"
                    description="Your detailed set progress for this active workout will be removed and the session will return to Planned. Your training plan and saved prescription will stay available."
                    url={`${url}/discard`}
                    method="post"
                    data={{ confirmed: true }}
                    confirmLabel="Discard workout"
                    onClose={() => setConfirm(null)}
                />
            )}
        </>
    );
}
