import PersonAvatar from "../../Components/PersonAvatar";
import { Head } from "@inertiajs/react";
import { Search, Users, ArrowRight } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    Pagination,
    EmptyState,
} from "../../Components/UI";
import { CoachStatus } from "../../Features/CoachingUI";
import useLiveFilters from "../../Features/useLiveFilters";
export default function ChooseTrainee({ plan, relationships, search }) {
    const form = useLiveFilters(
        `/coach/training-plans/${plan.id}/assign`,
        { search },
        ["relationships", "search"],
    );
    const action = (row) => (
        <div className="assignment-actions">
            <ButtonLink
                href={`/coach/trainees/${row.trainee.id}/${row.existing_plan_id ? `training-plans/${row.existing_plan_id}` : `setup?template=${plan.id}`}`}
                variant={row.existing_plan_id ? "secondary" : "primary"}
            >
                {row.existing_plan_id ? "View assignment" : "Assign"}
                <ArrowRight size={15} aria-hidden="true" />
            </ButtonLink>
            {row.existing_plan_id &&
                row.subscription?.effective_status === "active" && (
                    <details>
                        <summary>More options</summary>
                        <ButtonLink
                            variant="secondary"
                            href={`/coach/trainees/${row.trainee.id}/assign?template=${plan.id}&another=1`}
                        >
                            Assign another copy
                        </ButtonLink>
                    </details>
                )}
        </div>
    );
    const label = (row) =>
        row.existing_plan_id
            ? "Already assigned this template"
            : row.subscription?.effective_status === "active"
              ? "Available"
              : row.subscription?.effective_status === "paused"
                ? "Coaching paused"
                : ["ended", "expired"].includes(
                        row.subscription?.effective_status,
                    )
                  ? "Coaching ended"
                  : "Setup needed";
    return (
        <>
            <Head title="Assign to trainee" />
            <PageHeader
                title="Assign to trainee"
                description={`Choose who should follow ${plan.name}. Review dates and coaching before activating.`}
            >
                <ButtonLink
                    variant="secondary"
                    href={`/coach/training-plans/${plan.id}`}
                >
                    Back to template
                </ButtonLink>
            </PageHeader>
            <div
                className="panel coach-card live-filter-bar"
                aria-busy={form.processing}
            >
                <Field label="Search trainees">
                    <div className="search-input">
                        <Search size={18} aria-hidden="true" />
                        <input
                            type="search"
                            value={form.data.search}
                            maxLength={100}
                            onChange={(e) =>
                                form.setData("search", e.target.value)
                            }
                            placeholder="Name or Training Planner ID"
                        />
                    </div>
                </Field>
                {form.data.search && (
                    <Button
                        variant="secondary"
                        onClick={() => form.setData({ search: "" })}
                    >
                        Clear all
                    </Button>
                )}
                <p role="status" className="filter-feedback">
                    {form.processing
                        ? "Updating…"
                        : `${relationships.total} trainees found`}
                </p>
            </div>
            {relationships.data.length ? (
                <>
                    <div className="panel trainee-table">
                        <table>
                            <caption className="sr-only">
                                Connected trainees eligible for assignment
                            </caption>
                            <thead>
                                <tr>
                                    {[
                                        "Trainee",
                                        "Coaching",
                                        "Current training",
                                        "Status",
                                        "Action",
                                    ].map((x) => (
                                        <th scope="col" key={x}>
                                            {x}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {relationships.data.map((row) => (
                                    <tr key={row.id}>
                                        <th scope="row">
                                            <div className="person-identity">
                                                <PersonAvatar
                                                    name={row.trainee.name}
                                                />
                                                <div className="trainee-cell">
                                                    <strong>
                                                        {row.trainee.name}
                                                    </strong>
                                                    <small>
                                                        {
                                                            row.trainee
                                                                .training_code
                                                        }
                                                    </small>
                                                </div>
                                            </div>
                                        </th>
                                        <td>
                                            <CoachStatus
                                                status={
                                                    row.subscription
                                                        ?.effective_status
                                                }
                                            />
                                        </td>
                                        <td>
                                            {row.plan?.name || "Not assigned"}
                                        </td>
                                        <td>{label(row)}</td>
                                        <td>{action(row)}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <div className="trainee-mobile-cards">
                        {relationships.data.map((row) => (
                            <article className="panel coach-card" key={row.id}>
                                <div className="coach-card-title">
                                    <div className="person-identity">
                                        <PersonAvatar name={row.trainee.name} />
                                        <h2>{row.trainee.name}</h2>
                                    </div>
                                </div>
                                <p>{row.trainee.training_code}</p>
                                <CoachStatus
                                    status={row.subscription?.effective_status}
                                />
                                <p>{row.plan?.name || "No current training"}</p>
                                <p>{label(row)}</p>
                                {action(row)}
                            </article>
                        ))}
                    </div>
                </>
            ) : (
                <section className="panel">
                    <EmptyState
                        title="No matching connected trainees"
                        description="Try a name or Training Planner ID, or connect a new trainee."
                    >
                        <ButtonLink href="/coach/trainees">
                            Manage trainees
                        </ButtonLink>
                    </EmptyState>
                </section>
            )}
            <Pagination links={relationships.links} />
        </>
    );
}
