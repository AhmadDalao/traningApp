import { useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    Plus,
    Pencil,
    Trash2,
    ArrowUp,
    ArrowDown,
    CalendarPlus,
    ArrowRight,
} from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Breadcrumbs,
    DayIcon,
    TypeBadge,
    EmptyState,
    ConfirmDialog,
    Modal,
} from "../../Components/UI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
import ScheduleEntryForm from "../../Features/ScheduleEntryForm";
import { weekdaySummary } from "../../Components/WeekdayPicker";
export default function Show({ plan }) {
    const { errors } = usePage().props;
    const [edit, setEdit] = useState(false);
    const [deleting, setDeleting] = useState(null);
    const [schedule, setSchedule] = useState(null);
    const [busy, setBusy] = useState(false);
    const [orderError, setOrderError] = useState("");
    function move(index, offset) {
        const ids = plan.training_days.map((d) => d.id);
        [ids[index], ids[index + offset]] = [ids[index + offset], ids[index]];
        setBusy(true);
        router.patch(
            `/training-plans/${plan.id}/days/reorder`,
            { ids },
            {
                preserveScroll: true,
                onError: (e) =>
                    setOrderError(e.ids || "Could not reorder days."),
                onFinish: () => setBusy(false),
            },
        );
    }
    return (
        <>
            <Head title={plan.name} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: "/training-plans" },
                    { label: plan.name },
                ]}
            />
            <PageHeader
                eyebrow="YOUR TRAINING PLAN"
                title={plan.name}
                description={plan.description || "A routine built around you."}
            >
                <Button variant="secondary" onClick={() => setEdit(true)}>
                    <Pencil size={16} />
                    Edit plan
                </Button>
                <ButtonLink href={`/training-plans/${plan.id}/days/create`}>
                    <Plus size={17} />
                    Add training day
                </ButtonLink>
            </PageHeader>
            <section className="panel plan-period-panel">
                <div>
                    <h2>Plan schedule</h2>
                    <p>{plan.start_date && plan.end_date ? `${plan.start_date} to ${plan.end_date}` : "Add a start and end date to schedule your plan."}</p>
                    <p>Pick weekdays in each training day, then preview the dates before adding them to Calendar.</p>
                    {errors.schedule && <p className="field-error" role="alert">{errors.schedule}</p>}
                </div>
                {plan.start_date && plan.end_date ? <ButtonLink href={`/training-plans/${plan.id}/schedule-preview`}><CalendarPlus size={16} />Preview schedule</ButtonLink> : <Button variant="secondary" onClick={() => setEdit(true)}>Set plan dates</Button>}
            </section>
            <section className="panel">
                <div className="panel-heading">
                    <div>
                        <h2>
                            Training days{" "}
                            <span className="count-bubble">
                                {plan.training_days.length}
                            </span>
                        </h2>
                        <p>
                            Reusable workouts and rest days, with the weekdays
                            you choose for each one.
                        </p>
                    </div>
                </div>
                {orderError && (
                    <p className="field-error panel-padding">{orderError}</p>
                )}
                {plan.training_days.length ? (
                    <div className="day-list">
                        {plan.training_days.map((day, i) => (
                            <div className="day-row" key={day.id}>
                                <span className="row-number">
                                    {String(i + 1).padStart(2, "0")}
                                </span>
                                <DayIcon
                                    icon={day.icon}
                                    type={day.type}
                                    focus={day.focus_area}
                                />
                                <div className="day-info">
                                    <Link href={`/training-days/${day.id}`}>
                                        <h3>{day.title}</h3>
                                    </Link>
                                    <p>
                                        {day.type === "rest"
                                            ? "Rest & recovery"
                                            : `${day.focus_area || "Training"} · ${day.exercises_count} exercises`}
                                    </p>
                                    <p>{weekdaySummary(day.weekdays || [])}{day.default_time ? ` · ${day.default_time.slice(0, 5)}` : ""}</p>
                                </div>
                                <TypeBadge type={day.type} />
                                <div className="day-actions">
                                    <div className="reorder">
                                        <button
                                            className="icon-btn"
                                            aria-label={`Move ${day.title} up`}
                                            disabled={!i || busy}
                                            onClick={() => move(i, -1)}
                                        >
                                            <ArrowUp size={15} />
                                        </button>
                                        <button
                                            className="icon-btn"
                                            aria-label={`Move ${day.title} down`}
                                            disabled={
                                                i ===
                                                    plan.training_days.length -
                                                        1 || busy
                                            }
                                            onClick={() => move(i, 1)}
                                        >
                                            <ArrowDown size={15} />
                                        </button>
                                    </div>
                                    <ButtonLink
                                        href={`/training-days/${day.id}`}
                                        variant="secondary"
                                        className="small"
                                    >
                                        View
                                    </ButtonLink>
                                    <ButtonLink
                                        href={`/training-days/${day.id}/edit`}
                                        variant="secondary"
                                        className="small"
                                    >
                                        <Pencil size={14} />
                                        <span className="sr-only">
                                            Edit {day.title}
                                        </span>
                                    </ButtonLink>
                                    <Button
                                        variant="soft"
                                        className="small"
                                        onClick={() => setSchedule(day)}
                                    >
                                        <CalendarPlus size={15} />
                                        Schedule
                                    </Button>
                                    <button
                                        className="icon-btn danger-text"
                                        onClick={() => setDeleting(day)}
                                        aria-label={`Delete ${day.title}`}
                                    >
                                        <Trash2 size={15} />
                                    </button>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        title="Give your plan some shape"
                        description="Add a training day with your exercises, or a rest day to recover."
                    >
                        <ButtonLink
                            href={`/training-plans/${plan.id}/days/create`}
                        >
                            <Plus size={16} />
                            Add your first day
                        </ButtonLink>
                    </EmptyState>
                )}
            </section>
            <p className="below-note">
                Use the arrows to arrange your days. Your calendar schedule
                stays the same.
            </p>
            {edit && (
                <Modal
                    title="Edit training plan"
                    onClose={() => setEdit(false)}
                >
                    <TrainingPlanForm
                        plan={plan}
                        onSaved={() => setEdit(false)}
                        onCancel={() => setEdit(false)}
                    />
                </Modal>
            )}
            {schedule && (
                <Modal
                    title="Schedule a session"
                    onClose={() => setSchedule(null)}
                >
                    <ScheduleEntryForm
                        plans={[plan]}
                        selectedDay={schedule.id}
                        onSaved={() => setSchedule(null)}
                        onCancel={() => setSchedule(null)}
                    />
                </Modal>
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete training day?"
                    description={`“${deleting.title}” and its exercises will be removed. Scheduled sessions will remain in your calendar.`}
                    url={`/training-days/${deleting.id}`}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
