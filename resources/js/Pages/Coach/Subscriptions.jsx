import { useState } from "react";
import { Head } from "@inertiajs/react";
import { CreditCard, Package, Plus } from "lucide-react";
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
    const [tab, setTab] = useState("subscriptions");
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
                eyebrow="COACHING COVERAGE"
                title="Subscriptions"
                description="One clear view of who is covered, what they train, and when it starts and ends."
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
            <div
                className="directory-tabs"
                role="tablist"
                aria-label="Subscription directory"
            >
                {[
                    ["subscriptions", "Subscriptions"],
                    ["packages", "Coaching packages"],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        role="tab"
                        tabIndex={tab === key ? 0 : -1}
                        onKeyDown={(event) => {
                            if (
                                [
                                    "ArrowLeft",
                                    "ArrowRight",
                                    "Home",
                                    "End",
                                ].includes(event.key)
                            ) {
                                event.preventDefault();
                                const next =
                                    event.key === "Home"
                                        ? "subscriptions"
                                        : event.key === "End"
                                          ? "packages"
                                          : key === "subscriptions"
                                            ? "packages"
                                            : "subscriptions";
                                setTab(next);
                                document.getElementById(`tab-${next}`)?.focus();
                            }
                        }}
                        id={`tab-${key}`}
                        aria-controls={`panel-${key}`}
                        aria-selected={tab === key}
                        onClick={() => setTab(key)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <div
                id="panel-subscriptions"
                role="tabpanel"
                aria-labelledby="tab-subscriptions"
                hidden={tab !== "subscriptions"}
            >
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
                        ...[
                            "active",
                            "paused",
                            "upcoming",
                            "expired",
                            "ended",
                        ].map((k) => [
                            k,
                            k[0].toUpperCase() + k.slice(1),
                            counts[k] || 0,
                        ]),
                    ].map(([key, label, count]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={filter.data.status === key}
                            className={
                                filter.data.status === key ? "active" : ""
                            }
                            onClick={() => filter.setData("status", key)}
                        >
                            <CreditCard size={16} />
                            {label}
                            <span>{count}</span>
                        </button>
                    ))}
                </div>
                <section
                    className="panel coach-card subscription-filters"
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
                    <Field label="Coverage">
                        <select
                            value={filter.data.coverage}
                            onChange={(e) =>
                                filter.setData("coverage", e.target.value)
                            }
                        >
                            <option value="all">All coverage</option>
                            <option value="ending_7">
                                Active · ending within 7 days
                            </option>
                            <option value="ending_30">
                                Active · ending within 30 days
                            </option>
                            <option value="ongoing">
                                Ongoing · no end date
                            </option>
                        </select>
                    </Field>
                    <Field label="Sort by">
                        <select
                            value={filter.data.sort}
                            onChange={(e) =>
                                filter.setData("sort", e.target.value)
                            }
                        >
                            <option value="newest">Newest first</option>
                            <option value="ending_first">
                                End date · earliest first
                            </option>
                            <option value="starting_first">
                                Start date · earliest first
                            </option>
                        </select>
                    </Field>
                    <Field label="Items per page">
                        <select
                            value={filter.data.per_page}
                            onChange={(e) =>
                                filter.setData(
                                    "per_page",
                                    Number(e.target.value),
                                )
                            }
                        >
                            {[10, 25, 50, 100].map((n) => (
                                <option key={n} value={n}>
                                    {n}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <div className="subscription-filter-actions">
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
                                    coverage: "all",
                                    sort: "newest",
                                })
                            }
                        >
                            Clear filters
                        </Button>
                    </div>
                </section>
                <SubscriptionTable items={subscriptions.data} />
                <div className="subscription-results-footer">
                    <p>
                        Showing {subscriptions.from || 0}–
                        {subscriptions.to || 0} of {subscriptions.total}{" "}
                        subscriptions
                    </p>
                    <Pagination links={subscriptions.links} />
                </div>
            </div>
            <section
                id="panel-packages"
                role="tabpanel"
                aria-labelledby="tab-packages"
                hidden={tab !== "packages"}
                className="panel coach-card subscription-packages"
            >
                <h2>
                    <Package size={19} />
                    Coaching packages{" "}
                    <span className="count-bubble">{packages.length}</span>
                </h2>
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
            </section>
            {editing && (
                <PackageForm
                    item={editing.id ? editing : null}
                    close={() => setEditing(null)}
                />
            )}
        </>
    );
}
