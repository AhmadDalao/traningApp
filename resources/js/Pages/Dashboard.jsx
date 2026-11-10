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
    Sparkles,
    Asterisk,
    TrendingUp,
    Scale,
} from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    StatusBadge,
    Modal,
    EmptyState,
    DateLabel,
    TimeLabel,
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
    const { auth, today, timezone } = usePage().props;
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
    const hour = Number(
        new Intl.DateTimeFormat("en-GB", {
            timeZone: timezone,
            hour: "numeric",
            hourCycle: "h23",
        }).format(new Date()),
    );
    const greeting =
        hour < 12
            ? "Good morning"
            : hour < 17
              ? "Good afternoon"
              : "Good evening";
    const cards = [
        [
            "Completed this week",
            stats.completed,
            "Training sessions completed",
            CircleCheck,
            "green",
        ],
        [
            "Planned this week",
            stats.planned,
            "Training sessions still planned",
            Clock3,
            "purple",
        ],
        [
            "Today’s sessions",
            stats.today,
            "Training and recovery on your schedule",
            CalendarDays,
            "orange",
        ],
        [
            "Coming up",
            stats.upcoming,
            "Scheduled sessions in the next 7 days",
            ArrowUpRight,
            "blue",
        ],
    ];
    return (
        <div className="personal-dashboard reference-overview">
            <Head title="Overview" />
            <PageHeader
                eyebrow={<DateLabel date={today} long />}
                title={
                    <>
                        {greeting}, {auth.user.name.split(" ")[0]}{" "}
                        <Asterisk className="greeting-mark" size={32} />
                    </>
                }
                description="Your training, your progress, and the next step forward."
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
            <section
                className="coach-focus-banner personal-focus"
                aria-label="Your training focus"
            >
                <span className="focus-symbol">
                    <Sparkles size={28} />
                </span>
                <div>
                    <small>
                        {activeWorkout
                            ? "KEEP YOUR MOMENTUM"
                            : nextWorkout
                              ? "YOUR NEXT SESSION"
                              : "YOUR NEXT STEP"}
                    </small>
                    <h2>
                        {activeWorkout
                            ? activeWorkout.title
                            : nextWorkout
                              ? nextWorkout.title
                              : "Make time for your next workout."}
                    </h2>
                    <p>
                        {activeWorkout ? (
                            "Your workout is in progress. Pick up where you left off."
                        ) : nextWorkout ? (
                            <>
                                <DateLabel date={nextWorkout.scheduled_date} />{" "}
                                ·{" "}
                                {nextWorkout.scheduled_time ? (
                                    <TimeLabel
                                        time={nextWorkout.scheduled_time}
                                    />
                                ) : (
                                    "Anytime"
                                )}{" "}
                                · Ready when you are.
                            </>
                        ) : (
                            "Choose a training plan, then give it a place in your week."
                        )}
                    </p>
                </div>
                {activeWorkout ? (
                    <ButtonLink
                        href={`/schedule-entries/${activeWorkout.id}/workout`}
                    >
                        Resume workout <ArrowRight size={18} />
                    </ButtonLink>
                ) : nextWorkout ? (
                    <SessionStatusActions entry={nextWorkout} startOnly />
                ) : (
                    <ButtonLink href="/training-plans">
                        Find a training plan <ArrowRight size={18} />
                    </ButtonLink>
                )}
            </section>
            <div className="coach-stats coach-desktop-metrics">
                {cards.map(([label, value, note, Icon, color]) => (
                    <div className="panel coach-stat" key={label}>
                        <span className="metric-label">
                            {label}
                            <span className={`stat-icon ${color}`}>
                                <Icon size={22} />
                            </span>
                        </span>
                        <strong>{value}</strong>
                        <small>{note}</small>
                    </div>
                ))}
            </div>
            <div className="personal-dashboard-grid">
                <section
                    className="panel personal-dashboard-card"
                    aria-labelledby="personal-today-title"
                >
                    <div className="personal-card-heading">
                        <div>
                            <h2 id="personal-today-title">
                                Today’s training{" "}
                                <span className="count-bubble">
                                    {todayEntries.length}
                                </span>
                            </h2>
                            <p>Workouts and recovery, all in one place.</p>
                        </div>
                        <Link href={`/calendar?date=${today}`}>
                            Open calendar <ArrowRight size={16} />
                        </Link>
                    </div>
                    <div
                        className="personal-card-scroll"
                        role="region"
                        aria-label="Today's sessions"
                        tabIndex={0}
                    >
                        {todayEntries.length ? (
                            todayEntries.map((entry) => (
                                <article
                                    className="personal-session-row"
                                    key={entry.id}
                                >
                                    <span className="personal-session-time">
                                        {entry.scheduled_time ? (
                                            <TimeLabel
                                                time={entry.scheduled_time}
                                            />
                                        ) : (
                                            "Anytime"
                                        )}
                                    </span>
                                    <TrainingThumbnail item={entry} />
                                    <div className="personal-session-copy">
                                        <Link
                                            href={`/schedule-entries/${entry.id}`}
                                        >
                                            <strong>{entry.title}</strong>
                                        </Link>
                                        <small>
                                            {entry.entry_type === "rest"
                                                ? "Rest & recovery"
                                                : `${entry.focus_area || "Training"} · ${entry.exercises_count || 0} exercises`}
                                        </small>
                                    </div>
                                    <StatusBadge
                                        status={entry.status}
                                        active={
                                            entry.status === "planned" &&
                                            Boolean(entry.started_at)
                                        }
                                    />
                                    <div className="personal-session-actions">
                                        <ButtonLink
                                            href={`/schedule-entries/${entry.id}`}
                                            variant="secondary"
                                            className="small"
                                        >
                                            Details <ArrowRight size={14} />
                                        </ButtonLink>
                                        <SessionStatusActions
                                            entry={entry}
                                            compact
                                        />
                                    </div>
                                </article>
                            ))
                        ) : (
                            <EmptyState
                                compact
                                title="A little space in your day"
                                description="Schedule a workout, or enjoy a well-earned rest."
                            >
                                <Button onClick={() => setModal("schedule")}>
                                    <Plus size={16} />
                                    Schedule session
                                </Button>
                            </EmptyState>
                        )}
                    </div>
                    <div className="personal-card-footer">
                        <span>
                            {todayEntries.length}{" "}
                            {todayEntries.length === 1 ? "session" : "sessions"}{" "}
                            today
                        </span>
                        <Link href={`/calendar?date=${today}`}>
                            View your day <ArrowRight size={15} />
                        </Link>
                    </div>
                </section>
                <section
                    className="panel personal-dashboard-card"
                    aria-labelledby="personal-upcoming-title"
                >
                    <div className="personal-card-heading">
                        <div>
                            <h2 id="personal-upcoming-title">
                                Up next{" "}
                                <span className="count-bubble">
                                    {upcomingEntries.length}
                                </span>
                            </h2>
                            <p>Your next seven days.</p>
                        </div>
                        <Link href="/calendar">
                            View calendar <ArrowRight size={16} />
                        </Link>
                    </div>
                    <div
                        className="personal-card-scroll"
                        role="region"
                        aria-label="Upcoming sessions"
                        tabIndex={0}
                    >
                        {upcomingEntries.length ? (
                            upcomingEntries.map((entry) => (
                                <article
                                    className="personal-upcoming-row"
                                    key={entry.id}
                                >
                                    <div className="personal-date-tile">
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
                                    <TrainingThumbnail item={entry} />
                                    <div className="personal-session-copy">
                                        <Link
                                            href={`/schedule-entries/${entry.id}`}
                                        >
                                            <strong>{entry.title}</strong>
                                        </Link>
                                        <small>
                                            {entry.scheduled_time ? (
                                                <TimeLabel
                                                    time={entry.scheduled_time}
                                                />
                                            ) : (
                                                "Anytime"
                                            )}{" "}
                                            ·{" "}
                                            {entry.entry_type === "rest"
                                                ? "Rest & recovery"
                                                : entry.focus_area ||
                                                  "Training"}
                                        </small>
                                    </div>
                                    <StatusBadge
                                        status={entry.status}
                                        active={
                                            entry.status === "planned" &&
                                            Boolean(entry.started_at)
                                        }
                                    />
                                </article>
                            ))
                        ) : (
                            <EmptyState
                                compact
                                title="Your week is a blank slate"
                                description="Add a few sessions and give your week a little structure."
                            >
                                <Button
                                    variant="secondary"
                                    onClick={() => setModal("schedule")}
                                >
                                    Schedule session
                                </Button>
                            </EmptyState>
                        )}
                    </div>
                    <div className="personal-card-footer">
                        <span>
                            {upcomingEntries.length}{" "}
                            {upcomingEntries.length === 1
                                ? "session"
                                : "sessions"}{" "}
                            coming up
                        </span>
                        <Link href="/calendar">
                            Plan your week <ArrowRight size={15} />
                        </Link>
                    </div>
                </section>
                <div className="personal-current-training">
                    {programRuns.length ? (
                        <ProgramRunCards runs={programRuns} />
                    ) : (
                        <section className="panel personal-dashboard-card">
                            <div className="personal-card-heading">
                                <div>
                                    <h2>Your training plans</h2>
                                    <p>
                                        A starting point for your next session.
                                    </p>
                                </div>
                                <Link href="/training-plans">
                                    View all <ArrowRight size={16} />
                                </Link>
                            </div>
                            <div
                                className="personal-card-scroll"
                                role="region"
                                aria-label="Your training plans"
                                tabIndex={0}
                            >
                                {plans.length ? (
                                    plans.map((plan) => (
                                        <article
                                            className="personal-plan-row"
                                            key={plan.id}
                                        >
                                            <TrainingThumbnail item={plan} />
                                            <div>
                                                <Link
                                                    href={`/training-plans/${plan.id}`}
                                                >
                                                    <strong>{plan.name}</strong>
                                                </Link>
                                                <small>
                                                    {plan.structure_type ===
                                                    "program"
                                                        ? "Structured program"
                                                        : "Training plan"}{" "}
                                                    ·{" "}
                                                    {plan.training_days
                                                        ?.length || 0}{" "}
                                                    days
                                                </small>
                                            </div>
                                            <Link
                                                href={`/training-plans/${plan.id}`}
                                                aria-label={`View ${plan.name}`}
                                            >
                                                <ArrowRight size={18} />
                                            </Link>
                                        </article>
                                    ))
                                ) : (
                                    <EmptyState
                                        compact
                                        title="Build your training routine"
                                        description="Explore a plan or create one around your goals."
                                    >
                                        <ButtonLink href="/training-plans">
                                            Explore plans{" "}
                                            <ArrowRight size={16} />
                                        </ButtonLink>
                                    </EmptyState>
                                )}
                            </div>
                            <div className="personal-card-footer">
                                <span>
                                    {plans.length}{" "}
                                    {plans.length === 1 ? "plan" : "plans"}
                                </span>
                                <Link href="/training-plans">
                                    Explore training <ArrowRight size={15} />
                                </Link>
                            </div>
                        </section>
                    )}
                </div>
                <section
                    className="panel personal-dashboard-card personal-recent"
                    aria-labelledby="personal-recent-title"
                >
                    <div className="personal-card-heading">
                        <div>
                            <h2 id="personal-recent-title">Recent workout</h2>
                            <p>Every completed session counts.</p>
                        </div>
                        <Link href="/training-history">
                            View history <ArrowRight size={16} />
                        </Link>
                    </div>
                    <div className="personal-card-scroll">
                        {recentWorkout ? (
                            <div className="personal-recent-workout">
                                <TrainingThumbnail item={recentWorkout} />
                                <div>
                                    <StatusBadge status="completed" />
                                    <h3>{recentWorkout.title}</h3>
                                    <p>
                                        <DateLabel
                                            date={recentWorkout.scheduled_date}
                                        />
                                    </p>
                                    <ButtonLink
                                        href={`/training-history/${recentWorkout.id}`}
                                        variant="secondary"
                                    >
                                        View details <ArrowRight size={16} />
                                    </ButtonLink>
                                </div>
                            </div>
                        ) : (
                            <EmptyState
                                compact
                                title="Your progress starts here"
                                description="Complete a workout and see your latest result here."
                            />
                        )}
                    </div>
                    <Link href="/progress" className="personal-progress-link">
                        <span>
                            <TrendingUp size={21} />
                            <span>
                                <strong>See your progress</strong>
                                <small>
                                    Exercise trends, personal records and body
                                    weight
                                </small>
                            </span>
                        </span>
                        <ArrowRight size={19} />
                    </Link>
                </section>
            </div>
            {(checkIn || profileIncomplete) && (
                <div className="personal-reminders">
                    {checkIn && (
                        <section className="panel personal-check-in">
                            <Scale size={25} />
                            <div>
                                <h2>Weekly check-in</h2>
                                <p>
                                    {checkIn.last_weight
                                        ? `Last recorded weight: ${Number(checkIn.last_weight)} kg`
                                        : "Record your first body weight when you’re ready."}
                                    {checkIn.last_date && (
                                        <>
                                            {" "}
                                            ·{" "}
                                            <DateLabel
                                                date={checkIn.last_date}
                                            />
                                        </>
                                    )}
                                </p>
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
                    {profileIncomplete && (
                        <section className="panel personal-profile-prompt">
                            <Sparkles size={23} />
                            <div>
                                <h2>Make it yours</h2>
                                <p>
                                    Add your goals and training preferences to
                                    personalize your planner.
                                </p>
                            </div>
                            <ButtonLink href="/onboarding" variant="secondary">
                                Complete profile <ArrowRight size={16} />
                            </ButtonLink>
                        </section>
                    )}
                </div>
            )}
            {modal === "weight" && (
                <BodyWeightForm onClose={() => setModal(null)} />
            )}
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
        </div>
    );
}
