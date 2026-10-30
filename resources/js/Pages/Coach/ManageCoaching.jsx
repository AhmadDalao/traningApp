import { Head } from "@inertiajs/react";
import { useState } from "react";
import { PageHeader, Button, ButtonLink } from "../../Components/UI";
import {
    PackageForm,
    StartSubscription,
    Subscription,
} from "../../Features/CoachingForms";
import { useTraining, EndRelationship } from "../../Features/CoachingUI";
export default function ManageCoaching({
    relationship,
    subscriptions,
    packages,
}) {
    const ctx = useTraining();
    const [starting, setStarting] = useState(false),
        [creating, setCreating] = useState(false);
    const existing = subscriptions.some((s) =>
        ["active", "paused"].includes(s.status),
    );
    return (
        <>
            <Head title={`Coaching · ${ctx.trainee.name}`} />
            <PageHeader
                title="Manage coaching"
                description="Coaching periods control access to managing this trainee’s training."
            >
                {!existing && (
                    <Button onClick={() => setStarting(true)}>
                        Start coaching
                    </Button>
                )}
                <ButtonLink href={ctx.url("")} variant="secondary">
                    Back to trainee
                </ButtonLink>
            </PageHeader>
            {!subscriptions.length && (
                <section className="panel coach-card">
                    <h2>No coaching period yet</h2>
                    <p>Choose a coaching plan and dates to get started.</p>
                </section>
            )}
            <div className="coach-grid">
                {subscriptions.map((s) => (
                    <Subscription key={s.id} item={s} />
                ))}
            </div>
            <section className="panel coach-card">
                <h2>Coaching plans</h2>
                <p>Use a reusable package or a custom coaching period.</p>
                <Button variant="secondary" onClick={() => setCreating(true)}>
                    Create coaching plan
                </Button>
            </section>
            <details className="panel coach-card">
                <summary>End relationship</summary>
                <p>
                    This ends access. The trainee keeps all training and
                    history.
                </p>
                <EndRelationship
                    relationship={relationship}
                    label="Disconnect trainee"
                />
            </details>
            {starting && (
                <StartSubscription
                    packages={packages}
                    relationship={relationship}
                    close={() => setStarting(false)}
                />
            )}
            {creating && <PackageForm close={() => setCreating(false)} />}
        </>
    );
}
