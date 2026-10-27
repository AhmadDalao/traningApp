import { useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import {
    Pencil,
    Trash2,
    CalendarDays,
    Layers,
    CheckCircle2,
    MinusCircle,
} from "lucide-react";
import {
    PageHeader,
    Breadcrumbs,
    ButtonLink,
    DateLabel,
    StatusBadge,
    TypeBadge,
    ConfirmDialog,
    Button,
    TimeLabel,
} from "../../Components/UI";
import TrainingIcon from "../../Components/TrainingIcon";
import ExerciseList from "../../Features/ExerciseList";
import SessionStatusActions from "../../Features/SessionStatusActions";
import ActualWeightsForm from "../../Features/ActualWeightsForm";
import WorkoutDetailsForm from "../../Features/WorkoutDetailsForm";
import { calorieDisplay } from "../../Features/calories";
export default function Show({ entry, workout, history = false }) {
    const [deleting, setDeleting] = useState(false);
    const [editingWeights, setEditingWeights] = useState(false);
    const { timezone } = usePage().props;
    const day = entry.training_day;
    const calories = calorieDisplay(entry);
    const exercises = workout?.exercises || [];
    const saved = Boolean(entry.workout_snapshot);
    const canRecordWeights =
        entry.status === "completed" &&
        entry.entry_type === "training" &&
        saved &&
        exercises.length > 0;
    const backUrl = history
        ? "/training-history"
        : `/calendar?date=${entry.scheduled_date}`;
    return (
        <>
            <Head title={entry.title} />
            <Breadcrumbs
                items={[
                    {
                        label: history ? "Training History" : "Calendar",
                        href: backUrl,
                    },
                    { label: entry.title },
                ]}
            />
            <PageHeader
                eyebrow={
                    history
                        ? "TRAINING HISTORY SESSION DETAILS"
                        : "YOUR SCHEDULED SESSION"
                }
                title={entry.title}
                description={
                    <>
                        <DateLabel date={entry.scheduled_date} long />
                        {entry.scheduled_time && (
                            <>
                                {" "}
                                · <TimeLabel time={entry.scheduled_time} />
                            </>
                        )}
                    </>
                }
            >
                <SessionStatusActions entry={entry} />
                <ButtonLink
                    href={`/schedule-entries/${entry.id}/edit`}
                    variant="secondary"
                >
                    <Pencil size={16} />
                    Edit
                </ButtonLink>
            </PageHeader>
            {entry.status !== "planned" && (
                <div className={`status-banner ${entry.status}`}>
                    {entry.status === "completed" ? (
                        <CheckCircle2 size={21} />
                    ) : (
                        <MinusCircle size={21} />
                    )}
                    <div>
                        <strong>
                            {entry.status === "completed"
                                ? "Session completed. Keep showing up."
                                : "Session skipped. There’s always a next one."}
                        </strong>
                        <p>
                            {entry.completed_at && (
                                <>
                                    Completed on{" "}
                                    {new Date(
                                        entry.completed_at,
                                    ).toLocaleString("en-GB", {
                                        timeZone: timezone,
                                        dateStyle: "medium",
                                        timeStyle: "short",
                                    })}
                                    .{" "}
                                </>
                            )}
                            You can change the status anytime.
                        </p>
                    </div>
                </div>
            )}
            <div className="session-summary panel">
                <div>
                    <span className="meta-label">SESSION STATUS</span>
                    <StatusBadge status={entry.status} />
                </div>
                <div>
                    <span className="meta-label">SESSION TYPE</span>
                    <TypeBadge type={entry.entry_type} />
                </div>
                <div>
                    <span className="meta-label">FOCUS AREA</span>
                    <strong>
                        <TrainingIcon
                            icon={entry.icon}
                            type={entry.entry_type}
                            focus={entry.focus_area}
                            size={18}
                        />
                        {entry.entry_type === "rest"
                            ? "Recovery"
                            : entry.focus_area || "General training"}
                    </strong>
                </div>
                <div>
                    <span className="meta-label">EXERCISES</span>
                    <strong>
                        <Layers size={17} />
                        {entry.entry_type === "rest"
                            ? "Rest day"
                            : `${exercises.length} exercises`}
                    </strong>
                </div>
            </div>
            {entry.status === "completed" &&
                entry.entry_type === "training" && (
                    <>
                        <div className="session-metrics">
                            <div>
                                <span>Actual duration</span>
                                <strong>
                                    {entry.duration_minutes !== null &&
                                    entry.duration_minutes !== undefined
                                        ? `${entry.duration_minutes} min`
                                        : "—"}
                                </strong>
                                {entry.intensity && (
                                    <small>
                                        Intensity:{" "}
                                        {entry.intensity[0].toUpperCase() +
                                            entry.intensity.slice(1)}
                                    </small>
                                )}
                            </div>
                            <div>
                                <span>Calories</span>
                                <strong>{calories.value}</strong>
                                {calories.label && (
                                    <small>{calories.label}</small>
                                )}
                                {entry.calories_burned != null &&
                                    entry.estimated_calories != null && (
                                        <small>
                                            App estimate ≈{" "}
                                            {entry.estimated_calories} kcal
                                        </small>
                                    )}
                            </div>
                        </div>
                        <WorkoutDetailsForm
                            key={`${entry.id}-${entry.duration_minutes}-${entry.intensity}-${entry.calories_burned}-${entry.calories_source}-${entry.estimated_calories}`}
                            entry={entry}
                        />
                    </>
                )}
            {entry.notes && (
                <section className="panel notes-panel">
                    <h3>Session notes</h3>
                    <p>{entry.notes}</p>
                </section>
            )}
            {entry.entry_type === "rest" ? (
                <section className="rest-callout">
                    <TrainingIcon
                        icon={entry.icon}
                        type={entry.entry_type}
                        size={32}
                    />
                    <div>
                        <h2>Take a breath. Recharge.</h2>
                        <p>
                            Rest days are part of the plan and don’t count
                            towards completed workouts.
                        </p>
                    </div>
                </section>
            ) : (
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2>{saved ? "Saved workout" : "Your workout"}</h2>
                            {saved ? (
                                <p>
                                    {workout?.training_day_title || entry.title}
                                    {workout?.training_plan_name
                                        ? ` · ${workout.training_plan_name}`
                                        : ""}
                                    . Changes to your training plan won’t change
                                    this workout.
                                </p>
                            ) : day && entry.status !== "completed" ? (
                                <p>
                                    From{" "}
                                    <Link
                                        className="inline-link"
                                        href={`/training-days/${day.id}`}
                                    >
                                        {day.title}
                                    </Link>{" "}
                                    · {day.training_plan.name}
                                </p>
                            ) : (
                                <p>Your session details are preserved.</p>
                            )}
                        </div>
                        {canRecordWeights && (
                            <Button
                                variant="secondary"
                                onClick={() => setEditingWeights(true)}
                            >
                                <Pencil size={15} />
                                Edit actual weights
                            </Button>
                        )}
                    </div>
                    {workout ? (
                        <ExerciseList
                            exercises={exercises}
                            showActualWeights={
                                entry.status === "completed" && saved
                            }
                            emptyDescription={
                                saved
                                    ? "No exercises were available when this workout was saved."
                                    : undefined
                            }
                        />
                    ) : (
                        <div className="historical-note">
                            {entry.status === "completed"
                                ? "This session was completed before workout records were saved. Its original exercises and completion time aren’t available."
                                : "The original training day was deleted. Exercise details are no longer available for this session."}
                        </div>
                    )}
                </section>
            )}
            <div className="session-footer">
                <ButtonLink variant="secondary" href={backUrl}>
                    <CalendarDays size={16} />
                    {history ? "Back to Training History" : "Back to calendar"}
                </ButtonLink>
                <button
                    className="text-button danger-text"
                    onClick={() => setDeleting(true)}
                >
                    <Trash2 size={15} />
                    Delete session
                </button>
            </div>
            {editingWeights && (
                <ActualWeightsForm
                    entry={entry}
                    exercises={exercises}
                    onClose={() => setEditingWeights(false)}
                />
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete training session?"
                    description={`“${entry.title}” will be permanently removed from your calendar. This cannot be undone.`}
                    url={`/schedule-entries/${entry.id}`}
                    onClose={() => setDeleting(false)}
                />
            )}
        </>
    );
}
