import PersonAvatar from "../../Components/PersonAvatar";
import useLiveFilters from "../../Features/useLiveFilters";
import { Search, Plus, SlidersHorizontal, ChevronRight } from "lucide-react";
import { useState } from "react";
import { Head, Link, usePage } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    Field,
    Pagination,
    DateLabel,
} from "../../Components/UI";
import {
    CoachStatus,
    EndRelationship,
    TraineeState,
    traineeStates,
} from "../../Features/CoachingUI";
import AddTrainee from "../../Features/AddTrainee";
function Identity({ row }) {
    return (
        <div className="person-identity">
            <PersonAvatar name={row.trainee.name} />
            <div className="trainee-cell">
                <strong>
                    {row.trainee.id ? (
                        <Link
                            href={`/coach/trainees/${row.trainee.id}`}
                            aria-label={`View trainee ${row.trainee.name}`}
                        >
                            {row.trainee.name}
                        </Link>
                    ) : (
                        row.trainee.name
                    )}
                </strong>
                <small>{row.trainee.training_code}</small>
            </div>
        </div>
    );
}
function Program({ row }) {
    return (
        <div className="trainee-cell">
            <span title={row.plan?.name}>
                {row.plan?.name || "No program assigned"}
            </span>
            <small>
                {row.subscription?.package_name || "No coaching package"}
            </small>
            <small>
                {row.plan?.current_week
                    ? `Week ${row.plan.current_week} of ${row.plan.duration_weeks}`
                    : row.plan?.run_status?.replaceAll("_", " ")}
            </small>
        </div>
    );
}
function Next({ entry }) {
    return entry ? (
        <div className="trainee-cell">
            <span>{entry.title}</span>
            <small>
                <DateLabel date={entry.scheduled_date} />
                {entry.scheduled_time
                    ? ` · ${entry.scheduled_time.slice(0, 5)}`
                    : " · Anytime"}
            </small>
        </div>
    ) : (
        "Not scheduled"
    );
}
function CoachingState({ row }) {
    return (
        <div className="roster-states">
            {row.subscription ? (
                <CoachStatus status={row.subscription.effective_status} />
            ) : (
                <TraineeState state={row.display_state} />
            )}{" "}
            {row.subscription &&
                ["attention", "setup", "paused"].includes(
                    row.display_state,
                ) && (
                    <span className="roster-attention" title={row.reason}>
                        {row.display_state === "attention"
                            ? "Needs attention"
                            : row.display_state === "setup"
                              ? "Needs setup"
                              : row.subscription.effective_status !== "paused"
                                ? "Program paused"
                                : "Review pause"}
                    </span>
                )}
        </div>
    );
}
function Actions({ row }) {
    return row.action ? (
        <Link className="btn btn-secondary" href={row.action.url}>
            {["attention", "setup"].includes(row.display_state)
                ? "Review"
                : "Open"}{" "}
            <ChevronRight size={17} />
        </Link>
    ) : row.status === "pending" ? (
        <EndRelationship relationship={row} label="Cancel request" />
    ) : (
        <span>Access ended</span>
    );
}
export default function Trainees({
    relationships,
    filters,
    counts = {},
    rosterStats = {},
}) {
    const form = useLiveFilters("/coach/trainees", filters, [
            "relationships",
            "filters",
            "counts",
            "rosterStats",
        ]),
        [adding, setAdding] = useState(false);
    const { url } = usePage();
    const [moreFilters, setMoreFilters] = useState(false);
    return (
        <>
            <Head title="Trainees" />
            <PageHeader
                eyebrow="PEOPLE & PERFORMANCE"
                title="Your trainees"
                description="A focused roster with progress, context and the next useful action."
            >
                <Button onClick={() => setAdding(true)}>
                    <Plus size={17} aria-hidden="true" /> Add trainee
                </Button>
            </PageHeader>
            <p className="roster-summary">
                <strong>{rosterStats.connected || 0}</strong> connected trainees{" "}
                <span />
                <strong>{rosterStats.coaching || 0}</strong> active coaching{" "}
                <span />
                <strong>{rosterStats.review || 0}</strong> need attention
            </p>
            <nav className="trainee-filters" aria-label="Trainee filters">
                {[
                    ["all", "All"],
                    ["review", "Attention"],
                    ["coaching", "Active"],
                    ["setup", "Needs setup"],
                    ["paused", "Paused"],
                ].map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={form.data.status === key}
                        onClick={() => form.setData("status", key)}
                    >
                        {key === "active" ? "On track" : label}{" "}
                        <span>
                            {key === "all"
                                ? Object.values(counts).reduce(
                                      (a, b) => a + Number(b),
                                      0,
                                  )
                                : (rosterStats[key] ?? counts[key] ?? 0)}
                        </span>
                    </button>
                ))}
            </nav>
            <div
                className="panel coach-card live-filter-bar roster-search-bar"
                aria-busy={form.processing}
            >
                <Field label="Search connected trainees">
                    <div className="search-input">
                        <Search size={18} aria-hidden="true" />
                        <input
                            type="search"
                            autoFocus={
                                new URLSearchParams(url.split("?")[1]).get(
                                    "focus",
                                ) === "search"
                            }
                            value={form.data.search}
                            maxLength={100}
                            onChange={(e) =>
                                form.setData("search", e.target.value)
                            }
                            placeholder="Name, Training Planner ID or program"
                        />
                    </div>
                </Field>
                <Field label="Sort trainees">
                    <select
                        value={form.data.sort}
                        onChange={(e) => form.setData("sort", e.target.value)}
                    >
                        <option value="newest">Newest first</option>
                        <option value="name">Name A–Z</option>
                    </select>
                </Field>
                <Button
                    variant="secondary"
                    aria-expanded={moreFilters}
                    onClick={() => setMoreFilters(!moreFilters)}
                >
                    <SlidersHorizontal size={18} />
                    Filters
                </Button>
                <div
                    className="directory-advanced-filters"
                    hidden={!moreFilters}
                >
                    <Field label="Items per page">
                        <select
                            value={form.data.per_page}
                            onChange={(e) =>
                                form.setData("per_page", Number(e.target.value))
                            }
                        >
                            {[10, 25, 50, 100].map((n) => (
                                <option value={n} key={n}>
                                    {n}
                                </option>
                            ))}
                        </select>
                    </Field>
                    {(form.data.search ||
                        form.data.status !== "all" ||
                        form.data.sort !== "newest") && (
                        <Button
                            variant="secondary"
                            onClick={() =>
                                form.setData({
                                    search: "",
                                    status: "all",
                                    sort: "newest",
                                    per_page: 25,
                                })
                            }
                        >
                            Clear all
                        </Button>
                    )}
                    <Field label="Connection status">
                        <select
                            value={form.data.status}
                            onChange={(e) =>
                                form.setData("status", e.target.value)
                            }
                        >
                            {Object.entries({
                                ...traineeStates,
                                coaching: "Active coaching",
                                review: "Needs attention (all)",
                            }).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {key === "active" ? "On track" : label}
                                </option>
                            ))}
                        </select>
                    </Field>
                </div>
            </div>
            {relationships.data.length ? (
                <>
                    <div className="panel trainee-table">
                        <table>
                            <caption className="sr-only">
                                Trainees and next actions
                            </caption>
                            <thead>
                                <tr>
                                    {[
                                        "Trainee",
                                        "Current program",
                                        "This Week",
                                        "Next workout",
                                        "Coaching",
                                        "Next step",
                                    ].map((h) => (
                                        <th key={h} scope="col">
                                            {h}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {relationships.data.map((row) => (
                                    <tr key={row.id}>
                                        <th scope="row">
                                            <Identity row={row} />
                                        </th>
                                        <td>
                                            <Program row={row} />
                                        </td>
                                        <td>
                                            <div
                                                className={`roster-week ${row.week.total && row.week.completed / row.week.total < 0.5 ? "low" : ""}`}
                                            >
                                                <div>
                                                    <progress
                                                        className="roster-progress"
                                                        max={
                                                            row.week.total || 1
                                                        }
                                                        value={
                                                            row.week.completed
                                                        }
                                                        aria-label="Assigned workouts completed this week"
                                                    />
                                                    <strong>
                                                        {row.week.completed}/
                                                        {row.week.total}
                                                    </strong>
                                                </div>
                                                <small>
                                                    {row.week.total
                                                        ? `${Math.round((row.week.completed / row.week.total) * 100)}% completion`
                                                        : "Not scheduled"}
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <Next entry={row.next_session} />
                                        </td>
                                        <td>
                                            <CoachingState row={row} />
                                        </td>
                                        <td>
                                            <Actions row={row} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <footer className="directory-table-footer">
                            <span>
                                Showing {relationships.from || 0}–
                                {relationships.to || 0} of {relationships.total}{" "}
                                trainees
                            </span>
                            <span>One profile · all the context</span>
                        </footer>
                    </div>
                    <div className="trainee-mobile-cards">
                        {relationships.data.map((row) => (
                            <article className="panel coach-card" key={row.id}>
                                <div className="coach-card-title">
                                    <Identity row={row} />
                                    <CoachingState row={row} />
                                </div>
                                <p>
                                    {row.subscription?.package_name ||
                                        "Coaching not configured"}
                                </p>
                                <Program row={row} />
                                <dl>
                                    <div>
                                        <dt>Next workout</dt>
                                        <dd>
                                            <Next entry={row.next_session} />
                                        </dd>
                                    </div>
                                    <div>
                                        <dt>Assigned week</dt>
                                        <dd>
                                            {row.week.completed} /{" "}
                                            {row.week.total} completed
                                        </dd>
                                    </div>
                                </dl>
                                <p>{row.reason}</p>
                                <Actions row={row} />
                            </article>
                        ))}
                    </div>
                </>
            ) : (
                <section className="panel coach-card">
                    <h2>
                        {filters.search || filters.status !== "all"
                            ? "No matching trainees"
                            : "No trainees yet"}
                    </h2>
                    <p>
                        {filters.search
                            ? "Try a different name or Training Planner ID."
                            : "Connect using their Training Planner ID or temporary QR code."}
                    </p>
                    <Button variant="secondary" onClick={() => setAdding(true)}>
                        Add trainee
                    </Button>
                </section>
            )}
            <Pagination links={relationships.links} />
            {adding && <AddTrainee close={() => setAdding(false)} />}
        </>
    );
}
