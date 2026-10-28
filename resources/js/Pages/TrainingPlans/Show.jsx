import { useTraining } from "../../Features/CoachingUI";
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
import {
    CopyActions,
    DayCopyDialog,
    DuplicatePlanDialog,
} from "../../Features/TrainingCopyActions";
export default function Show({ plan, copyPlans = [] }) {
    const { url, canManage, coaching, templates } = useTraining();
    const { errors } = usePage().props;
    const [endingAssignment, setEndingAssignment] = useState(false);
    const [duplicating, setDuplicating] = useState(false);
    const [copying, setCopying] = useState(null);
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
            url(`/training-plans/${plan.id}/days/reorder`),
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
                    { label: "Training plans", href: url("/training-plans") },
                    { label: plan.name },
                ]}
            />
            <PageHeader
                eyebrow="YOUR TRAINING PLAN"
                title={plan.name}
                description={plan.description || "A routine built around you."}
            >
                {canManage && (
                    <Button variant="secondary" onClick={() => setEdit(true)}>
                        <Pencil size={16} />
                        Edit plan
                    </Button>
                )}
                {canManage && (
                    <ButtonLink
                        href={url(`/training-plans/${plan.id}/days/create`)}
                    >
                        <Plus size={17} />
                        Add training day
                    </ButtonLink>
                )}
                {canManage && !coaching && (
                    <CopyActions
                        label="More plan actions"
                        onDuplicate={() => setDuplicating(true)}
                    />
                )}
            </PageHeader>
            {!templates && (
                <section className="panel plan-period-panel">
                    <div>
                        <h2>Plan schedule</h2>
                        <p>
                            {plan.start_date && plan.end_date
                                ? `${plan.start_date} to ${plan.end_date}`
                                : "Add a start and end date to schedule your plan."}
                        </p>
                        <p>
                            Review generated dates, add missing sessions or
                            remove eligible future sessions after confirmation.
                        </p>
                        {errors.schedule && (
                            <p className="field-error" role="alert">
                                {errors.schedule}
                            </p>
                        )}
                    </div>
                    {canManage && (
                        <ButtonLink
                            href={url(
                                `/training-plans/${plan.id}/schedule-preview`,
                            )}
                        >
                            <CalendarPlus size={16} />
                            Manage schedule
                        </ButtonLink>
                    )}
                </section>
            )}
            {plan.coach_name && (
                <p className="coach-callout">
                    Coach assigned · {plan.coach_name}
                    {plan.assignment_status === "ended"
                        ? " · Assignment ended"
                        : ""}
                </p>
            )}
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
                                    <Link
                                        href={url(`/training-days/${day.id}`)}
                                    >
                                        <h3>{day.title}</h3>
                                    </Link>
                                    <p>
                                        {day.type === "rest"
                                            ? "Rest & recovery"
                                            : `${day.focus_area || "Training"} · ${day.exercises_count} exercises`}
                                    </p>
                                    <p>
                                        {weekdaySummary(day.weekdays || [])}
                                        {day.default_time
                                            ? ` · ${day.default_time.slice(0, 5)}`
                                            : ""}
                                    </p>
                                </div>
                                <TypeBadge type={day.type} />
                                <div className="day-actions">
                                    {canManage && (
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
                                                        plan.training_days
                                                            .length -
                                                            1 || busy
                                                }
                                                onClick={() => move(i, 1)}
                                            >
                                                <ArrowDown size={15} />
                                            </button>
                                        </div>
                                    )}
                                    <ButtonLink
                                        href={url(`/training-days/${day.id}`)}
                                        variant="secondary"
                                        className="small"
                                    >
                                        View
                                    </ButtonLink>
                                    {canManage && (
                                        <ButtonLink
                                            href={url(
                                                `/training-days/${day.id}/edit`,
                                            )}
                                            variant="secondary"
                                            className="small"
                                        >
                                            <Pencil size={14} />
                                            <span className="sr-only">
                                                Edit {day.title}
                                            </span>
                                        </ButtonLink>
                                    )}
                                    {canManage && !templates && (
                                        <Button
                                            variant="soft"
                                            className="small"
                                            onClick={() => setSchedule(day)}
                                        >
                                            <CalendarPlus size={15} />
                                            Schedule
                                        </Button>
                                    )}
                                    {canManage && (
                                        <CopyActions
                                            label={`More actions for ${day.title}`}
                                            onDuplicate={() =>
                                                setCopying({
                                                    day,
                                                    mode: "duplicate",
                                                })
                                            }
                                            onCopy={() =>
                                                setCopying({
                                                    day,
                                                    mode: "copy",
                                                })
                                            }
                                        />
                                    )}
                                    {canManage && (
                                        <button
                                            className="icon-btn danger-text"
                                            onClick={() => setDeleting(day)}
                                            aria-label={`Delete ${day.title}`}
                                        >
                                            <Trash2 size={15} />
                                        </button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <EmptyState
                        title="Give your plan some shape"
                        description="Add a training day with your exercises, or a rest day to recover."
                    >
                        {canManage && (
                            <ButtonLink
                                href={url(
                                    `/training-plans/${plan.id}/days/create`,
                                )}
                            >
                                <Plus size={16} />
                                Add your first day
                            </ButtonLink>
                        )}
                    </EmptyState>
                )}
            </section>
            {canManage && (
                <p className="below-note">
                    Use the arrows to arrange your days. Your calendar schedule
                    stays the same.
                </p>
            )}
            {coaching && plan.assignment_status === "active" && (
                <Button
                    variant="secondary"
                    onClick={() => setEndingAssignment(true)}
                >
                    End assignment
                </Button>
            )}
            {endingAssignment && (
                <ConfirmDialog
                    title="End coaching assignment?"
                    description="The trainee keeps this plan and all sessions. Coach editing will stop."
                    url={url(`/assignments/${plan.assignment_id}/end`)}
                    method="post"
                    data={{ confirmed: true }}
                    confirmLabel="End assignment"
                    onClose={() => setEndingAssignment(false)}
                />
            )}
            {duplicating && (
                <DuplicatePlanDialog
                    plan={plan}
                    onClose={() => setDuplicating(false)}
                />
            )}
            {copying && (
                <DayCopyDialog
                    {...copying}
                    plans={copyPlans}
                    onClose={() => setCopying(null)}
                />
            )}
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
                    url={url(`/training-days/${deleting.id}`)}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
