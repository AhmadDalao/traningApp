import { Head, Link } from "@inertiajs/react";
import { AlertTriangle, CheckCircle2 } from "lucide-react";
import TrainingThumbnail from "../../Components/TrainingThumbnail";
import { PageHeader, ButtonLink, StatusBadge } from "../../Components/UI";
import { EndRelationship, useTraining } from "../../Features/CoachingUI";
import {
    CurrentTraining,
    WeeklyTraining,
    WorkoutDate,
    TrainingRow,
} from "../../Features/CoachWorkspace";
export default function Trainee({
    state,
    currentTraining,
    thisWeek = [],
    relationship,
    profile,
    recentActivity = [],
    nextSession,
    plans = [],
}) {
    const ctx = useTraining(),
        needsAttention = ["attention", "paused", "setup"].includes(
            state?.display_state,
        );
    return (
        <div className="trainee-overview">
            <Head title={ctx.trainee.name} />
            <PageHeader
                title="Training overview"
                description="Current training, this week, and the next useful step."
            >
                {state?.subscription_action && (
                    <ButtonLink href={state.subscription_action.url}>
                        {state.subscription_action.label}
                    </ButtonLink>
                )}
                <ButtonLink href={ctx.url("/setup?assign=1")}>
                    Assign & activate training
                </ButtonLink>
                <ButtonLink
                    variant="secondary"
                    href={`/coach/subscriptions?trainee=${ctx.trainee.id}`}
                >
                    Manage subscriptions
                </ButtonLink>
                <details className="trainee-more">
                    <summary>More</summary>
                    <div>
                        <Link href={ctx.url("/coaching")}>Manage coaching</Link>
                        {ctx.canManage && currentTraining && (
                            <Link href={ctx.url("/schedule-entries/create")}>
                                Add Workout
                            </Link>
                        )}
                        <hr />
                        <EndRelationship
                            relationship={relationship}
                            label="Disconnect trainee"
                        />
                    </div>
                </details>
            </PageHeader>
            <div className="coach-trainee-columns">
                <CurrentTraining
                    training={currentTraining}
                    nextSession={nextSession}
                />
                <WeeklyTraining entries={thisWeek} />
            </div>
            {plans.length > 1 && (
                <section className="assigned-programs">
                    <div className="coach-card-title">
                        <h2>
                            All assigned training{" "}
                            <span className="count-bubble">{plans.length}</span>
                        </h2>
                        <Link href={ctx.url("/training-plans")}>
                            View all training →
                        </Link>
                    </div>
                    <div className="assigned-program-list">
                        {plans.map((plan) => (
                            <TrainingRow
                                key={plan.id}
                                plan={{
                                    ...plan,
                                    subscription: plan.subscription,
                                }}
                            >
                                <ButtonLink
                                    variant="secondary"
                                    href={ctx.url(`/training-plans/${plan.id}`)}
                                >
                                    View training
                                </ButtonLink>
                                <ButtonLink
                                    variant="secondary"
                                    href={`/coach/subscriptions?trainee=${ctx.trainee.id}&search=${encodeURIComponent(plan.name)}`}
                                >
                                    Manage subscription
                                </ButtonLink>
                            </TrainingRow>
                        ))}
                    </div>
                </section>
            )}
            <div className="coach-overview-columns">
                <section className="panel coach-card">
                    <h2>
                        {needsAttention ? (
                            <AlertTriangle size={19} aria-hidden="true" />
                        ) : (
                            <CheckCircle2 size={19} aria-hidden="true" />
                        )}
                        {needsAttention ? "Needs attention" : "On track"}
                    </h2>
                    <p>{state?.reason || "Training is on track."}</p>
                    {needsAttention && state?.action && (
                        <ButtonLink variant="secondary" href={state.action.url}>
                            {state.action.label}
                        </ButtonLink>
                    )}
                    {profile?.training_goals?.length > 0 && (
                        <details className="coach-secondary-details">
                            <summary>Training goals</summary>
                            <div className="recommendation-tags">
                                {profile.training_goals.map((g) => (
                                    <span className="badge training" key={g}>
                                        {g.replaceAll("_", " ")}
                                    </span>
                                ))}
                            </div>
                        </details>
                    )}
                </section>
                <section className="panel coach-card">
                    <div className="coach-card-title">
                        <h2>Recent activity</h2>
                        <Link href={ctx.url("/training-history")}>
                            View history →
                        </Link>
                    </div>
                    {recentActivity.map((s) => (
                        <article className="coach-session" key={s.id}>
                            <TrainingThumbnail item={s} />
                            <div className="coach-session-copy">
                                <Link
                                    href={ctx.url(`/training-history/${s.id}`)}
                                >
                                    <strong>{s.title}</strong>
                                </Link>
                                <small>
                                    <WorkoutDate entry={s} />
                                </small>
                            </div>
                            <StatusBadge status={s.status} />
                        </article>
                    ))}
                    {!recentActivity.length && (
                        <p>Completed and skipped workouts will appear here.</p>
                    )}
                </section>
            </div>
        </div>
    );
}
