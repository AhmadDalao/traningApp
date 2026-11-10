import TrainingThumbnail from "../../Components/TrainingThumbnail";
import PersonAvatar from "../../Components/PersonAvatar";
import {
    Users,
    CalendarDays,
    CheckCircle2,
    AlertTriangle,
    Clock,
    Sparkles,
    Plus,
    ArrowRight,
    TrendingUp,
} from "lucide-react";
import { Head, Link, usePage } from "@inertiajs/react";
import { PageHeader, ButtonLink } from "../../Components/UI";
import { CoachStatus } from "../../Features/CoachingUI";
import { WorkoutDate } from "../../Features/CoachWorkspace";
function SessionList({ entries, empty, grouped = false, showTrainee = true }) {
    return (
        <div className="coach-daily-list">
            {entries.map((entry) => (
                <article
                    className="coach-session"
                    key={grouped ? entry.user_id : entry.id}
                >
                    {showTrainee && <PersonAvatar name={entry.trainee_name} />}
                    <div className="coach-session-copy">
                        {showTrainee && (
                            <Link href={`/coach/trainees/${entry.user_id}`}>
                                <strong>{entry.trainee_name}</strong>
                            </Link>
                        )}
                        <div className="thumbnail-identity">
                            <TrainingThumbnail item={entry} />
                            <Link
                                href={`/coach/trainees/${entry.user_id}/schedule-entries/${entry.id}`}
                            >
                                {entry.title}
                            </Link>
                        </div>
                        {entry.scheduled_date && (
                            <small>
                                <WorkoutDate entry={entry} />
                            </small>
                        )}
                    </div>
                    {grouped ? (
                        <div className="coach-upcoming-count">
                            <strong>{entry.session_count} workouts</strong>
                            <Link
                                href={`/coach/trainees/${entry.user_id}/calendar`}
                            >
                                Calendar →
                            </Link>
                        </div>
                    ) : (
                        <CoachStatus status={entry.status} />
                    )}
                </article>
            ))}
            {!entries.length && <p className="coach-quiet-empty">{empty}</p>}
        </div>
    );
}
function TodayList({ entries }) {
    const groups = new Map();
    for (const entry of entries)
        groups.set(entry.user_id, [
            ...(groups.get(entry.user_id) || []),
            entry,
        ]);
    return entries.length ? (
        [...groups].map(([user, rows]) => (
            <section className="coach-today-group" key={user}>
                <div className="person-identity">
                    <PersonAvatar name={rows[0].trainee_name} />
                    <Link href={`/coach/trainees/${user}`}>
                        <strong>{rows[0].trainee_name}</strong>
                    </Link>
                    <small>
                        {rows.length} workout{rows.length === 1 ? "" : "s"}
                    </small>
                </div>
                <SessionList entries={rows} showTrainee={false} />
            </section>
        ))
    ) : (
        <p className="coach-quiet-empty">No workouts scheduled today.</p>
    );
}
export default function Overview({
    stats,
    todaySessions = [],
    upcomingGroups = [],
    recent,
    attention = [],
    pending = [],
    workload = [],
    momentum = [],
}) {
    const { today } = usePage().props;
    const peak = Math.max(1, ...workload.map((day) => day.scheduled));
    return (
        <div className="coach-dashboard">
            <Head title="Coach Overview" />
            <PageHeader
                eyebrow={new Date(`${today}T12:00:00`).toLocaleDateString(
                    "en-GB",
                    {
                        weekday: "long",
                        day: "numeric",
                        month: "long",
                        year: "numeric",
                    },
                )}
                title="Coach Overview"
                description="Here’s what matters across your coaching workspace today."
            >
                <ButtonLink href="/coach/trainees" variant="secondary">
                    <Users size={17} />
                    Manage trainees
                </ButtonLink>
                <ButtonLink href="/coach/training-plans/create">
                    <Plus size={17} />
                    New program
                </ButtonLink>
            </PageHeader>
            <section className="coach-focus-banner">
                <span className="focus-symbol">
                    <Sparkles size={27} />
                </span>
                <div>
                    <small>YOUR FOCUS FOR TODAY</small>
                    <h2>
                        {stats.attention
                            ? `${stats.attention} ${stats.attention === 1 ? "trainee could" : "trainees could"} use your attention.`
                            : "Your trainees are on track."}
                    </h2>
                    <p>
                        Review missed workouts, pauses and unfinished training
                        setups.
                    </p>
                </div>
                <a href="#priority-inbox" className="btn btn-secondary">
                    Review priority inbox <ArrowRight size={17} />
                </a>
            </section>
            <div className="coach-stats coach-desktop-metrics">
                {[
                    [
                        "Connected trainees",
                        stats.trainees,
                        Users,
                        "purple",
                        "Accepted coaching connections",
                    ],
                    [
                        "Training today",
                        stats.today,
                        CalendarDays,
                        "orange",
                        "Trainees with planned or completed training",
                    ],
                    [
                        "Completed today",
                        stats.completed,
                        CheckCircle2,
                        "green",
                        "Completed workouts scheduled today",
                    ],
                    [
                        "Need attention",
                        stats.attention,
                        AlertTriangle,
                        "orange",
                        "Issues to review, separate from coverage",
                    ],
                ].map(([label, count, Icon, color, hint]) => (
                    <div className="panel coach-stat" key={label}>
                        <span className="metric-label">
                            {label}
                            <span className={`stat-icon ${color}`}>
                                <Icon size={18} aria-hidden="true" />
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
                        <h2>
                            <AlertTriangle size={19} aria-hidden="true" />{" "}
                            Priority inbox
                        </h2>
                        <Link href="/coach/trainees?status=attention">
                            View trainees →
                        </Link>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Trainees needing attention"
                    >
                        {attention.map((row) => (
                            <article className="coach-session" key={row.id}>
                                <PersonAvatar name={row.trainee.name} />
                                <div className="coach-session-copy">
                                    <Link
                                        href={`/coach/trainees/${row.trainee.id}`}
                                    >
                                        <strong>{row.trainee.name}</strong>
                                    </Link>
                                    <span>{row.reason}</span>
                                </div>
                                <ButtonLink
                                    variant="secondary"
                                    href={row.action.url}
                                >
                                    {row.action.label === "Continue Assignment"
                                        ? "Assign Training"
                                        : row.action.label}
                                </ButtonLink>
                            </article>
                        ))}
                        {!attention.length && (
                            <p className="on-track">
                                <CheckCircle2 size={18} aria-hidden="true" />{" "}
                                Everyone is on track.
                            </p>
                        )}
                        {pending.length > 0 && (
                            <div className="coach-pending-summary">
                                <Link href="/coach/trainees?status=pending">
                                    {stats.pending} pending requests →
                                </Link>
                            </div>
                        )}
                    </div>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <CalendarDays size={19} aria-hidden="true" />{" "}
                            Today’s training
                        </h2>
                        <span className="count-bubble">
                            {todaySessions.length}
                        </span>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Today’s workouts"
                    >
                        <TodayList entries={todaySessions} />
                    </div>
                </section>
            </div>
            <div className="coach-overview-columns coach-insights">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <CalendarDays size={19} />
                            Coaching workload
                        </h2>
                        <span className="badge planned">This week</span>
                    </div>
                    <p>Scheduled workouts and their completed portion.</p>
                    <div
                        className="workload-chart"
                        role="img"
                        aria-label={workload
                            .map(
                                (d) =>
                                    `${d.date}: ${d.completed} completed of ${d.scheduled} scheduled`,
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
                                    {new Date(
                                        `${day.date}T12:00:00`,
                                    ).toLocaleDateString("en-GB", {
                                        weekday: "short",
                                    })}
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
                    </div>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <TrendingUp size={19} />
                            Trainee momentum
                        </h2>
                        <Link href="/coach/trainees">View roster →</Link>
                    </div>
                    <p>
                        Completed / scheduled workouts this week, across your
                        assignments.
                    </p>
                    <div
                        className="momentum-list"
                        tabIndex={0}
                        aria-label="Weekly trainee progress"
                    >
                        {momentum.map((row) => (
                            <Link
                                className="momentum-row"
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
                        ))}
                        {!momentum.length && (
                            <p className="coach-quiet-empty">
                                Your connected trainees will appear here.
                            </p>
                        )}
                    </div>
                </section>
            </div>
            <div className="coach-overview-columns">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>Upcoming · Next 7 days</h2>
                        <small>Next workout per trainee</small>
                    </div>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Upcoming workouts"
                    >
                        <SessionList
                            entries={upcomingGroups}
                            grouped
                            empty="No upcoming workouts in the next seven days."
                        />
                    </div>
                </section>
                <section className="panel coach-card">
                    <h2>
                        <Clock size={19} aria-hidden="true" /> Recent activity
                    </h2>
                    <div
                        className="coach-panel-scroll"
                        tabIndex={0}
                        aria-label="Recent activity"
                    >
                        <SessionList
                            entries={recent}
                            empty="Activity appears when trainees record their workouts."
                        />
                    </div>
                </section>
            </div>
        </div>
    );
}
