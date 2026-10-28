import { RecordList } from "../../Features/ExerciseRecords";
import { Head } from "@inertiajs/react";
import { PageHeader, ButtonLink, DateLabel } from "../../Components/UI";
import {
    CoachStatus,
    EndRelationship,
    useTraining,
} from "../../Features/CoachingUI";
export default function Trainee({
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
                description="The trainee owns their workouts and records."
            >
                <ButtonLink href="/coach/subscriptions" variant="secondary">
                    Manage subscription
                </ButtonLink>
            </PageHeader>
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
                    <span>Subscription</span>
                    <strong className="coach-small-value">
                        {subscription?.package_name || "None yet"}
                    </strong>
                    <CoachStatus status={subscription?.effective_status} />
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
                            <p>No session yet.</p>
                        )}
                    </section>
                ))}
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
            <EndRelationship
                relationship={relationship}
                label="End coaching relationship"
            />
        </>
    );
}
