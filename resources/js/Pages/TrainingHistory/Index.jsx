import { calorieDisplay } from "../../Features/calories";
import { useEffect, useState } from "react";
import { Head, Link, router, usePage } from "@inertiajs/react";
import {
    CheckCircle2,
    MinusCircle,
    Coffee,
    CalendarDays,
    ArrowRight,
} from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    StatusBadge,
    TypeBadge,
    EmptyState,
    Pagination,
    TimeLabel,
} from "../../Components/UI";

export default function Index({ entries, summary, filters, plans }) {
    const { errors } = usePage().props;
    const [draft, setDraft] = useState(filters);
    const [busy, setBusy] = useState(false);
    useEffect(() => {
        setDraft(filters);
    }, [filters]);
    function change(name, value) {
        setDraft((current) => ({ ...current, [name]: value }));
    }
    const cards = [
        {
            label: "Completed",
            value: summary.completed,
            icon: CheckCircle2,
            color: "green",
            note: "training sessions",
        },
        {
            label: "Skipped",
            value: summary.skipped,
            icon: MinusCircle,
            color: "orange",
            note: "training sessions",
        },
        {
            label: "Rest Days",
            value: summary.rest,
            icon: Coffee,
            color: "purple",
            note: "days of recovery",
        },
        {
            label: "Total Sessions",
            value: summary.total,
            icon: CalendarDays,
            color: "blue",
            note: "past scheduled sessions",
        },
    ];
    function apply(next) {
        setBusy(true);
        router.get(
            "/training-history",
            Object.fromEntries(
                Object.entries(next).filter(
                    ([key, value]) =>
                        value !== "" &&
                        (next.period === "custom" ||
                            !["from", "to"].includes(key)),
                ),
            ),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                onFinish: () => setBusy(false),
            },
        );
    }
    return (
        <>
            <Head title="Training History" />
            <PageHeader
                eyebrow="LOOK BACK AT YOUR TRAINING"
                title="Training History"
                description="Your past sessions, saved one workout at a time."
            >
                <ButtonLink variant="secondary" href="/calendar">
                    <CalendarDays size={16} />
                    Calendar
                </ButtonLink>
            </PageHeader>
            <div className="stats-grid">
                {cards.map(({ label, value, note, icon: Icon, color }) => (
                    <div className="stat-card" key={label}>
                        <div className="stat-top">
                            <span>{label}</span>
                            <div className={`stat-icon ${color}`}>
                                <Icon size={19} />
                            </div>
                        </div>
                        <strong>{value}</strong>
                        <p>{note}</p>
                    </div>
                ))}
            </div>
            <section className="panel history-panel" aria-busy={busy}>
                <div className="panel-heading">
                    <div>
                        <h2>
                            Your sessions{" "}
                            <span className="count-bubble">
                                {entries.total}
                            </span>
                        </h2>
                        <p>
                            Date presets filter immediately. For custom dates,
                            choose From and To, then apply filters.
                        </p>
                    </div>
                </div>
                <form
                    className="history-filters"
                    onSubmit={(event) => {
                        event.preventDefault();
                        apply(draft);
                    }}
                >
                    <Field label="Period" error={errors.period}>
                        <select
                            value={draft.period}
                            disabled={busy}
                            onChange={(event) => {
                                const next = {
                                    ...draft,
                                    period: event.target.value,
                                };
                                setDraft(next);
                                if (next.period !== "custom") apply(next);
                            }}
                        >
                            <option value="all">All time</option>
                            <option value="today">Today</option>
                            <option value="yesterday">Yesterday</option>
                            <option value="last_7_days">Last 7 days</option>
                            <option value="last_30_days">Last 30 days</option>
                            <option value="this_month">This month</option>
                            <option value="custom">Custom</option>
                        </select>
                    </Field>
                    {draft.period === "custom" && (
                        <>
                            <Field label="From" error={errors.from}>
                                <input
                                    type="date"
                                    required
                                    value={draft.from}
                                    max={draft.to || undefined}
                                    onInput={(event) =>
                                        change("from", event.target.value)
                                    }
                                />
                            </Field>
                            <Field label="To" error={errors.to}>
                                <input
                                    type="date"
                                    required
                                    value={draft.to}
                                    min={draft.from || undefined}
                                    onInput={(event) =>
                                        change("to", event.target.value)
                                    }
                                />
                            </Field>
                        </>
                    )}
                    <Field label="Training plan" error={errors.plan}>
                        <select
                            value={draft.plan}
                            onChange={(event) =>
                                change("plan", event.target.value)
                            }
                        >
                            <option value="">All training plans</option>
                            {plans.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                    <Field label="Session type" error={errors.type}>
                        <select
                            value={draft.type}
                            onChange={(event) =>
                                change("type", event.target.value)
                            }
                        >
                            <option value="all">All session types</option>
                            <option value="training">Training days</option>
                            <option value="rest">Rest days</option>
                        </select>
                    </Field>
                    <div className="history-filter-actions">
                        <Button disabled={busy}>Apply filters</Button>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={busy}
                            onClick={() => {
                                apply({ per_page: filters.per_page });
                            }}
                        >
                            Reset
                        </Button>
                    </div>
                </form>
                <div className="history-toolbar">
                    <div
                        className="calendar-filters"
                        role="group"
                        aria-label="History status filters"
                    >
                        {[
                            ["all", "All"],
                            ["completed", "Completed"],
                            ["skipped", "Skipped"],
                            ["planned", "Planned"],
                        ].map(([value, label]) => (
                            <button
                                type="button"
                                className={`filter-chip ${filters.status === value ? "active" : ""}`}
                                aria-pressed={filters.status === value}
                                disabled={busy}
                                key={value}
                                onClick={() =>
                                    apply({ ...filters, status: value })
                                }
                            >
                                {value !== "all" && (
                                    <span
                                        className={`filter-dot dot-${value}`}
                                    />
                                )}
                                {label}
                            </button>
                        ))}
                    </div>
                    <Field label="Items per page">
                        <select
                            value={filters.per_page}
                            disabled={busy}
                            onChange={(event) =>
                                apply({
                                    ...filters,
                                    per_page: event.target.value,
                                })
                            }
                        >
                            {["10", "25", "50", "100", "all"].map((value) => (
                                <option key={value} value={value}>
                                    {value === "all" ? "All" : value}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
                {entries.data.length ? (
                    <div className="table-scroll">
                        <table className="history-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Title</th>
                                    <th>Type</th>
                                    <th>Exercises</th>
                                    <th>Status</th>
                                    <th>Duration</th>
                                    <th>Calories</th>
                                    <th>Weight Summary</th>
                                    <th>
                                        <span className="sr-only">Details</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {entries.data.map((entry) => (
                                    <tr key={entry.id}>
                                        <td data-label="Date">
                                            <time
                                                dateTime={entry.scheduled_date}
                                            >
                                                {new Date(
                                                    `${entry.scheduled_date}T12:00:00`,
                                                ).toLocaleDateString("en-GB", {
                                                    day: "numeric",
                                                    month: "short",
                                                    year: "numeric",
                                                })}
                                            </time>
                                            {entry.scheduled_time && (
                                                <small>
                                                    <TimeLabel
                                                        time={
                                                            entry.scheduled_time
                                                        }
                                                    />
                                                </small>
                                            )}
                                        </td>
                                        <td
                                            className="history-title-cell"
                                            data-label="Title"
                                        >
                                            <strong>{entry.title}</strong>
                                            {entry.focus_area && (
                                                <small>
                                                    {entry.focus_area}
                                                </small>
                                            )}
                                        </td>
                                        <td data-label="Type">
                                            <TypeBadge
                                                type={entry.entry_type}
                                            />
                                        </td>
                                        <td data-label="Exercises">
                                            {entry.entry_type === "rest"
                                                ? "—"
                                                : entry.status ===
                                                        "completed" &&
                                                    !entry.snapshot_available
                                                  ? "Not recorded"
                                                  : entry.exercises_count}
                                        </td>
                                        <td data-label="Status">
                                            {entry.entry_type === "rest" ? (
                                                <span className="badge rest">
                                                    Rest Day
                                                </span>
                                            ) : (
                                                <StatusBadge
                                                    status={entry.status}
                                                />
                                            )}
                                        </td>
                                        <td data-label="Duration">
                                            {entry.duration_minutes != null
                                                ? `${entry.duration_minutes} min`
                                                : "—"}
                                        </td>
                                        <td data-label="Calories">
                                            {calorieDisplay(entry).value}
                                        </td>
                                        <td data-label="Weight Summary">
                                            {entry.weights_logged
                                                ? `${entry.weights_logged}/${entry.exercises_count} weights logged`
                                                : "—"}
                                        </td>
                                        <td className="history-link-cell">
                                            <Link
                                                href={`/training-history/${entry.id}`}
                                                className="text-link"
                                                aria-label={`View details for ${entry.title} on ${entry.scheduled_date}`}
                                            >
                                                View details
                                                <ArrowRight size={14} />
                                            </Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                ) : (
                    <EmptyState
                        title="No sessions in this view"
                        description="Past completed, skipped, planned, and rest sessions appear here. Try another filter or schedule your next session."
                    >
                        <ButtonLink href="/calendar">Open calendar</ButtonLink>
                    </EmptyState>
                )}
                <Pagination links={entries.links} />
            </section>
        </>
    );
}
