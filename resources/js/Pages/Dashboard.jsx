import { useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import {
    Plus,
    CalendarDays,
    CircleCheck,
    Clock3,
    ArrowUpRight,
    ArrowRight,
    Dumbbell,
} from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    StatusBadge,
    DayIcon,
    Modal,
    EmptyState,
    TextLink,
    DateLabel,
    TypeBadge,
} from "../Components/UI";
import TrainingPlanForm from "../Features/TrainingPlanForm";
import ScheduleEntryForm from "../Features/ScheduleEntryForm";
import SessionStatusActions from "../Features/SessionStatusActions";
export default function Dashboard({
    stats,
    todayEntries,
    upcomingEntries,
    plans,
}) {
    const { auth, today } = usePage().props;
    const [modal, setModal] = useState(null);
    const cards = [
        {
            label: "Completed",
            value: stats.completed,
            note: "training sessions this week",
            icon: CircleCheck,
            color: "green",
        },
        {
            label: "Planned",
            value: stats.planned,
            note: "training sessions this week",
            icon: Clock3,
            color: "blue",
        },
        {
            label: "Today",
            value: stats.today,
            note: "sessions on your schedule",
            icon: CalendarDays,
            color: "purple",
        },
        {
            label: "Coming up",
            value: stats.upcoming,
            note: "sessions in the next 7 days",
            icon: ArrowUpRight,
            color: "orange",
        },
    ];
    return (
        <>
            <Head title="Overview" />
            <PageHeader
                eyebrow={<DateLabel date={today} long />}
                title={`Let’s keep moving, ${auth.user.name.split(" ")[0]}.`}
                description="A clear plan. A little consistency. A stronger you."
            >
                <Button variant="secondary" onClick={() => setModal("plan")}>
                    <Plus size={17} />
                    Create plan
                </Button>
                <Button onClick={() => setModal("schedule")}>
                    <Plus size={17} />
                    Schedule session
                </Button>
            </PageHeader>
            <div className="stats-grid">
                {cards.map(({ label, value, note, icon: Icon, color }) => (
                    <div className="stat-card" key={label}>
                        <div className="stat-top">
                            <span>{label}</span>
                            <div className={`stat-icon ${color}`}>
                                <Icon size={19} />
                            </div>
                        </div>
                        <strong>{value}</strong>
                        <p>{note}</p>
                    </div>
                ))}
            </div>
            <div className="dashboard-columns">
                <section className="panel today-panel">
                    <div className="panel-heading">
                        <div>
                            <h2>
                                Today’s sessions{" "}
                                <span className="count-bubble">
                                    {todayEntries.length}
                                </span>
                            </h2>
                            <p>Make today a step forward.</p>
                        </div>
                        <TextLink href={`/calendar?date=${today}`}>
                            Calendar
                        </TextLink>
                    </div>
                    {todayEntries.length ? (
                        <div className="today-list">
                            {todayEntries.map((entry) => (
                                <article
                                    className={`session-card ${entry.status}`}
                                    key={entry.id}
                                >
                                    <div className="session-main">
                                        <DayIcon
                                            type={entry.entry_type}
                                            focus={entry.focus_area}
                                            large
                                        />
                                        <div>
                                            <div className="session-title">
                                                <Link
                                                    href={`/schedule-entries/${entry.id}`}
                                                >
                                                    <h3>{entry.title}</h3>
                                                </Link>
                                                <StatusBadge
                                                    status={entry.status}
                                                />
                                            </div>
                                            <p>
                                                {entry.entry_type === "rest"
                                                    ? "Recovery is part of the plan."
                                                    : `${entry.focus_area || "Training"} · ${entry.training_day?.exercises_count || 0} exercises`}
                                            </p>
                                        </div>
                                    </div>
                                    <div className="session-bottom">
                                        <ButtonLink
                                            href={`/schedule-entries/${entry.id}`}
                                            variant="secondary"
                                            className="small"
                                        >
                                            View details
                                            <ArrowRight size={14} />
                                        </ButtonLink>
                                        <SessionStatusActions
                                            entry={entry}
                                            compact
                                        />
                                    </div>
                                </article>
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            compact
                            title="A little space in your day"
                            description="Schedule a workout, or give yourself a well-earned rest."
                        >
                            <Button onClick={() => setModal("schedule")}>
                                <Plus size={16} />
                                Schedule session
                            </Button>
                        </EmptyState>
                    )}
                </section>
                <section className="panel upcoming-panel">
                    <div className="panel-heading">
                        <div>
                            <h2>Up next</h2>
                            <p>Your next seven days.</p>
                        </div>
                        <TextLink href="/calendar">View all</TextLink>
                    </div>
                    {upcomingEntries.length ? (
                        <div className="upcoming-list">
                            {upcomingEntries.map((entry) => (
                                <Link
                                    href={`/schedule-entries/${entry.id}`}
                                    key={entry.id}
                                    className="upcoming-row"
                                >
                                    <div className="date-tile">
                                        <span>
                                            {new Date(
                                                `${entry.scheduled_date}T12:00:00`,
                                            ).toLocaleDateString("en", {
                                                weekday: "short",
                                            })}
                                        </span>
                                        <strong>
                                            {entry.scheduled_date.slice(8, 10)}
                                        </strong>
                                    </div>
                                    <div className="upcoming-info">
                                        <h3>{entry.title}</h3>
                                        <p>
                                            {entry.entry_type === "rest"
                                                ? "Rest & recovery"
                                                : entry.focus_area ||
                                                  "Training"}
                                        </p>
                                    </div>
                                    <StatusBadge status={entry.status} />
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <EmptyState
                            compact
                            title="Your week is a blank slate"
                            description="Add a few sessions and give your week a little structure."
                        />
                    )}
                </section>
            </div>
            <section className="dashboard-bottom">
                <div>
                    <div className="tip-label">
                        <Dumbbell size={17} />
                        BUILT AROUND YOU
                    </div>
                    <h2>Good training starts with a good plan.</h2>
                    <p>
                        Organize your days, add your exercises, and make it your
                        own.
                    </p>
                </div>
                <ButtonLink href="/training-plans" variant="secondary">
                    Explore your plans
                    <ArrowRight size={17} />
                </ButtonLink>
            </section>
            {modal === "plan" && (
                <Modal
                    title="Create training plan"
                    description="Give your next chapter a name."
                    onClose={() => setModal(null)}
                >
                    <TrainingPlanForm
                        onSaved={() => setModal(null)}
                        onCancel={() => setModal(null)}
                    />
                </Modal>
            )}
            {modal === "schedule" && (
                <Modal
                    title="Schedule a session"
                    description="Make a little time for progress."
                    onClose={() => setModal(null)}
                >
                    <ScheduleEntryForm
                        plans={plans}
                        onSaved={() => setModal(null)}
                        onCancel={() => setModal(null)}
                    />
                </Modal>
            )}
        </>
    );
}
