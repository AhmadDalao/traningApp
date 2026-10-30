import { useState } from "react";
import { Head } from "@inertiajs/react";
import { PageHeader, Button, Pagination } from "../../Components/UI";
import {
    PackageForm,
    StartSubscription,
    Subscription,
} from "../../Features/CoachingForms";
export default function Subscriptions({
    packages,
    relationships,
    subscriptions,
}) {
    const [editing, setEditing] = useState(false),
        [starting, setStarting] = useState(false);
    return (
        <>
            <Head title="Coaching subscriptions" />
            <PageHeader
                title="Subscriptions"
                description="Manage coaching service periods. No payments or billing."
            >
                <Button variant="secondary" onClick={() => setEditing({})}>
                    Create package
                </Button>
                <Button onClick={() => setStarting(true)}>
                    Start subscription
                </Button>
            </PageHeader>
            <section className="panel coach-card">
                <h2>Your service packages</h2>
                <div className="coach-package-list">
                    {packages.map((p) => (
                        <div key={p.id}>
                            <strong>{p.name}</strong>
                            <p>
                                {p.duration_days
                                    ? `${p.duration_days} days`
                                    : "Custom duration"}{" "}
                                · {p.active ? "Available" : "Archived"}
                            </p>
                            <Button
                                variant="secondary"
                                onClick={() => setEditing(p)}
                            >
                                Edit package
                            </Button>
                        </div>
                    ))}
                </div>
                {!packages.length && (
                    <p>
                        Create a reusable package, or start a custom
                        subscription.
                    </p>
                )}
            </section>
            <div className="coach-grid">
                {subscriptions.data.map((item) => (
                    <Subscription key={item.id} item={item} />
                ))}
            </div>
            {!subscriptions.data.length && (
                <p className="panel coach-card">
                    No subscriptions yet. Connect with a trainee first.
                </p>
            )}
            <Pagination links={subscriptions.links} />
            {editing && (
                <PackageForm
                    item={editing.id ? editing : null}
                    close={() => setEditing(false)}
                />
            )}
            {starting && (
                <StartSubscription
                    packages={packages}
                    relationships={relationships}
                    close={() => setStarting(false)}
                />
            )}
        </>
    );
}
