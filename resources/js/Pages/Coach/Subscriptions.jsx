import { useState } from "react";
import { Head } from "@inertiajs/react";
import { CreditCard, Package, Plus, Users, Search } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Pagination,
    Field,
    Breadcrumbs,
} from "../../Components/UI";
import { PackageForm } from "../../Features/CoachingForms";
import SubscriptionTable from "../../Features/SubscriptionTable";
import useLiveFilters from "../../Features/useLiveFilters";

export default function Subscriptions({
    packages,
    relationships,
    subscriptions,
    filters,
    counts,
}) {
    const [editing, setEditing] = useState(null);
    const filter = useLiveFilters("/coach/subscriptions", filters, [
        "subscriptions",
        "filters",
        "counts",
    ]);
    return (
        <>
            <Head title="Subscriptions" />
            <Breadcrumbs
                items={[
                    { label: "Coach Overview", href: "/coach" },
                    { label: "Subscriptions" },
                ]}
            />
            <PageHeader
                title="Subscriptions"
                description="Independent coverage for every training program. Pause or end one without affecting the others."
            >
                <Button variant="secondary" onClick={() => setEditing({})}>
                    <Package size={17} />
                    Create package
                </Button>
                <ButtonLink href="/coach/trainees">
                    <Plus size={17} />
                    Assign & activate training
                </ButtonLink>
            </PageHeader>
            <div className="subscription-counts">
                {[
                    [
                        "all",
                        "All",
                        Object.values(counts).reduce(
                            (n, v) => n + Number(v),
                            0,
                        ),
                    ],
                    ...["active", "paused", "upcoming", "expired", "ended"].map(
                        (k) => [
                            k,
                            k[0].toUpperCase() + k.slice(1),
                            counts[k] || 0,
                        ],
                    ),
                ].map(([key, label, count]) => (
                    <button
                        key={key}
                        type="button"
                        className={filter.data.status === key ? "active" : ""}
                        onClick={() => filter.setData("status", key)}
                    >
                        <CreditCard size={16} />
                        {label}
                        <span>{count}</span>
                    </button>
                ))}
            </div>
            <section
                className="panel coach-card live-filter-bar"
                aria-busy={filter.processing}
            >
                <Field label="Search subscriptions">
                    <input
                        placeholder="Name, ID, program or package"
                        value={filter.data.search}
                        onChange={(e) =>
                            filter.setData("search", e.target.value)
                        }
                        maxLength={100}
                    />
                </Field>
                <Field label="Trainee">
                    <select
                        value={filter.data.trainee}
                        onChange={(e) =>
                            filter.setData("trainee", e.target.value)
                        }
                    >
                        <option value="">All trainees</option>
                        {relationships.map((r) => (
                            <option key={r.id} value={r.trainee.id}>
                                {r.trainee.name} · {r.trainee.training_code}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field label="Items per page">
                    <select
                        value={filter.data.per_page}
                        onChange={(e) =>
                            filter.setData("per_page", Number(e.target.value))
                        }
                    >
                        {[10, 25, 50, 100].map((n) => (
                            <option key={n} value={n}>
                                {n}
                            </option>
                        ))}
                    </select>
                </Field>
                <span role="status">
                    {filter.processing
                        ? "Updating…"
                        : `${subscriptions.total} subscriptions found`}
                </span>
                <Button
                    variant="secondary"
                    onClick={() =>
                        filter.setData({
                            search: "",
                            status: "all",
                            trainee: "",
                            per_page: 10,
                        })
                    }
                >
                    Clear filters
                </Button>
            </section>
            <SubscriptionTable items={subscriptions.data} />
            <Pagination links={subscriptions.links} />
            <details className="panel coach-card subscription-packages">
                <summary>
                    <Package size={19} />
                    Coaching packages{" "}
                    <span className="count-bubble">{packages.length}</span>
                </summary>
                <p>
                    Reusable package definitions. Changing a package leaves
                    existing subscriptions unchanged.
                </p>
                <ul>
                    {packages.map((p) => (
                        <li key={p.id}>
                            <div>
                                <strong>{p.name}</strong>
                                <small>
                                    {p.duration_days
                                        ? `${p.duration_days} days`
                                        : "Ongoing"}{" "}
                                    · {p.active ? "Available" : "Archived"}
                                </small>
                                {p.description && <p>{p.description}</p>}
                            </div>
                            <Button
                                variant="secondary"
                                onClick={() => setEditing(p)}
                            >
                                Edit package
                            </Button>
                        </li>
                    ))}
                </ul>
                {!packages.length && (
                    <p>
                        Create your first package, or use custom coverage when
                        assigning training.
                    </p>
                )}
            </details>
            {editing && (
                <PackageForm
                    item={editing.id ? editing : null}
                    close={() => setEditing(null)}
                />
            )}
        </>
    );
}
