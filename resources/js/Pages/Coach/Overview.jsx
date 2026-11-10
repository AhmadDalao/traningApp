import { useState } from "react";
import PersonAvatar from "../../Components/PersonAvatar";
import {
    Users,
    CalendarDays,
    CheckCircle2,
    AlertTriangle,
    Sparkles,
    Plus,
    ArrowRight,
    Asterisk,
} from "lucide-react";
import { Head, Link, usePage } from "@inertiajs/react";
import { PageHeader, Button, ButtonLink, TimeLabel } from "../../Components/UI";
import { CoachStatus } from "../../Features/CoachingUI";
import AddTrainee from "../../Features/AddTrainee";

export default function Overview({
    stats,
    todaySessions = [],
    attention = [],
    workload = [],
    momentum = [],
}) {
    const { today, timezone } = usePage().props;
    const [adding, setAdding] = useState(false);
    const [allToday, setAllToday] = useState(false);
    const [allMomentum, setAllMomentum] = useState(false);
    const peak = Math.max(1, ...workload.map((day) => day.scheduled));
    const dateLabel = (date, options) =>
        new Date(`${date}T12:00:00`).toLocaleDateString("en-GB", options);
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
    const workouts = todaySessions
        .filter((entry) => entry.entry_type === "training")
        .sort(
            (a, b) =>
                (a.scheduled_time || "").localeCompare(
                    b.scheduled_time || "",
                ) || a.id - b.id,
        );
    const planned = workouts.filter(
        (entry) => entry.status === "planned",
    ).length;
    return (
        <div className="coach-dashboard reference-overview">
            <Head title="Coach Overview" />
            <PageHeader
                eyebrow={dateLabel(today, {
                    weekday: "long",
                    day: "numeric",
                    month: "long",
                    year: "numeric",
                })}
                title={
                    <>
                        {greeting}, Coach{" "}
                        <Asterisk className="greeting-mark" size={32} />
                    </>
                }
                description="Here’s what matters across your coaching workspace today."
            >
                <Button variant="secondary" onClick={() => setAdding(true)}>
                    <Plus size={18} />
                    Add trainee
                </Button>
                <ButtonLink href="/coach/training-plans/create">
                    <Plus size={18} />
                    New program
                </ButtonLink>
            </PageHeader>
            <section className="coach-focus-banner">
                <span className="focus-symbol">
                    <Sparkles size={28} />
                </span>
                <div>
                    <small>YOUR FOCUS FOR TODAY</small>
                    <h2>
                        {stats.attention
                            ? `${stats.attention} ${stats.attention === 1 ? "trainee could" : "trainees could"} use your attention.`
                            : "Your trainees are on track."}
                    </h2>
                    <p>
                        Check missed workouts and unfinished training setups
                        before planning ahead.
                    </p>
                </div>
                <Link
                    href="/coach/trainees?status=review"
                    className="btn btn-secondary"
                >
                    Review priority queue <ArrowRight size={20} />
                </Link>
            </section>
            <div className="coach-stats coach-desktop-metrics">
                {[
                    [
                        "Active trainees",
                        stats.trainees,
                        Users,
                        "purple",
                        "Connected coaching profiles",
                    ],
                    [
                        "Workouts today",
                        workouts.length,
                        CalendarDays,
                        "orange",
                        `Sessions scheduled for ${dateLabel(today, { month: "short", day: "numeric" })}`,
                    ],
                    [
                        "Completed today",
                        stats.completed,
                        CheckCircle2,
                        "green",
                        "Scheduled workout completions",
                    ],
                    [
                        "Needs attention",
                        stats.attention,
                        AlertTriangle,
                        "red",
                        "Issues to review, not a coaching status",
                    ],
                ].map(([label, count, Icon, color, hint]) => (
                    <div className="panel coach-stat" key={label}>
                        <span className="metric-label">
                            {label}
                            <span className={`stat-icon ${color}`}>
                                <Icon size={22} />
                            </span>
                        </span>
                        <strong>{count}</strong>
                        <small>{hint}</small>
                    </div>
                ))}
            </div>
            <div className="coach-overview-columns">
                <section className="panel coach-card" id="priority-inbox">
                    <div className="coach-card-title">
                        <div>
                            <h2>Priority inbox</h2>
                            <p>
                                People who need your attention and a clear next
                                step.
                            </p>
                        </div>
                        <Link href="/coach/trainees?status=review">
                            See all <ArrowRight size={17} />
                        </Link>
                    </div>
                    <div
                        className="priority-preview"
                        aria-label="Trainees needing attention"
                    >
                        {attention.slice(0, 3).map((row) => (
                            <article className="coach-session" key={row.id}>
                                <PersonAvatar name={row.trainee.name} />
                                <div className="coach-session-copy">
                                    <Link
                                        href={`/coach/trainees/${row.trainee.id}`}
                                    >
                                        <strong>{row.trainee.name}</strong>
                                    </Link>
                                    <span>{row.reason}</span>
                                    <small className="priority-reason">
                                        {row.reason.includes("missed")
                                            ? "At risk · review inactivity"
                                            : "Suggested follow-up"}
                                    </small>
                                </div>
                                <ButtonLink
                                    variant="secondary"
                                    href={row.action.url}
                                >
                                    Review <ArrowRight size={16} />
                                </ButtonLink>
                            </article>
                        ))}
                        {!attention.length && (
                            <p className="coach-quiet-empty">
                                <CheckCircle2 size={18} />
                                Everyone is on track.
                            </p>
                        )}
                    </div>
                    <footer className="coach-card-footer">
                        <span>
                            Attention is evaluated separately from coaching
                            status.
                        </span>
                        <Link href="/coach/trainees">
                            Go to trainees <ArrowRight size={17} />
                        </Link>
                    </footer>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <div>
                            <h2>Today’s training</h2>
                            <p>
                                {planned} planned{" "}
                                {planned === 1 ? "session" : "sessions"} ·{" "}
                                {stats.completed} completed
                            </p>
                        </div>
                        <button
                            className="text-button"
                            onClick={() => setAllToday(!allToday)}
                            aria-expanded={allToday}
                        >
                            {allToday ? "Show preview" : "View schedule"}{" "}
                            <ArrowRight size={17} />
                        </button>
                    </div>
                    <div
                        className={`today-preview ${allToday ? "expanded" : ""}`}
                        tabIndex={allToday ? 0 : undefined}
                        aria-label="Today’s workouts"
                    >
                        {(allToday ? workouts : workouts.slice(0, 4)).map(
                            (entry) => (
                                <article
                                    className="today-workout"
                                    key={entry.id}
                                >
                                    <span className="today-workout-time">
                                        {entry.scheduled_time ? (
                                            <TimeLabel
                                                time={entry.scheduled_time}
                                            />
                                        ) : (
                                            "Anytime"
                                        )}
                                    </span>
                                    <PersonAvatar name={entry.trainee_name} />
                                    <Link
                                        className="today-workout-copy"
                                        href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                                    >
                                        <strong>{entry.title}</strong>
                                        <span>{entry.trainee_name}</span>
                                    </Link>
                                    <CoachStatus status={entry.status} />
                                    <Link
                                        className="today-calendar-link"
                                        aria-label={`Open calendar for ${entry.trainee_name}`}
                                        href={`/coach/trainees/${entry.user_id}/calendar`}
                                    >
                                        <CalendarDays size={16} />
                                    </Link>
                                </article>
                            ),
                        )}
                        {!workouts.length && (
                            <p className="coach-quiet-empty">
                                No workouts scheduled today.
                            </p>
                        )}
                    </div>
                    <footer className="coach-card-footer">
                        <span>
                            Showing{" "}
                            {allToday
                                ? workouts.length
                                : Math.min(4, workouts.length)}{" "}
                            of {workouts.length} sessions
                        </span>
                        <button
                            className="text-button"
                            onClick={() => setAllToday(!allToday)}
                            aria-expanded={allToday}
                        >
                            {allToday
                                ? "Show fewer"
                                : `View all ${workouts.length} workouts`}{" "}
                            <ArrowRight size={17} />
                        </button>
                    </footer>
                </section>
            </div>
            <div className="coach-overview-columns coach-insights">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <div>
                            <h2>Coaching workload</h2>
                            <p>
                                Scheduled and completed workouts
                                {workload.length
                                    ? ` · ${dateLabel(workload[0].date, { month: "short", day: "numeric" })}–${dateLabel(workload[6].date, { day: "numeric" })}`
                                    : ""}
                            </p>
                        </div>
                        <span className="badge planned">
                            <CalendarDays size={14} />
                            This week
                        </span>
                    </div>
                    <div
                        className="workload-chart"
                        role="img"
                        aria-label={workload
                            .map(
                                (day) =>
                                    `${day.date}: ${day.completed} completed of ${day.scheduled} scheduled`,
                            )
                            .join("; ")}
                    >
                        {workload.map((day) => (
                            <div className="workload-day" key={day.date}>
                                <strong>{day.scheduled}</strong>
                                <div className="workload-track">
                                    <div
                                        className="workload-scheduled"
                                        style={{
                                            height: `${(day.scheduled / peak) * 100}%`,
                                        }}
                                    >
                                        <div
                                            className="workload-completed"
                                            style={{
                                                height: `${day.scheduled ? (day.completed / day.scheduled) * 100 : 0}%`,
                                            }}
                                        />
                                    </div>
                                </div>
                                <span>
                                    {dateLabel(day.date, { weekday: "short" })}
                                </span>
                            </div>
                        ))}
                    </div>
                    <div className="chart-key">
                        <span>
                            <i />
                            Scheduled
                        </span>
                        <span>
                            <i />
                            Completed
                        </span>
                        <small>Completed is part of the scheduled total</small>
                    </div>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <div>
                            <h2>Trainee momentum</h2>
                            <p>Completed / scheduled activities this week.</p>
                        </div>
                        <Link href="/coach/trainees">
                            View roster <ArrowRight size={17} />
                        </Link>
                    </div>
                    <div
                        className={`momentum-list ${allMomentum ? "expanded" : ""}`}
                        aria-label="Weekly trainee progress"
                        tabIndex={allMomentum ? 0 : undefined}
                    >
                        {(allMomentum ? momentum : momentum.slice(0, 4)).map(
                            (row) => (
                                <Link
                                    className={`momentum-row ${row.week.total && row.week.completed / row.week.total < 0.5 ? "low" : ""}`}
                                    href={`/coach/trainees/${row.trainee.id}`}
                                    key={row.id}
                                >
                                    <PersonAvatar name={row.trainee.name} />
                                    <div>
                                        <div>
                                            <strong>{row.trainee.name}</strong>
                                            <span>
                                                {row.week.completed}/
                                                {row.week.total} ·{" "}
                                                {row.week.total
                                                    ? `${Math.round((row.week.completed / row.week.total) * 100)}%`
                                                    : "Not scheduled"}
                                            </span>
                                        </div>
                                        <progress
                                            max={row.week.total || 1}
                                            value={row.week.completed}
                                            aria-label={`${row.trainee.name} workouts completed this week`}
                                        />
                                    </div>
                                </Link>
                            ),
                        )}
                        {!momentum.length && (
                            <p className="coach-quiet-empty">
                                Your connected trainees will appear here.
                            </p>
                        )}
                    </div>
                    <footer className="coach-card-footer">
                        <span>
                            Completion of this week’s scheduled training.
                        </span>
                        {momentum.length > 4 && (
                            <button
                                className="text-button"
                                onClick={() => setAllMomentum(!allMomentum)}
                            >
                                {allMomentum ? "Show fewer" : "View all"}
                                <ArrowRight size={16} />
                            </button>
                        )}
                    </footer>
                </section>
            </div>
            {adding && <AddTrainee close={() => setAdding(false)} />}
        </div>
    );
}
