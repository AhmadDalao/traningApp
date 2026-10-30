import { useState } from "react";
import { Head, useForm, Link, router } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    Field,
    Pagination,
    DateLabel,
} from "../../Components/UI";
import {
    EndRelationship,
    TraineeState,
    traineeStates,
} from "../../Features/CoachingUI";
import AddTrainee from "../../Features/AddTrainee";
function Identity({ row }) {
    return (
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
    );
}
function Program({ row }) {
    return (
        <div className="trainee-cell">
            <span title={row.plan?.name}>
                {row.plan?.name || "Not assigned"}
            </span>
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
                {entry.scheduled_time &&
                    ` · ${entry.scheduled_time.slice(0, 5)}`}
            </small>
        </div>
    ) : (
        "Not scheduled"
    );
}
function Actions({ row }) {
    return row.action ? (
        <Link className="btn btn-secondary" href={row.action.url}>
            {row.action.label} →
        </Link>
    ) : row.status === "pending" ? (
        <EndRelationship relationship={row} label="Cancel request" />
    ) : (
        <span>Access ended</span>
    );
}
export default function Trainees({ relationships, filters, counts = {} }) {
    const form = useForm(filters),
        [adding, setAdding] = useState(false);
    return (
        <>
            <Head title="Trainees" />
            <PageHeader
                title="Trainees"
                description="Your people, their training, and the next useful action."
            >
                <Button onClick={() => setAdding(true)}>Add trainee</Button>
            </PageHeader>
            <form
                className="panel coach-card coach-actions"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.get("/coach/trainees", { preserveState: true });
                }}
            >
                <Field label="Search connected trainees">
                    <input
                        value={form.data.search}
                        maxLength={100}
                        onChange={(e) => form.setData("search", e.target.value)}
                        placeholder="Name or Training Planner ID"
                    />
                </Field>
                <Button disabled={form.processing}>Search</Button>
            </form>
            <nav className="trainee-filters" aria-label="Trainee filters">
                {Object.entries(traineeStates).map(([key, label]) => (
                    <button
                        key={key}
                        type="button"
                        aria-pressed={filters.status === key}
                        onClick={() => {
                            form.setData("status", key);
                            router.get(
                                "/coach/trainees",
                                { ...form.data, status: key },
                                { preserveState: true },
                            );
                        }}
                    >
                        {label}{" "}
                        <span>
                            {key === "all"
                                ? Object.values(counts).reduce(
                                      (a, b) => a + Number(b),
                                      0,
                                  )
                                : counts[key] || 0}
                        </span>
                    </button>
                ))}
            </nav>
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
                                        "Coaching",
                                        "Program",
                                        "Assigned this week",
                                        "Next workout",
                                        "Last workout",
                                        "Status",
                                        "Next action",
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
                                            <div className="trainee-cell">
                                                <span>
                                                    {row.subscription
                                                        ?.package_name ||
                                                        "Not configured"}
                                                </span>
                                                <small>
                                                    {
                                                        row.subscription
                                                            ?.effective_status
                                                    }
                                                    {row.subscription?.ends_on
                                                        ? ` · Ends ${row.subscription.ends_on}`
                                                        : row.subscription
                                                          ? " · Ongoing"
                                                          : ""}
                                                </small>
                                            </div>
                                        </td>
                                        <td>
                                            <Program row={row} />
                                        </td>
                                        <td>
                                            {row.status === "active"
                                                ? `${row.week.completed} / ${row.week.total}`
                                                : "—"}
                                        </td>
                                        <td>
                                            <Next entry={row.next_session} />
                                        </td>
                                        <td>
                                            {row.last_workout ? (
                                                <DateLabel
                                                    date={
                                                        row.last_workout
                                                            .scheduled_date
                                                    }
                                                />
                                            ) : (
                                                "No completed workout"
                                            )}
                                        </td>
                                        <td>
                                            <TraineeState
                                                state={row.display_state}
                                            />
                                            <small className="trainee-reason">
                                                {row.reason}
                                            </small>
                                        </td>
                                        <td>
                                            <Actions row={row} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="trainee-mobile-cards">
                        {relationships.data.map((row) => (
                            <article className="panel coach-card" key={row.id}>
                                <div className="coach-card-title">
                                    <Identity row={row} />
                                    <TraineeState state={row.display_state} />
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
