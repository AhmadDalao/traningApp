import { ProgramRunCards } from "../../Features/ProgramUI";
import { RecordList } from "../../Features/ExerciseRecords";
import { Head, Link } from "@inertiajs/react";
import { PageHeader, ButtonLink, DateLabel } from "../../Components/UI";
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
    const ctx = useTraining();
    return (
        <>
            <Head title={ctx.trainee.name} />
            <PageHeader
                title="Training overview"
                description="Training, coaching, and the next step for this trainee."
            >
                {ctx.canManage && state?.plan && (
                    <ButtonLink href={ctx.url("/schedule-entries/create")}>
                        Schedule workout
                    </ButtonLink>
                )}
                {ctx.canManage && state?.plan && (
                    <ButtonLink
                        variant="secondary"
                        href={ctx.url(`/training-plans/${state.plan.id}`)}
                    >
                        Adjust program
                    </ButtonLink>
                )}
                <details className="trainee-more">
                    <summary>More</summary>
                    <div>
                        <Link href={ctx.url("/coaching")}>Manage coaching</Link>
                        <Link href={ctx.url("/setup")}>Review setup</Link>
                        <hr />
                        <EndRelationship
                            relationship={relationship}
                            label="Disconnect trainee"
                        />
                    </div>
                </details>
            </PageHeader>
            {state?.display_state === "setup" && (
                <section className="panel coach-card setup-checklist">
                    <h2>Finish coaching setup</h2>
                    <p>{state.reason}</p>
                    <ol>
                        <li>
                            Coaching{" "}
                            {state.subscription?.effective_status === "active"
                                ? "✓ Saved"
                                : "· Not active"}
                        </li>
                        <li>
                            Training{" "}
                            {state.plan ? "✓ Assigned" : "· Not assigned"}
                        </li>
                        <li>
                            Schedule{" "}
                            {state.has_schedule ? "✓ Saved" : "· Needs review"}
                        </li>
                    </ol>
                    <ButtonLink href={ctx.url("/setup")}>
                        Continue setup
                    </ButtonLink>
                </section>
            )}
            {state && ["paused", "attention"].includes(state.display_state) && (
                <section className="panel coach-card">
                    <TraineeState state={state.display_state} />
                    <h2>{state.reason}</h2>
                    <ButtonLink
                        variant="secondary"
                        href={
                            state.display_state === "paused"
                                ? state.action.url
                                : ctx.url("/coaching")
                        }
                    >
                        {state.display_state === "paused"
                            ? state.action.label
                            : "Review coaching"}
                    </ButtonLink>
                </section>
            )}
            <div className="coach-grid">
                {[
                    ["Next session", nextSession],
                    ["Recent workout", recentWorkout],
                ].map(([label, entry]) => (
                    <section className="panel coach-card" key={label}>
                        <h2>{label}</h2>
                        {entry ? (
                            <>
                                <p>
                                    {entry.title} ·{" "}
                                    <DateLabel date={entry.scheduled_date} />
                                </p>
                                <ButtonLink
                                    href={ctx.url(
                                        `/schedule-entries/${entry.id}`,
                                    )}
                                    variant="secondary"
                                >
                                    View session
                                </ButtonLink>
                            </>
                        ) : (
                            <>
                                <p>No session yet.</p>
                                {label === "Next session" &&
                                    ctx.canManage &&
                                    state?.plan && (
                                        <ButtonLink
                                            href={ctx.url(
                                                "/schedule-entries/create",
                                            )}
                                        >
                                            Schedule workout
                                        </ButtonLink>
                                    )}
                            </>
                        )}
                    </section>
                ))}
            </div>
            <section className="panel coach-card">
                <div className="coach-card-title">
                    <h2>This week</h2>
                    <ButtonLink variant="secondary" href={ctx.url("/calendar")}>
                        View calendar
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
                    {thisWeek.filter((s) => s.entry_type === "training").length}{" "}
                    workouts completed across all shared training
                </p>
                <ul className="setup-schedule">
                    {thisWeek.map((s) => (
                        <li key={s.id}>
                            <DateLabel date={s.scheduled_date} />
                            <strong>{s.title}</strong>
                            <Link href={ctx.url(`/schedule-entries/${s.id}`)}>
                                {s.missed
                                    ? "Missed · Review"
                                    : s.started_at && s.status === "planned"
                                      ? "In progress"
                                      : s.status}
                            </Link>
                        </li>
                    ))}
                </ul>
                {!thisWeek.length && <p>No workouts scheduled this week.</p>}
            </section>
            <ProgramRunCards runs={programRuns} />
            <div className="coach-stats">
                <div className="panel coach-stat">
                    <span>Current body weight</span>
                    <strong>
                        {body.current === null ? "—" : `${body.current} kg`}
                    </strong>
                </div>
                <div className="panel coach-stat">
                    <span>Completion this month</span>
                    <strong>
                        {completion === null ? "—" : `${completion}%`}
                    </strong>
                </div>
                <div className="panel coach-stat">
                    <span>Coaching</span>
                    <strong className="coach-small-value">
                        {subscription?.package_name || "None yet"}
                    </strong>
                    <CoachStatus status={subscription?.effective_status} />
                    <ButtonLink variant="secondary" href={ctx.url("/coaching")}>
                        Manage coaching
                    </ButtonLink>
                </div>
                <div className="panel coach-stat">
                    <span>Training goals</span>
                    <strong className="coach-small-value">
                        {profile?.training_goals
                            ?.map((g) => g.replaceAll("_", " "))
                            .join(", ") || "Not set"}
                    </strong>
                </div>
            </div>
            <section className="panel coach-card">
                <h2>Your assigned plans</h2>
                {plans.map((plan) => (
                    <p key={plan.id}>
                        <ButtonLink
                            href={ctx.url(`/training-plans/${plan.id}`)}
                            variant="secondary"
                        >
                            {plan.name}
                        </ButtonLink>
                    </p>
                ))}
                {!plans.length && <p>No assigned plans yet.</p>}
                <div className="coach-actions">
                    <ButtonLink href={ctx.url("/training-plans")}>
                        View plans
                    </ButtonLink>
                    <ButtonLink
                        href={ctx.url("/progress/exercises")}
                        variant="secondary"
                    >
                        Exercise progress & records
                    </ButtonLink>
                </div>
            </section>
            {recentRecords.length > 0 && (
                <section className="panel coach-card">
                    <h2>Recent personal records</h2>
                    <RecordList records={recentRecords} />
                </section>
            )}
        </>
    );
}
