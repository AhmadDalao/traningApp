import { useRef, useState } from "react";
import { Head, useRemember } from "@inertiajs/react";
import { ArrowRight, Check, SlidersHorizontal, Search } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    Modal,
    EmptyState,
} from "../../Components/UI";
import { emptyPlanFilters, filterPlans } from "../../Features/planDiscovery";

function PlanCard({ plan, recommended = false }) {
    return (
        <article className="panel discovery-card">
            <div className="recommendation-tags">
                <span
                    className={`badge ${plan.level === "advanced" ? "planned" : "training"}`}
                >
                    {plan.level_label}
                </span>
                <span className="discovery-focus">{plan.focus_label}</span>
            </div>
            <h3>{plan.name}</h3>
            <p className="discovery-meta">
                {plan.days_per_week} days / week · About {plan.duration} min ·{" "}
                {plan.equipment_label}
            </p>
            <p>{plan.description}</p>
            {recommended && (
                <div className="discovery-reasons">
                    <h4>Why this fits</h4>
                    <ul>
                        {plan.reasons.map((reason) => (
                            <li key={reason}>
                                <Check size={14} aria-hidden="true" />
                                <span>{reason}</span>
                            </li>
                        ))}
                    </ul>
                </div>
            )}
            <ButtonLink
                href={`/recommendations/${plan.key}`}
                variant="secondary"
                aria-label={`Preview ${plan.name}`}
            >
                Preview plan <ArrowRight size={16} />
            </ButtonLink>
        </article>
    );
}

export default function Index({
    recommendations,
    plans,
    options,
    hasPreferences,
}) {
    const [filters, setFilters] = useRemember(
        emptyPlanFilters,
        "discovery-filters",
    );
    const [search, setSearch] = useRemember("", "discovery-search");
    const [draft, setDraft] = useState(null);
    const filterButton = useRef(null);
    function closeFilters() {
        setDraft(null);
        requestAnimationFrame(() => filterButton.current?.focus());
    }
    const filtered = filterPlans(plans, filters, search);
    const selected = [
        options.levels[filters.level],
        options.goals[filters.goal],
        filters.days && `${filters.days} days / week`,
        options.equipment[filters.equipment],
    ].filter(Boolean);
    const reset = () => {
        setFilters(emptyPlanFilters);
        setSearch("");
    };
    return (
        <div className="discovery-page">
            <Head title="Find a training plan" />
            <PageHeader
                eyebrow="FIND YOUR NEXT ROUTINE"
                title="Find a training plan"
                description="Explore a starting point, make it yours, and schedule it when you’re ready."
            >
                <ButtonLink href="/training-preferences" variant="secondary">
                    Update preferences
                </ButtonLink>
                <ButtonLink href="/training-plans" variant="secondary">
                    My plans
                </ButtonLink>
            </PageHeader>
            <section
                aria-labelledby="recommended-title"
                className="discovery-section"
            >
                <div className="discovery-section-heading">
                    <h2 id="recommended-title">
                        {hasPreferences
                            ? "Recommended for you"
                            : "Good places to start"}
                    </h2>
                    <p>
                        {hasPreferences
                            ? "Your ordered goals, experience, days, time, and equipment help rank these plans."
                            : "Browse freely, or add preferences for more tailored suggestions."}
                    </p>
                </div>
                <div className="discovery-grid">
                    {recommendations.map((plan) => (
                        <PlanCard key={plan.key} plan={plan} recommended />
                    ))}
                </div>
            </section>
            <section
                aria-labelledby="all-plans-title"
                className="discovery-section"
            >
                <div className="discovery-section-heading">
                    <h2 id="all-plans-title">All plans</h2>
                    <p>
                        Every level is available. Advanced plans involve more
                        volume or more demanding movements; choose the
                        commitment that suits you.
                    </p>
                </div>
                <div className="discovery-toolbar">
                    <label className="discovery-search">
                        <Search size={18} aria-hidden="true" />
                        <span className="sr-only">Search plans</span>
                        <input
                            type="search"
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                            placeholder="Search plans, goals, or split…"
                        />
                    </label>
                    <Button
                        variant="secondary"
                        onClick={(event) => {
                            filterButton.current = event.currentTarget;
                            event.currentTarget.focus();
                            setDraft({ ...filters });
                        }}
                    >
                        <SlidersHorizontal size={17} />
                        Filters{selected.length ? ` (${selected.length})` : ""}
                    </Button>
                </div>
                <div className="discovery-results-summary">
                    <p role="status" aria-live="polite">
                        {filtered.length} of {plans.length} plans
                        {selected.length ? ` · ${selected.join(" · ")}` : ""}
                    </p>
                    {(selected.length > 0 || search) && (
                        <Button variant="secondary" onClick={reset}>
                            Clear filters
                        </Button>
                    )}
                </div>
                {filtered.length ? (
                    <div className="discovery-grid">
                        {filtered.map((plan) => (
                            <PlanCard key={plan.key} plan={plan} />
                        ))}
                    </div>
                ) : (
                    <section className="panel">
                        <EmptyState
                            title="No plans match these filters"
                            description="Try fewer filters or a different search."
                        >
                            <Button variant="secondary" onClick={reset}>
                                Show all plans
                            </Button>
                        </EmptyState>
                    </section>
                )}
            </section>
            {draft && (
                <Modal
                    title="Filter plans"
                    description="Combine filters to narrow the full library."
                    onClose={closeFilters}
                >
                    <form
                        className="discovery-filters"
                        onSubmit={(event) => {
                            event.preventDefault();
                            setFilters(draft);
                            closeFilters();
                        }}
                    >
                        <Field label="Experience level">
                            <select
                                value={draft.level}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        level: event.target.value,
                                    })
                                }
                            >
                                <option value="">All levels</option>
                                {Object.entries(options.levels).map(
                                    ([key, name]) => (
                                        <option key={key} value={key}>
                                            {name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Field label="Training goal">
                            <select
                                value={draft.goal}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        goal: event.target.value,
                                    })
                                }
                            >
                                <option value="">All goals</option>
                                {Object.entries(options.goals).map(
                                    ([key, name]) => (
                                        <option key={key} value={key}>
                                            {name}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <Field label="Days per week">
                            <select
                                value={draft.days}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        days: event.target.value,
                                    })
                                }
                            >
                                <option value="">Any number of days</option>
                                {[2, 3, 4, 5, 6].map((days) => (
                                    <option key={days} value={days}>
                                        {days} days
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Field label="Equipment used">
                            <select
                                value={draft.equipment}
                                onChange={(event) =>
                                    setDraft({
                                        ...draft,
                                        equipment: event.target.value,
                                    })
                                }
                            >
                                <option value="">Any equipment</option>
                                {["bodyweight", "dumbbells", "full_gym"].map(
                                    (key) => (
                                        <option key={key} value={key}>
                                            {options.equipment[key]}
                                        </option>
                                    ),
                                )}
                            </select>
                        </Field>
                        <p className="field-hint">
                            Equipment filters show what a plan uses. Home gyms
                            vary; choose dumbbells or bodyweight to explore
                            those routines.
                        </p>
                        <div className="form-actions">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() =>
                                    setDraft({ ...emptyPlanFilters })
                                }
                            >
                                Clear
                            </Button>
                            <Button>
                                Show {filterPlans(plans, draft, search).length}{" "}
                                plans
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </div>
    );
}
