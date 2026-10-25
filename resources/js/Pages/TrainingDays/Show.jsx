import { useState } from "react";
import { Head } from "@inertiajs/react";
import { Pencil, CalendarPlus, Coffee, Trash2, Dumbbell } from "lucide-react";
import {
    PageHeader,
    Breadcrumbs,
    ButtonLink,
    Button,
    TypeBadge,
    Modal,
    ConfirmDialog,
} from "../../Components/UI";
import ExerciseList from "../../Features/ExerciseList";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
export default function Show({ day }) {
    const [schedule, setSchedule] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const plan = { ...day.training_plan, training_days: [day] };
    return (
        <>
            <Head title={day.title} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: "/training-plans" },
                    { label: plan.name, href: `/training-plans/${plan.id}` },
                    { label: day.title },
                ]}
            />
            <PageHeader
                eyebrow="REUSABLE TRAINING DAY"
                title={day.title}
                description={
                    day.type === "rest"
                        ? "Make recovery a part of your routine."
                        : `${day.focus_area || "Training"} · ${day.exercises.length} exercises`
                }
            >
                <ButtonLink
                    href={`/training-days/${day.id}/edit`}
                    variant="secondary"
                >
                    <Pencil size={16} />
                    Edit day
                </ButtonLink>
                <Button onClick={() => setSchedule(true)}>
                    <CalendarPlus size={17} />
                    Schedule day
                </Button>
            </PageHeader>
            <div className="detail-meta">
                <TypeBadge type={day.type} />
                {day.focus_area && (
                    <span>
                        <Dumbbell size={16} />
                        {day.focus_area}
                    </span>
                )}
                <span>From {plan.name}</span>
            </div>
            {day.notes && (
                <section className="panel notes-panel">
                    <h3>Notes</h3>
                    <p>{day.notes}</p>
                </section>
            )}
            {day.type === "rest" ? (
                <section className="rest-callout">
                    <Coffee size={32} />
                    <div>
                        <h2>Recharge for what’s next.</h2>
                        <p>This is a rest day. No exercises required.</p>
                    </div>
                </section>
            ) : (
                <section className="panel">
                    <div className="panel-heading">
                        <div>
                            <h2>
                                Your exercises{" "}
                                <span className="count-bubble">
                                    {day.exercises.length}
                                </span>
                            </h2>
                            <p>
                                Follow the order below. Matching superset
                                letters go together.
                            </p>
                        </div>
                    </div>
                    <ExerciseList exercises={day.exercises} />
                </section>
            )}
            <div className="danger-zone">
                <button
                    className="text-button danger-text"
                    onClick={() => setDeleting(true)}
                >
                    <Trash2 size={15} />
                    Delete training day
                </button>
            </div>
            {schedule && (
                <Modal
                    title="Schedule a session"
                    onClose={() => setSchedule(false)}
                >
                    <ScheduleEntryForm
                        plans={[plan]}
                        selectedDay={day.id}
                        onSaved={() => setSchedule(false)}
                        onCancel={() => setSchedule(false)}
                    />
                </Modal>
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete training day?"
                    description="This day and its exercises will be permanently deleted. Calendar history will be preserved."
                    url={`/training-days/${day.id}`}
                    onClose={() => setDeleting(false)}
                />
            )}
        </>
    );
}
