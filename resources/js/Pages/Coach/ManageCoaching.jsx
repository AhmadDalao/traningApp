import SubscriptionTable from "../../Features/SubscriptionTable";
import { Head } from "@inertiajs/react";
import { useState } from "react";
import { PageHeader, Button, ButtonLink } from "../../Components/UI";
import { PackageForm } from "../../Features/CoachingForms";
import { useTraining, EndRelationship } from "../../Features/CoachingUI";
export default function ManageCoaching({ relationship, subscriptions }) {
    const ctx = useTraining();
    const [creating, setCreating] = useState(false);
    return (
        <>
            <Head title={`Coaching · ${ctx.trainee.name}`} />
            <PageHeader
                title="Manage coaching"
                description="Each program has its own coaching coverage and controls."
            >
                {
                    <ButtonLink href={ctx.url("/setup?assign=1")}>
                        Assign & activate training
                    </ButtonLink>
                }
                <ButtonLink href={ctx.url("")} variant="secondary">
                    Back to trainee
                </ButtonLink>
            </PageHeader>
            {!subscriptions.length && (
                <section className="panel coach-card">
                    <h2>No coaching period yet</h2>
                    <p>
                        Choose training, review its dates, then assign and
                        activate.
                    </p>
                </section>
            )}
            <SubscriptionTable items={subscriptions} />
            <section className="panel coach-card">
                <h2>Coaching Packages</h2>
                <p>Use a reusable package or a custom coaching period.</p>
                <Button variant="secondary" onClick={() => setCreating(true)}>
                    Create Coaching Package
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
            {creating && <PackageForm close={() => setCreating(false)} />}
        </>
    );
}
