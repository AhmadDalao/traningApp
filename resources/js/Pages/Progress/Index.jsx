import { useTraining } from "../../Features/CoachingUI";
import useLiveFilters from "../../Features/useLiveFilters";
import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import {
    Plus,
    Pencil,
    Trash2,
    CheckCircle2,
    Flame,
    Trophy,
    TrendingUp,
    Scale,
    CalendarDays,
    Dumbbell,
    Activity,
} from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    EmptyState,
    DateLabel,
    Pagination,
    ConfirmDialog,
    formatWeight,
    Field,
} from "../../Components/UI";
import BodyWeightForm from "../../Features/BodyWeightForm";
import BodyWeightChart from "../../Features/BodyWeightChart";
import { RecordList, RecordValue } from "../../Features/ExerciseRecords";
import { ProgramRunCards } from "../../Features/ProgramUI";
import { Thumbnail } from "../../Features/ExerciseEditor";

export default function Index({
    summary,
    trend,
    entries,
    training,
    range = "30",
    highlights = [],
    recentRecords = [],
    recentRecordCount = 0,
    recentWorkouts = [],
    programRuns = [],
}) {
    const { url, coaching } = useTraining();
    const live = useLiveFilters(url("/progress"), { range }, [
        "range",
        "summary",
        "trend",
        "entries",
        "training",
        "highlights",
        "recentRecords",
        "recentRecordCount",
        "recentWorkouts",
        "programRuns",
    ]);
    const [editing, setEditing] = useState(null),
        [deleting, setDeleting] = useState(null);
    const hasTraining = training.due > 0 || highlights.length > 0;
    const cards = [
        ["Completed workouts", training.completed, CheckCircle2, "green"],
        [
            "Completion rate",
            training.completion_rate === null
                ? "No due workouts"
                : `${training.completion_rate}%`,
            Activity,
            "blue",
        ],
        ["Current streak", `${training.streak} workouts`, Flame, "orange"],
        ["Recent PRs", recentRecordCount, Trophy, "purple"],
    ];
    return (
        <div className="unified-progress" aria-busy={live.processing}>
            <Head title="Progress" />
            <PageHeader
                title="Progress"
                description={
                    coaching
                        ? "Consistency and performance from training assigned by you."
                        : "See how your training is changing over time."
                }
            >
                <Field label="Progress period">
                    <select
                        value={live.data.range}
                        onChange={(e) => live.setData("range", e.target.value)}
                    >
                        {[
                            ["30", "Last 30 days"],
                            ["90", "Last 90 days"],
                            ["6m", "Last 6 months"],
                            ["1y", "Last year"],
                            ["all", "All time"],
                        ].map(([k, v]) => (
                            <option key={k} value={k}>
                                {v}
                            </option>
                        ))}
                    </select>
                </Field>
            </PageHeader>
            <span className="sr-only" role="status">
                {live.processing
                    ? "Updating progress"
                    : `${training.completed} completed workouts in this period`}
            </span>
            {hasTraining ? (
                <>
                    <div className="stats-grid progress-stats">
                        {cards.map(([label, value, Icon, color]) => (
                            <div className="stat-card" key={label}>
                                <div className="stat-top">
                                    <span>{label}</span>
                                    <span className={`stat-icon ${color}`}>
                                        <Icon size={19} aria-hidden="true" />
                                    </span>
                                </div>
                                <strong>{value}</strong>
                                {label === "Current streak" && (
                                    <p>Completed due workouts in a row</p>
                                )}
                            </div>
                        ))}
                    </div>
                    <ProgramRunCards runs={programRuns} />
                    <section className="panel coach-card consistency-panel">
                        <div className="coach-card-title">
                            <h2>
                                <CalendarDays size={20} aria-hidden="true" />{" "}
                                Training consistency
                            </h2>
                            <ButtonLink
                                variant="secondary"
                                href={url("/training-history")}
                            >
                                View history
                            </ButtonLink>
                        </div>
                        <p>
                            {training.completed} of {training.due} due workouts
                            completed · {training.skipped} skipped
                        </p>
                        {training.due > 0 && (
                            <progress
                                max={training.due}
                                value={training.completed}
                                aria-label="Completed due workouts"
                            />
                        )}
                        {training.weighted_volume !== null && (
                            <p className="volume-summary">
                                <Dumbbell size={17} aria-hidden="true" />{" "}
                                {Number(
                                    training.weighted_volume,
                                ).toLocaleString()}{" "}
                                kg total weighted volume
                            </p>
                        )}
                    </section>
                    <div className="progress-columns">
                        <section className="panel coach-card">
                            <div className="coach-card-title">
                                <h2>
                                    <Trophy size={20} aria-hidden="true" />{" "}
                                    Recent PRs
                                </h2>
                                <ButtonLink
                                    variant="secondary"
                                    href={url("/progress/exercises")}
                                >
                                    View all
                                </ButtonLink>
                            </div>
                            {recentRecords.length ? (
                                <RecordList
                                    records={recentRecords.slice(0, 4)}
                                    compact
                                />
                            ) : (
                                <p className="field-hint">
                                    First performances establish a baseline. New
                                    records appear when those results improve.
                                </p>
                            )}
                        </section>
                        <section className="panel coach-card">
                            <div className="coach-card-title">
                                <h2>
                                    <CheckCircle2
                                        size={20}
                                        aria-hidden="true"
                                    />{" "}
                                    Recent training
                                </h2>
                                <ButtonLink
                                    variant="secondary"
                                    href={url(
                                        "/training-history?status=completed",
                                    )}
                                >
                                    View all
                                </ButtonLink>
                            </div>
                            <ul className="compact-activity">
                                {recentWorkouts.map((entry) => (
                                    <li key={entry.id}>
                                        <div>
                                            <Link
                                                href={url(
                                                    `/training-history/${entry.id}`,
                                                )}
                                            >
                                                {entry.title}
                                            </Link>
                                            <small>
                                                <DateLabel
                                                    date={entry.scheduled_date}
                                                />
                                                {entry.duration_minutes
                                                    ? ` · ${entry.duration_minutes} min`
                                                    : ""}
                                            </small>
                                        </div>
                                        <span className="badge completed">
                                            Completed
                                        </span>
                                    </li>
                                ))}
                            </ul>
                            {!recentWorkouts.length && (
                                <p>No completed workouts in this period.</p>
                            )}
                        </section>
                    </div>
                    <section aria-labelledby="exercise-highlights">
                        <div className="coach-card-title">
                            <h2 id="exercise-highlights">
                                <TrendingUp size={20} aria-hidden="true" />{" "}
                                Exercise progress
                            </h2>
                            <ButtonLink
                                variant="secondary"
                                href={url("/progress/exercises")}
                            >
                                All exercises
                            </ButtonLink>
                        </div>
                        {highlights.length ? (
                            <div className="exercise-highlight-grid">
                                {highlights.map((exercise) => {
                                    const record = exercise.records.find(
                                        (r) =>
                                            r.metric ===
                                            (exercise.tracking_mode ===
                                            "duration"
                                                ? "longest_duration"
                                                : exercise.weighted
                                                  ? "best_set"
                                                  : "max_reps"),
                                    );
                                    return (
                                        <article
                                            className="panel coach-card"
                                            key={`${exercise.key}-${exercise.tracking_mode}`}
                                        >
                                            <div className="exercise-progress-identity">
                                                <Thumbnail
                                                    exercise={exercise}
                                                />
                                                <h3>
                                                    <Link href={exercise.url}>
                                                        {exercise.name}
                                                    </Link>
                                                </h3>
                                            </div>
                                            <p>
                                                Last performance:{" "}
                                                {exercise.tracking_mode ===
                                                "duration"
                                                    ? `${exercise.latest_duration} sec`
                                                    : exercise.latest_weight !==
                                                        null
                                                      ? `${exercise.latest_weight} kg`
                                                      : `${exercise.latest_reps} reps`}
                                            </p>
                                            {record && (
                                                <p>
                                                    {record.label}:{" "}
                                                    <strong>
                                                        <RecordValue
                                                            record={record}
                                                        />
                                                    </strong>
                                                </p>
                                            )}
                                            {exercise.weight_change !== null &&
                                                exercise.weight_change !==
                                                    0 && (
                                                    <p className="field-hint">
                                                        {exercise.weight_change >
                                                        0
                                                            ? "+"
                                                            : ""}
                                                        {exercise.weight_change}{" "}
                                                        kg from first to latest
                                                        recorded weight
                                                    </p>
                                                )}
                                            <ButtonLink
                                                variant="secondary"
                                                href={exercise.url}
                                            >
                                                View exercise
                                            </ButtonLink>
                                        </article>
                                    );
                                })}
                            </div>
                        ) : (
                            <p className="field-hint">
                                Detailed set logs will show exercise trends
                                here.
                            </p>
                        )}
                    </section>
                </>
            ) : (
                <section className="panel">
                    <EmptyState
                        title="Your progress starts with a workout"
                        description="Complete workouts to start building your training trends."
                    >
                        <ButtonLink href={url("/calendar")}>
                            View Calendar
                        </ButtonLink>
                    </EmptyState>
                </section>
            )}
            <section className="panel coach-card body-summary">
                <div className="coach-card-title">
                    <h2>
                        <Scale size={20} aria-hidden="true" /> Body weight
                    </h2>
                    {!coaching && (
                        <Button
                            variant="secondary"
                            onClick={() => setEditing({})}
                        >
                            <Plus size={16} aria-hidden="true" />
                            Record weight
                        </Button>
                    )}
                </div>
                {summary.current !== null ? (
                    <>
                        <div className="body-summary-values">
                            <div>
                                <span>Current</span>
                                <strong>{formatWeight(summary.current)}</strong>
                            </div>
                            {summary.target !== null && (
                                <div>
                                    <span>Target</span>
                                    <strong>
                                        {formatWeight(summary.target)}
                                    </strong>
                                </div>
                            )}
                            {summary.change !== null && (
                                <div>
                                    <span>Change from first entry</span>
                                    <strong>
                                        {summary.change > 0 ? "+" : ""}
                                        {summary.change} kg
                                    </strong>
                                </div>
                            )}
                        </div>
                        {trend.length > 1 && (
                            <BodyWeightChart entries={trend} />
                        )}
                    </>
                ) : (
                    <p>No body-weight measurements recorded yet.</p>
                )}
                {entries.data.length > 0 && (
                    <details className="weight-history">
                        <summary>View weight history</summary>
                        <ul className="weight-entry-list">
                            {entries.data.map((entry) => (
                                <li key={entry.id}>
                                    <div>
                                        <DateLabel
                                            date={entry.recorded_date}
                                            long
                                        />
                                        <strong>
                                            {formatWeight(entry.weight_kg)}
                                        </strong>
                                        {entry.note && <p>{entry.note}</p>}
                                    </div>
                                    {!coaching && (
                                        <div className="weight-entry-actions">
                                            <button
                                                className="icon-btn"
                                                aria-label={`Edit weight on ${entry.recorded_date}`}
                                                onClick={() =>
                                                    setEditing(entry)
                                                }
                                            >
                                                <Pencil
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                            </button>
                                            <button
                                                className="icon-btn danger-text"
                                                aria-label={`Delete weight on ${entry.recorded_date}`}
                                                onClick={() =>
                                                    setDeleting(entry)
                                                }
                                            >
                                                <Trash2
                                                    size={16}
                                                    aria-hidden="true"
                                                />
                                            </button>
                                        </div>
                                    )}
                                </li>
                            ))}
                        </ul>
                        <Pagination links={entries.links} />
                    </details>
                )}
                {!coaching && summary.target !== null && (
                    <ButtonLink
                        className="small"
                        variant="secondary"
                        href="/profile/edit#preferences"
                    >
                        Edit goal
                    </ButtonLink>
                )}
            </section>
            {editing && !coaching && (
                <BodyWeightForm
                    entry={editing.id ? editing : null}
                    onClose={() => setEditing(null)}
                />
            )}
            {deleting && !coaching && (
                <ConfirmDialog
                    title="Delete body weight entry?"
                    description="This removes this measurement and recalculates your progress."
                    url={`/body-weight/${deleting.id}`}
                    onClose={() => setDeleting(null)}
                />
            )}
        </div>
    );
}
