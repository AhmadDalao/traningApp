import { useState } from "react";
import { Head, useForm, Link } from "@inertiajs/react";
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
    Errors,
    coachingJson,
} from "../../Features/CoachingUI";
export default function Trainees({ relationships, filters }) {
    const search = useForm(filters),
        request = useForm({ training_code: "" });
    const [member, setMember] = useState(null),
        [error, setError] = useState(""),
        [busy, setBusy] = useState(false);
    async function lookup(e) {
        e.preventDefault();
        setBusy(true);
        setError("");
        setMember(null);
        try {
            const result = await coachingJson("/coach/lookup", request.data);
            setMember(result.member);
            if (!result.member) setError("No other member found with that ID.");
        } catch (e) {
            setError(e.message);
        } finally {
            setBusy(false);
        }
    }
    return (
        <>
            <Head title="Trainees" />
            <PageHeader
                title="Trainees"
                description="Connections begin with a request and the trainee's approval."
            />
            <section className="panel coach-card">
                <h2>Add trainee</h2>
                <form onSubmit={lookup} className="coach-actions">
                    <Field label="Training Planner ID">
                        <input
                            required
                            maxLength={11}
                            placeholder="TP-7F4K9M2Q"
                            value={request.data.training_code}
                            onChange={(e) => {
                                request.setData(
                                    "training_code",
                                    e.target.value.toUpperCase(),
                                );
                                setMember(null);
                            }}
                        />
                    </Field>
                    <Button disabled={busy}>Find member</Button>
                </form>
                <p className="field-hint">
                    Or scan their temporary QR with your phone camera. Search
                    uses an exact ID, never an email address.
                </p>
                {error && (
                    <p className="field-error" role="alert">
                        {error}
                    </p>
                )}
                <Errors errors={request.errors} />
                {member && (
                    <div className="coach-member">
                        <strong>{member.name}</strong>
                        <span>Training Planner member</span>
                        <Button
                            disabled={request.processing}
                            onClick={() =>
                                request.post("/coach/requests", {
                                    onSuccess: () => {
                                        setMember(null);
                                        request.reset();
                                    },
                                })
                            }
                        >
                            Send request
                        </Button>
                    </div>
                )}
            </section>
            <form
                className="panel coach-card coach-actions"
                onSubmit={(e) => {
                    e.preventDefault();
                    search.get("/coach/trainees", { preserveState: true });
                }}
            >
                <Field label="Search connected trainees">
                    <input
                        value={search.data.search}
                        onChange={(e) =>
                            search.setData("search", e.target.value)
                        }
                        placeholder="Name or Training Planner ID"
                    />
                </Field>
                <Field label="Connection status">
                    <select
                        value={search.data.status}
                        onChange={(e) =>
                            search.setData("status", e.target.value)
                        }
                    >
                        {["active", "pending", "ended", "all"].map((s) => (
                            <option key={s} value={s}>
                                {s}
                            </option>
                        ))}
                    </select>
                </Field>
                <Button>Apply filters</Button>
            </form>
            <div className="coach-grid">
                {relationships.data.map((row) => (
                    <section key={row.id} className="panel coach-card">
                        <div className="coach-card-title">
                            <h2>{row.trainee.name}</h2>
                            <CoachStatus status={row.status} />
                        </div>
                        <p>{row.trainee.training_code}</p>
                        {row.subscription && (
                            <p>
                                {row.subscription.package_name} ·{" "}
                                <CoachStatus
                                    status={row.subscription.effective_status}
                                />
                            </p>
                        )}
                        {row.next_session && (
                            <p>
                                Next: {row.next_session.title} ·{" "}
                                <DateLabel
                                    date={row.next_session.scheduled_date}
                                />
                            </p>
                        )}
                        {row.last_workout && (
                            <p>
                                Last workout:{" "}
                                <DateLabel
                                    date={row.last_workout.scheduled_date}
                                />
                            </p>
                        )}
                        <div className="coach-actions">
                            {row.status === "active" && (
                                <Link
                                    className="btn btn-primary"
                                    href={`/coach/trainees/${row.trainee.id}`}
                                >
                                    View trainee →
                                </Link>
                            )}
                            {row.status !== "ended" && (
                                <EndRelationship
                                    relationship={row}
                                    label={
                                        row.status === "pending"
                                            ? "Cancel request"
                                            : "End relationship"
                                    }
                                />
                            )}
                        </div>
                    </section>
                ))}
            </div>
            {!relationships.data.length && (
                <p className="panel coach-card">
                    No connections match these filters.
                </p>
            )}
            <Pagination links={relationships.links} />
        </>
    );
}
