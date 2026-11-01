import { RecordList } from "../../Features/ExerciseRecords";
import { Head, Link } from "@inertiajs/react";
import {
    CalendarPlus,
    Dumbbell,
    CalendarDays,
    TrendingUp,
    AlertTriangle,
    Settings,
    ArrowRight,
    Trophy,
} from "lucide-react";
import {
    PageHeader,
    ButtonLink,
    DateLabel,
    StatusBadge,
} from "../../Components/UI";
import {
    CoachStatus,
    EndRelationship,
    useTraining,
} from "../../Features/CoachingUI";

export default function Trainee({
    state,
    thisWeek = [],
    programRuns = [],
    relationship,
    profile,
    body,
    subscription,
    plans,
    nextSession,
    recentWorkout,
    completion,
    recentRecords = [],
}) {
    const ctx = useTraining(),
        current = state?.plan,
        others = plans.filter((p) => p.id !== current?.id),
        run = programRuns.find((r) => r.id === current?.run_id);
    const incomplete =
        !current || !state?.has_schedule || !!state?.subscription_action;
    const weekTraining = thisWeek.filter((s) => s.entry_type === "training"),
        weekCompleted = weekTraining.filter(
            (s) => s.status === "completed",
        ).length;
    return (
        <div className="trainee-overview">
            <Head title={ctx.trainee.name} />
            <PageHeader
                title="Training overview"
                description="Training assigned by you, and the next useful step."
            >
                {state?.subscription_action && (
                    <ButtonLink href={state.subscription_action.url}>
                        {state.subscription_action.label}
                    </ButtonLink>
                )}
                {ctx.canManage && current && (
                    <ButtonLink href={ctx.url("/schedule-entries/create")}>
                        <CalendarPlus size={17} aria-hidden="true" /> Schedule
                        workout
                    </ButtonLink>
                )}
                <details className="trainee-more">
                    <summary>More</summary>
                    <div>
                        <Link href={ctx.url("/coaching")}>Manage coaching</Link>
                        {incomplete && (
                            <Link href={ctx.url("/setup")}>Continue setup</Link>
                        )}
                        <hr />
                        <EndRelationship
                            relationship={relationship}
                            label="Disconnect trainee"
                        />
                    </div>
                </details>
            </PageHeader>
            {incomplete && (
                <section className="panel coach-card setup-checklist">
                    <div>
                        <h2>Finish coaching setup</h2>
                        <p>{state?.reason}</p>
                    </div>
                    <ButtonLink href={ctx.url("/setup")}>
                        Continue setup{" "}
                        <ArrowRight size={16} aria-hidden="true" />
                    </ButtonLink>
                </section>
            )}
            <div className="trainee-training-grid">
                <section className="panel coach-card assigned-training">
                    <div className="coach-card-title">
                        <h2>Training assigned by you</h2>
                        {ctx.canManage && (
                            <ButtonLink
                                variant="secondary"
                                href={ctx.url("/assign")}
                            >
                                Assign training
                            </ButtonLink>
                        )}
                    </div>
                    {current ? (
                        <div className="current-training">
                            <span className="feature-icon">
                                <Dumbbell size={24} aria-hidden="true" />
                            </span>
                            <div>
                                <h3>{current.name}</h3>
                                <p>
                                    {current.current_week
                                        ? `Week ${current.current_week} of ${current.duration_weeks}`
                                        : current.structure_type === "program"
                                          ? `${current.duration_weeks}-week program`
                                          : "Simple plan"}
                                    {run?.block
                                        ? ` · ${run.block}`
                                        : current.run_status
                                          ? ` · ${current.run_status}`
                                          : ""}
                                </p>
                                {run?.progress_percent !== undefined && (
                                    <div className="program-mini-progress">
                                        <progress
                                            max="100"
                                            value={run.progress_percent}
                                            aria-label="Resolved program weeks"
                                        />
                                        <small>
                                            {run.progress_percent}% complete
                                        </small>
                                    </div>
                                )}
                            </div>
                            <ButtonLink
                                variant="secondary"
                                href={ctx.url(`/training-plans/${current.id}`)}
                            >
                                View program{" "}
                                <ArrowRight size={15} aria-hidden="true" />
                            </ButtonLink>
                        </div>
                    ) : (
                        <p>No training assigned yet.</p>
                    )}
                    {others.length > 0 && (
                        <details className="other-assignments">
                            <summary>
                                Other assignments by you ({others.length})
                            </summary>
                            <ul className="compact-activity">
                                {others.map((plan) => (
                                    <li key={plan.id}>
                                        <div>
                                            <Link
                                                href={ctx.url(
                                                    `/training-plans/${plan.id}`,
                                                )}
                                            >
                                                {plan.name}
                                            </Link>
                                            <small>
                                                Assigned{" "}
                                                <DateLabel
                                                    date={
                                                        plan.assignment
                                                            ?.assigned_at
                                                    }
                                                />
                                                {plan.program_runs?.[0]?.status
                                                    ? ` · ${plan.program_runs[0].status}`
                                                    : ""}
                                            </small>
                                        </div>
                                        <CoachStatus
                                            status={plan.assignment?.status}
                                        />
                                    </li>
                                ))}
                            </ul>
                        </details>
                    )}
                </section>
                <section className="panel coach-card trainee-coaching-card">
                    <div className="coach-card-title">
                        <h2>
                            <Settings size={18} aria-hidden="true" /> Coaching
                            status
                        </h2>
                    </div>
                    <CoachStatus status={subscription?.effective_status} />
                    <h3>{subscription?.package_name || "Not configured"}</h3>
                    <p>
                        {subscription?.ends_on ? (
                            <>
                                Ends <DateLabel date={subscription.ends_on} />
                            </>
                        ) : subscription ? (
                            "Ongoing coaching"
                        ) : (
                            "Activate coaching to begin."
                        )}
                    </p>
                    <ButtonLink variant="secondary" href={ctx.url("/coaching")}>
                        Manage coaching
                    </ButtonLink>
                </section>
            </div>
            <div className="trainee-quick-grid">
                <section className="panel coach-card">
                    <h2>Next workout</h2>
                    {nextSession ? (
                        <>
                            <div className="quick-workout">
                                <span className="feature-icon">
                                    <Dumbbell size={21} aria-hidden="true" />
                                </span>
                                <div>
                                    <h3>{nextSession.title}</h3>
                                    <p>
                                        <DateLabel
                                            date={nextSession.scheduled_date}
                                        />{" "}
                                        ·{" "}
                                        {nextSession.scheduled_time?.slice(
                                            0,
                                            5,
                                        ) || "Anytime"}
                                    </p>
                                </div>
                            </div>
                            <ButtonLink
                                variant="secondary"
                                href={ctx.url(
                                    `/schedule-entries/${nextSession.id}`,
                                )}
                            >
                                View session
                            </ButtonLink>
                        </>
                    ) : (
                        <p className="field-hint">
                            No upcoming assigned workout.
                        </p>
                    )}
                </section>
                <section className="panel coach-card">
                    <h2>This week</h2>
                    <div className="quick-value">
                        <span className="feature-icon green">
                            <CalendarDays size={20} aria-hidden="true" />
                        </span>
                        <strong>
                            {weekCompleted}{" "}
                            <small>/ {weekTraining.length}</small>
                        </strong>
                    </div>
                    <p>Assigned workouts completed</p>
                    {weekTraining.length > 0 && (
                        <progress
                            max={weekTraining.length}
                            value={weekCompleted}
                            aria-label="This week's completed workouts"
                        />
                    )}
                    <ButtonLink variant="secondary" href={ctx.url("/calendar")}>
                        View calendar
                    </ButtonLink>
                </section>
                <section className="panel coach-card">
                    <h2>Progress</h2>
                    <div className="quick-value">
                        <span className="feature-icon">
                            <TrendingUp size={20} aria-hidden="true" />
                        </span>
                        <strong>
                            {completion === null
                                ? "Getting started"
                                : `${completion}%`}
                        </strong>
                    </div>
                    <p>
                        {completion === null
                            ? "No workouts due this month yet."
                            : "Completion this month"}
                    </p>
                    {body.current !== null && (
                        <p>
                            Current body weight ·{" "}
                            <strong>{body.current} kg</strong>
                        </p>
                    )}
                    <ButtonLink variant="secondary" href={ctx.url("/progress")}>
                        View progress
                    </ButtonLink>
                </section>
            </div>
            {state && ["paused", "attention"].includes(state.display_state) && (
                <section className="panel coach-card trainee-attention">
                    <AlertTriangle size={22} aria-hidden="true" />
                    <div>
                        <h2>Needs attention</h2>
                        <p>{state.reason}</p>
                    </div>
                    <ButtonLink
                        variant="secondary"
                        href={
                            state.action.url === ctx.url("")
                                ? ctx.url("/calendar")
                                : state.action.url
                        }
                    >
                        {state.display_state === "paused"
                            ? state.action.label
                            : "Review schedule"}
                    </ButtonLink>
                </section>
            )}
            <div className="trainee-detail-grid">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <CalendarDays size={18} aria-hidden="true" /> This
                            week's schedule
                        </h2>
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url("/calendar")}
                        >
                            View all
                        </ButtonLink>
                    </div>
                    <ul className="compact-activity">
                        {thisWeek.slice(0, 5).map((s) => (
                            <li key={s.id}>
                                <div>
                                    <Link
                                        href={ctx.url(
                                            `/schedule-entries/${s.id}`,
                                        )}
                                    >
                                        {s.title}
                                    </Link>
                                    <small>
                                        <DateLabel date={s.scheduled_date} />
                                    </small>
                                </div>
                                {s.missed ? (
                                    <span className="badge skipped">
                                        Missed
                                    </span>
                                ) : (
                                    <StatusBadge
                                        status={s.status}
                                        active={
                                            s.status === "planned" &&
                                            !!s.started_at
                                        }
                                    />
                                )}
                            </li>
                        ))}
                    </ul>
                    {!thisWeek.length && (
                        <p className="field-hint">
                            No workouts scheduled this week.
                        </p>
                    )}
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <Trophy size={18} aria-hidden="true" /> Recent
                            performance
                        </h2>
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url("/training-history")}
                        >
                            View history
                        </ButtonLink>
                    </div>
                    {recentWorkout && (
                        <div className="recent-performance">
                            <span className="badge completed">Completed</span>
                            <Link
                                href={ctx.url(
                                    `/training-history/${recentWorkout.id}`,
                                )}
                            >
                                {recentWorkout.title}
                            </Link>
                            <small>
                                <DateLabel
                                    date={recentWorkout.scheduled_date}
                                />
                            </small>
                        </div>
                    )}
                    {recentRecords.length > 0 ? (
                        <RecordList
                            records={recentRecords.slice(0, 3)}
                            compact
                        />
                    ) : (
                        <p className="field-hint">
                            Personal records appear as training results improve.
                        </p>
                    )}
                </section>
            </div>
            {profile?.training_goals?.length > 0 && (
                <section className="panel coach-card trainee-goals">
                    <h2>Training goals</h2>
                    <div className="recommendation-tags">
                        {profile.training_goals.map((g) => (
                            <span className="badge training" key={g}>
                                {g.replaceAll("_", " ")}
                            </span>
                        ))}
                    </div>
                </section>
            )}
        </div>
    );
}
