import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import {
    Pencil,
    Trash2,
    CalendarDays,
    Dumbbell,
    Layers,
    Coffee,
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
} from "../../Components/UI";
import ExerciseList from "../../Features/ExerciseList";
import SessionStatusActions from "../../Features/SessionStatusActions";
export default function Show({ entry }) {
    const [deleting, setDeleting] = useState(false);
    const day = entry.training_day;
    const exercises = day?.exercises || [];
    return (
        <>
            <Head title={entry.title} />
            <Breadcrumbs
                items={[
                    {
                        label: "Calendar",
                        href: `/calendar?date=${entry.scheduled_date}`,
                    },
                    { label: entry.title },
                ]}
            />
            <PageHeader
                eyebrow="YOUR SCHEDULED SESSION"
                title={entry.title}
                description={<DateLabel date={entry.scheduled_date} long />}
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
                        <p>You can change the status anytime.</p>
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
                        <Dumbbell size={17} />
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
            {entry.notes && (
                <section className="panel notes-panel">
                    <h3>Session notes</h3>
                    <p>{entry.notes}</p>
                </section>
            )}
            {entry.entry_type === "rest" ? (
                <section className="rest-callout">
                    <Coffee size={32} />
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
                            <h2>Your workout</h2>
                            {day ? (
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
                                <p>
                                    The original training day was deleted. Your
                                    session history is preserved.
                                </p>
                            )}
                        </div>
                    </div>
                    {day ? (
                        <ExerciseList exercises={exercises} />
                    ) : (
                        <div className="historical-note">
                            Exercise details are no longer available for this
                            session.
                        </div>
                    )}
                </section>
            )}
            <div className="session-footer">
                <ButtonLink
                    variant="secondary"
                    href={`/calendar?date=${entry.scheduled_date}`}
                >
                    <CalendarDays size={16} />
                    Back to calendar
                </ButtonLink>
                <button
                    className="text-button danger-text"
                    onClick={() => setDeleting(true)}
                >
                    <Trash2 size={15} />
                    Delete session
                </button>
            </div>
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
