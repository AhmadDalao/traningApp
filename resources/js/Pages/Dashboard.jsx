import TrainingThumbnail from "../Components/TrainingThumbnail";
import { ProgramRunCards } from "../Features/ProgramUI";
import { useState } from "react";
import { Head, Link, usePage, router } from "@inertiajs/react";
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
import BodyWeightForm from "../Features/BodyWeightForm";
export default function Dashboard({
    programRuns = [],
    stats,
    todayEntries,
    upcomingEntries,
    plans,
    checkIn,
    profileIncomplete,
    activeWorkout,
    recentWorkout,
}) {
    const { auth, today } = usePage().props;
    const [modal, setModal] = useState(null);
    const nextWorkout =
        todayEntries.find(
            (entry) =>
                entry.status === "planned" && entry.entry_type === "training",
        ) ||
        upcomingEntries.find(
            (entry) =>
                entry.status === "planned" && entry.entry_type === "training",
        );
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
            <ProgramRunCards runs={programRuns} />
            {activeWorkout && (
                <div className="profile-reminder workout-resume-banner">
                    <span>
                        <strong>{activeWorkout.title}</strong> · In progress
                    </span>
                    <ButtonLink
                        href={`/schedule-entries/${activeWorkout.id}/workout`}
                        className="small"
                    >
                        Resume workout
                    </ButtonLink>
                </div>
            )}
            {profileIncomplete && (
                <div className="profile-reminder">
                    <span>
                        Personalize your planner with optional goals and
                        training preferences.
                    </span>
                    <ButtonLink
                        href="/onboarding"
                        variant="secondary"
                        className="small"
                    >
                        Complete profile
                    </ButtonLink>
                </div>
            )}
            {checkIn && (
                <section className="panel weekly-check-in">
                    <div>
                        <h2>Weekly check-in</h2>
                        <p>How are you progressing?</p>
                        <small>
                            {checkIn.last_weight
                                ? `Last recorded weight: ${Number(checkIn.last_weight)} kg`
                                : "Record your first body weight when you’re ready."}
                            {checkIn.last_date && (
                                <>
                                    {" "}
                                    · <DateLabel date={checkIn.last_date} />
                                </>
                            )}
                        </small>
                    </div>
                    <div className="check-in-actions">
                        <Button onClick={() => setModal("weight")}>
                            Record today's weight
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() =>
                                router.post(
                                    "/body-weight-check-in/dismiss",
                                    {},
                                    { preserveScroll: true },
                                )
                            }
                        >
                            Not now
                        </Button>
                    </div>
                </section>
            )}
            <div className="dashboard-feature-grid">
                <section className="panel dashboard-feature">
                    <h2>Next session</h2>
                    {nextWorkout ? (
                        <>
                            <div className="dashboard-feature-workout">
                                <TrainingThumbnail item={nextWorkout} />
                                <div>
                                    <h3>{nextWorkout.title}</h3>
                                    <p>
                                        <DateLabel
                                            date={nextWorkout.scheduled_date}
                                        />{" "}
                                        ·{" "}
                                        {nextWorkout.scheduled_time?.slice(
                                            0,
                                            5,
                                        ) || "Anytime"}
                                    </p>
                                </div>
                            </div>
                            <SessionStatusActions
                                entry={nextWorkout}
                                startOnly
                            />
                        </>
                    ) : (
                        <>
                            <p>
                                No upcoming workout. Give your week a little
                                structure.
                            </p>
                            <Button
                                variant="secondary"
                                onClick={() => setModal("schedule")}
                            >
                                Schedule session
                            </Button>
                        </>
                    )}
                </section>
                <section className="panel dashboard-feature">
                    <h2>Recent workout</h2>
                    {recentWorkout ? (
                        <>
                            <div className="dashboard-feature-workout">
                                <TrainingThumbnail item={recentWorkout} />
                                <div>
                                    <h3>{recentWorkout.title}</h3>
                                    <p>
                                        <DateLabel
                                            date={recentWorkout.scheduled_date}
                                        />{" "}
                                        · Completed
                                    </p>
                                </div>
                            </div>
                            <ButtonLink
                                variant="secondary"
                                href={`/training-history/${recentWorkout.id}`}
                            >
                                View details{" "}
                                <ArrowRight size={16} aria-hidden="true" />
                            </ButtonLink>
                        </>
                    ) : (
                        <>
                            <p>Your completed workouts will appear here.</p>
                            <ButtonLink
                                variant="secondary"
                                href="/training-history"
                            >
                                View history
                            </ButtonLink>
                        </>
                    )}
                </section>
            </div>
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
            {modal === "weight" && (
                <BodyWeightForm onClose={() => setModal(null)} />
            )}
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
                    <div
                        className="dashboard-session-list"
                        role="region"
                        aria-label="Today's sessions"
                    >
                        {todayEntries.length ? (
                            <div className="today-list">
                                {todayEntries.slice(0, 3).map((entry) => (
                                    <article
                                        className={`session-card ${entry.status}`}
                                        key={entry.id}
                                    >
                                        <div className="session-main">
                                            <TrainingThumbnail item={entry} />
                                            <div>
                                                <div className="session-title">
                                                    <Link
                                                        href={`/schedule-entries/${entry.id}`}
                                                    >
                                                        <h3>{entry.title}</h3>
                                                    </Link>
                                                    <StatusBadge
                                                        status={entry.status}
                                                        active={
                                                            entry.status ===
                                                                "planned" &&
                                                            Boolean(
                                                                entry.started_at,
                                                            )
                                                        }
                                                    />
                                                </div>
                                                <p>
                                                    {entry.entry_type === "rest"
                                                        ? "Recovery is part of the plan."
                                                        : `${entry.focus_area || "Training"} · ${entry.exercises_count || 0} exercises`}
                                                    {entry.scheduled_time &&
                                                        ` · ${entry.scheduled_time.slice(0, 5)}`}
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
                    </div>
                </section>
                <section className="panel upcoming-panel">
                    <div className="panel-heading">
                        <div>
                            <h2>Up next</h2>
                            <p>Your next seven days.</p>
                        </div>
                        <TextLink href="/calendar">View all</TextLink>
                    </div>
                    <div
                        className="dashboard-session-list"
                        role="region"
                        aria-label="Upcoming sessions"
                    >
                        {upcomingEntries.length ? (
                            <div className="upcoming-list">
                                {upcomingEntries.slice(0, 4).map((entry) => (
                                    <article
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
                                                {entry.scheduled_date.slice(
                                                    8,
                                                    10,
                                                )}
                                            </strong>
                                        </div>
                                        <TrainingThumbnail item={entry} />
                                        <div className="upcoming-info">
                                            <h3>
                                                <Link
                                                    href={`/schedule-entries/${entry.id}`}
                                                >
                                                    {entry.title}
                                                </Link>
                                            </h3>
                                            <p>
                                                {entry.entry_type === "rest"
                                                    ? "Rest & recovery"
                                                    : entry.focus_area ||
                                                      "Training"}
                                            </p>
                                        </div>
                                        <StatusBadge
                                            status={entry.status}
                                            active={
                                                entry.status === "planned" &&
                                                Boolean(entry.started_at)
                                            }
                                        />
                                    </article>
                                ))}
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                title="Your week is a blank slate"
                                description="Add a few sessions and give your week a little structure."
                            />
                        )}
                    </div>
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
