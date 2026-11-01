import { useTraining } from "../../Features/CoachingUI";
import { useEffect, useRef, useState } from "react";
import { Head, router, usePage } from "@inertiajs/react";
import {
    ArrowRight,
    Check,
    SlidersHorizontal,
    Search,
    CalendarDays,
    Clock,
    Dumbbell,
    Sparkles,
} from "lucide-react";
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
    const { url } = useTraining();
    return (
        <article className="panel discovery-card">
            <div className="recommendation-tags">
                {recommended && (
                    <span className="badge completed">
                        <Sparkles size={13} aria-hidden="true" /> Recommended
                    </span>
                )}
                <span
                    className={`badge ${plan.level === "advanced" ? "planned" : "training"}`}
                >
                    {plan.level_label}
                </span>
                <span className="discovery-focus">{plan.focus_label}</span>
            </div>
            <h3>{plan.name}</h3>
            {plan.structure_type === "program" && (
                <span className="badge training">
                    Program · {plan.duration_weeks} weeks
                </span>
            )}
            <div className="plan-metadata">
                <span>
                    <CalendarDays size={14} aria-hidden="true" />
                    {plan.days_per_week} days / week
                </span>
                <span>
                    <Clock size={14} aria-hidden="true" />
                    About {plan.duration} min
                </span>
                <span>
                    <Dumbbell size={14} aria-hidden="true" />
                    {plan.equipment_label}
                </span>
            </div>
            <p>{plan.description}</p>
            {recommended && (
                <ul
                    className="recommendation-reasons"
                    aria-label="Why this fits"
                >
                    {plan.reasons.slice(0, 2).map((reason) => (
                        <li key={reason}>
                            <Check size={14} aria-hidden="true" />
                            {reason}
                        </li>
                    ))}
                </ul>
            )}
            <ButtonLink
                href={url(`/recommendations/${plan.key}`)}
                variant="secondary"
                aria-label={`Preview ${plan.name}`}
            >
                View {plan.structure_type === "program" ? "program" : "plan"}
                <ArrowRight size={16} aria-hidden="true" />
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
    const { url, templates } = useTraining(),
        page = usePage();
    function read() {
        const q = new URLSearchParams(page.url.split("?")[1] || "");
        return {
            filters: Object.fromEntries(
                Object.keys(emptyPlanFilters).map((k) => [k, q.get(k) || ""]),
            ),
            search: q.get("search") || "",
        };
    }
    const initial = read(),
        [filters, setFilters] = useState(initial.filters),
        [search, setSearch] = useState(initial.search),
        [open, setOpen] = useState(false);
    const timer = useRef(null),
        filterButton = useRef(null);
    useEffect(() => {
        const next = read();
        setFilters(next.filters);
        setSearch(next.search);
    }, [page.url]);
    useEffect(() => () => clearTimeout(timer.current), []);
    function sync(next, text, delay = 0) {
        clearTimeout(timer.current);
        const visit = () => {
            const q = new URLSearchParams(
                Object.entries({ ...next, search: text }).filter(([, v]) => v),
            );
            router.replace({
                url: url("/recommendations") + (q.size ? `?${q}` : ""),
                preserveState: true,
                preserveScroll: true,
            });
        };
        if (delay) timer.current = setTimeout(visit, delay);
        else visit();
    }
    function change(key, value) {
        const next = { ...filters, [key]: value };
        setFilters(next);
        sync(next, search);
    }
    function reset() {
        setFilters(emptyPlanFilters);
        setSearch("");
        sync(emptyPlanFilters, "");
    }
    function close() {
        setOpen(false);
        requestAnimationFrame(() => filterButton.current?.focus());
    }
    const filtered = filterPlans(plans, filters, search),
        selected = [
            filters.type &&
                (filters.type === "program" ? "Programs" : "Simple plans"),
            options.levels[filters.level],
            options.goals[filters.goal],
            filters.days && `${filters.days} days / week`,
            options.equipment[filters.equipment],
            filters.duration && `Up to ${filters.duration} minutes`,
        ].filter(Boolean);
    const fields = (
        <>
            <Field label="Type">
                <select
                    value={filters.type}
                    onChange={(e) => change("type", e.target.value)}
                >
                    <option value="">All training</option>
                    <option value="simple">Plans</option>
                    <option value="program">Programs</option>
                </select>
            </Field>
            <Field label="Experience level">
                <select
                    value={filters.level}
                    onChange={(e) => change("level", e.target.value)}
                >
                    <option value="">All levels</option>
                    {Object.entries(options.levels).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Training goal">
                <select
                    value={filters.goal}
                    onChange={(e) => change("goal", e.target.value)}
                >
                    <option value="">All goals</option>
                    {Object.entries(options.goals).map(([k, v]) => (
                        <option key={k} value={k}>
                            {v}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Days per week">
                <select
                    value={filters.days}
                    onChange={(e) => change("days", e.target.value)}
                >
                    <option value="">Any days</option>
                    {[2, 3, 4, 5, 6].map((k) => (
                        <option key={k} value={k}>
                            {k} days
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Equipment used">
                <select
                    value={filters.equipment}
                    onChange={(e) => change("equipment", e.target.value)}
                >
                    <option value="">Any equipment</option>
                    {["bodyweight", "dumbbells", "full_gym"].map((k) => (
                        <option key={k} value={k}>
                            {options.equipment[k]}
                        </option>
                    ))}
                </select>
            </Field>
            <Field label="Session duration">
                <select
                    value={filters.duration}
                    onChange={(e) => change("duration", e.target.value)}
                >
                    <option value="">Any duration</option>
                    {[30, 45, 60, 90].map((k) => (
                        <option key={k} value={k}>
                            Up to {k} min
                        </option>
                    ))}
                </select>
            </Field>
        </>
    );
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
                <ButtonLink href={url("/training-plans")} variant="secondary">
                    {templates ? "My templates" : "My plans"}
                </ButtonLink>
            </PageHeader>
            <section
                className="panel discovery-filter-panel"
                aria-label="Search and filter training plans"
            >
                <div className="discovery-toolbar">
                    <label className="discovery-search">
                        <Search size={18} aria-hidden="true" />
                        <span className="sr-only">Search plans</span>
                        <input
                            type="search"
                            value={search}
                            onChange={(e) => {
                                setSearch(e.target.value);
                                sync(filters, e.target.value, 300);
                            }}
                            placeholder="Search plans, goals, or split…"
                            maxLength={150}
                        />
                    </label>
                    <Button
                        className="mobile-discovery-filter"
                        variant="secondary"
                        onClick={(e) => {
                            filterButton.current = e.currentTarget;
                            setOpen(true);
                        }}
                    >
                        <SlidersHorizontal size={17} aria-hidden="true" />
                        Filters{selected.length ? ` (${selected.length})` : ""}
                    </Button>
                </div>
                <div className="desktop-discovery-filters">{fields}</div>
                <div className="discovery-results-summary">
                    <p role="status" aria-live="polite">
                        {filtered.length} of {plans.length} plans
                        {selected.length ? ` · ${selected.join(" · ")}` : ""}
                    </p>
                    {(selected.length > 0 || search) && (
                        <Button variant="secondary" onClick={reset}>
                            Clear all
                        </Button>
                    )}
                </div>
                <p className="field-hint">
                    Search and filters apply to All training plans.
                    Recommendations stay personalized.
                </p>
            </section>
            <section
                aria-labelledby="recommended-title"
                className="discovery-section"
            >
                <div className="discovery-section-heading">
                    <h2 id="recommended-title">
                        <Sparkles size={20} aria-hidden="true" /> Recommended
                        for you
                    </h2>
                    <p>
                        {hasPreferences
                            ? "Based on your goals, experience and availability."
                            : "Starting points to explore. Add preferences for more tailored suggestions."}
                    </p>
                </div>
                <div className="discovery-grid">
                    {recommendations.slice(0, 3).map((plan) => (
                        <PlanCard key={plan.key} plan={plan} recommended />
                    ))}
                </div>
            </section>
            <section
                aria-labelledby="all-plans-title"
                className="discovery-section"
            >
                <div className="discovery-section-heading">
                    <h2 id="all-plans-title">
                        All training plans{" "}
                        <span className="count-bubble">{filtered.length}</span>
                    </h2>
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
                            title="No training plans match your search"
                            description="Try fewer filters or a different search."
                        >
                            <Button variant="secondary" onClick={reset}>
                                Clear filters
                            </Button>
                        </EmptyState>
                    </section>
                )}
            </section>
            {open && (
                <Modal
                    title="Filter plans"
                    description="Results update as you choose. Done closes this panel."
                    onClose={close}
                >
                    <div className="discovery-filters">
                        {fields}
                        <p role="status">{filtered.length} plans found</p>
                        <div className="form-actions">
                            <Button variant="secondary" onClick={reset}>
                                Clear all
                            </Button>
                            <Button onClick={close}>Done</Button>
                        </div>
                    </div>
                </Modal>
            )}
        </div>
    );
}
