import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import { Pencil, CalendarPlus, Trash2 } from "lucide-react";
import {
    PageHeader,
    Breadcrumbs,
    ButtonLink,
    Button,
    TypeBadge,
    Modal,
    ConfirmDialog,
} from "../../Components/UI";
import TrainingIcon from "../../Components/TrainingIcon";
import { weekdaySummary } from "../../Components/WeekdayPicker";
import ExerciseList from "../../Features/ExerciseList";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
import { CopyActions, DayCopyDialog } from "../../Features/TrainingCopyActions";
export default function Show({ day, copyPlans = [], canEditDay = true }) {
    const { url, canManage, coaching, templates } = useTraining();
    const [copying, setCopying] = useState(null);
    const [schedule, setSchedule] = useState(false);
    const [deleting, setDeleting] = useState(false);
    const plan = { ...day.training_plan, training_days: [day] };
    return (
        <>
            <Head title={day.title} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: url("/training-plans") },
                    {
                        label: plan.name,
                        href: url(`/training-plans/${plan.id}`),
                    },
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
                {canManage && canEditDay && (
                    <ButtonLink
                        href={url(`/training-days/${day.id}/edit`)}
                        variant="secondary"
                    >
                        <Pencil size={16} />
                        Edit day
                    </ButtonLink>
                )}
                {canManage &&
                    !templates &&
                    day.training_plan.structure_type !== "program" && (
                        <Button onClick={() => setSchedule(true)}>
                            <CalendarPlus size={17} />
                            Schedule day
                        </Button>
                    )}
                {canManage && canEditDay && (
                    <CopyActions
                        label="More day actions"
                        onDuplicate={() => setCopying("duplicate")}
                        onCopy={() => setCopying("copy")}
                    />
                )}
            </PageHeader>
            {day.training_plan.structure_type === "program" && (
                <p className="program-context">
                    This workout belongs to a program block.{" "}
                    <Link href={url(`/training-plans/${day.training_plan.id}`)}>
                        View program and review active run changes
                    </Link>
                    .
                </p>
            )}
            <div className="detail-meta">
                <TypeBadge type={day.type} />
                <span>
                    <TrainingIcon
                        icon={day.icon}
                        type={day.type}
                        focus={day.focus_area}
                        size={18}
                    />
                    {day.type === "rest"
                        ? "Recovery"
                        : day.focus_area || "General training"}
                </span>
                <span>From {plan.name}</span>
                <span>
                    {weekdaySummary(day.weekdays || [])}
                    {day.default_time
                        ? ` · ${day.default_time.slice(0, 5)}`
                        : ""}
                </span>
            </div>
            {day.notes && (
                <section className="panel notes-panel">
                    <h3>Notes</h3>
                    <p>{day.notes}</p>
                </section>
            )}
            {day.type === "rest" ? (
                <section className="rest-callout">
                    <TrainingIcon icon={day.icon} type={day.type} size={32} />
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
                                Exercises linked in a superset are performed
                                together.
                            </p>
                        </div>
                    </div>
                    <ExerciseList exercises={day.exercises} />
                </section>
            )}
            {canManage && canEditDay && (
                <div className="danger-zone">
                    <button
                        className="text-button danger-text"
                        onClick={() => setDeleting(true)}
                    >
                        <Trash2 size={15} />
                        Delete training day
                    </button>
                </div>
            )}
            {copying && (
                <DayCopyDialog
                    day={day}
                    mode={copying}
                    plans={copyPlans}
                    onClose={() => setCopying(null)}
                />
            )}
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
                    url={url(`/training-days/${day.id}`)}
                    onClose={() => setDeleting(false)}
                />
            )}
        </>
    );
}
