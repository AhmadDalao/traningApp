import { RecordList } from "../../Features/ExerciseRecords";
import { Head, Link } from "@inertiajs/react";
import {
    CalendarPlus,
    Layers,
    CalendarDays,
    TrendingUp,
    AlertTriangle,
    CheckCircle2,
    Settings,
} from "lucide-react";
import {
    PageHeader,
    ButtonLink,
    DateLabel,
    StatusBadge,
} from "../../Components/UI";
import {
    CoachStatus,
    TraineeState,
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
        !state?.plan || !state?.has_schedule || !!state?.subscription_action;
    return (
        <>
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
                    <h2>Finish coaching setup</h2>
                    <p>{state?.reason}</p>
                    <ButtonLink href={ctx.url("/setup")}>
                        Continue setup
                    </ButtonLink>
                </section>
            )}
            <section className="panel coach-card assigned-training">
                <div className="coach-card-title">
                    <h2>
                        <Layers size={20} aria-hidden="true" /> Training
                        assigned by you
                    </h2>
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
                        <div>
                            <span className="field-hint">Current training</span>
                            <h3>{current.name}</h3>
                            <p>
                                {current.current_week
                                    ? `Week ${current.current_week} of ${current.duration_weeks}`
                                    : current.structure_type === "program"
                                      ? `${current.duration_weeks}-week program`
                                      : "Simple plan"}
                                {current.run_status
                                    ? ` · ${current.run_status}`
                                    : ""}
                            </p>
                            {run?.block && (
                                <p className="field-hint">
                                    {run.block}
                                    {run.adherence !== null
                                        ? ` · ${run.adherence}% adherence`
                                        : ""}
                                </p>
                            )}
                        </div>
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url(`/training-plans/${current.id}`)}
                        >
                            View program
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
                                                    plan.assignment?.assigned_at
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
            <div className="coach-grid trainee-primary-grid">
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <CalendarDays size={20} aria-hidden="true" /> This
                            week
                        </h2>
                        <ButtonLink
                            variant="secondary"
                            href={ctx.url("/calendar")}
                        >
                            View all
                        </ButtonLink>
                    </div>
                    <p>
                        {
                            thisWeek.filter(
                                (s) =>
                                    s.entry_type === "training" &&
                                    s.status === "completed",
                            ).length
                        }{" "}
                        /{" "}
                        {
                            thisWeek.filter((s) => s.entry_type === "training")
                                .length
                        }{" "}
                        assigned workouts completed
                    </p>
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
                                ) : s.started_at && s.status === "planned" ? (
                                    <span className="badge training">
                                        In progress
                                    </span>
                                ) : (
                                    <StatusBadge status={s.status} />
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
                    <h2>
                        <CalendarPlus size={20} aria-hidden="true" /> Next
                        workout
                    </h2>
                    {nextSession ? (
                        <>
                            <h3>{nextSession.title}</h3>
                            <p>
                                <DateLabel date={nextSession.scheduled_date} />{" "}
                                ·{" "}
                                {nextSession.scheduled_time?.slice(0, 5) ||
                                    "Anytime"}
                            </p>
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
                    {state &&
                        ["paused", "attention"].includes(
                            state.display_state,
                        ) && (
                            <div className="attention-note">
                                <h3>
                                    <AlertTriangle
                                        size={17}
                                        aria-hidden="true"
                                    />{" "}
                                    Needs attention
                                </h3>
                                <p>{state.reason}</p>
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
                            </div>
                        )}
                </section>
            </div>
            <div className="coach-grid">
                <section className="panel coach-card">
                    <h2>
                        <TrendingUp size={20} aria-hidden="true" /> Progress
                    </h2>
                    <p>
                        {completion === null
                            ? "No workouts due this month yet."
                            : `${completion}% completion this month`}
                    </p>
                    {body.current !== null && (
                        <p>
                            Current body weight ·{" "}
                            <strong>{body.current} kg</strong>
                        </p>
                    )}
                    {recentWorkout && (
                        <p>
                            <CheckCircle2 size={16} aria-hidden="true" /> Last
                            completed:{" "}
                            <Link
                                href={ctx.url(
                                    `/training-history/${recentWorkout.id}`,
                                )}
                            >
                                {recentWorkout.title}
                            </Link>{" "}
                            · <DateLabel date={recentWorkout.scheduled_date} />
                        </p>
                    )}
                    <ButtonLink variant="secondary" href={ctx.url("/progress")}>
                        View progress
                    </ButtonLink>
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>
                            <Settings size={20} aria-hidden="true" /> Coaching
                        </h2>
                        <CoachStatus status={subscription?.effective_status} />
                    </div>
                    <h3>{subscription?.package_name || "Not configured"}</h3>
                    <p>
                        {subscription?.ends_on
                            ? `Ends ${subscription.ends_on}`
                            : subscription
                              ? "Ongoing coaching"
                              : "Activate coaching to begin."}
                    </p>
                    <ButtonLink variant="secondary" href={ctx.url("/coaching")}>
                        Manage coaching
                    </ButtonLink>
                    {profile?.training_goals?.length > 0 && (
                        <p className="field-hint">
                            Goals ·{" "}
                            {profile.training_goals
                                .map((g) => g.replaceAll("_", " "))
                                .join(", ")}
                        </p>
                    )}
                </section>
            </div>
            {recentRecords.length > 0 && (
                <section className="panel coach-card">
                    <h2>Recent personal records</h2>
                    <RecordList records={recentRecords.slice(0, 3)} compact />
                </section>
            )}
        </>
    );
}
