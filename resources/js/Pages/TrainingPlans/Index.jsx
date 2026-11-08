import useLiveFilters from "../../Features/useLiveFilters";
import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, useForm, router } from "@inertiajs/react";
import { Plus, Pencil, Trash2, ArrowRight } from "lucide-react";
import PlanThumbnail from "../../Components/PlanThumbnail";
import {
    PageHeader,
    Button,
    ButtonLink,
    Modal,
    ConfirmDialog,
    EmptyState,
    Pagination,
    Field,
    DateLabel,
} from "../../Components/UI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
import { DuplicatePlanDialog } from "../../Features/TrainingCopyActions";
import { Copy } from "lucide-react";
export default function Index({
    plans,
    filters = { search: "", type: "all" },
}) {
    const { url, canManage, coaching, templates } = useTraining();
    const filter = useLiveFilters(url("/training-plans"), filters, [
        "plans",
        "filters",
    ]);
    const [duplicating, setDuplicating] = useState(null);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    return (
        <>
            <Head
                title={
                    templates
                        ? "Programs"
                        : coaching
                          ? "Training assigned by you"
                          : "Training plans"
                }
            />
            <PageHeader
                eyebrow="BUILD YOUR ROUTINE"
                title={
                    templates
                        ? "Programs"
                        : coaching
                          ? "Training assigned by you"
                          : "Training plans"
                }
                description={
                    templates
                        ? "Create, manage and assign your reusable training programs."
                        : "Your goals, broken down into days you can show up for."
                }
            >
                {!coaching && (
                    <ButtonLink
                        href={url("/recommendations")}
                        variant="secondary"
                    >
                        Find a training plan
                    </ButtonLink>
                )}
                {!templates && (
                    <ButtonLink variant="secondary" href={url("/program-runs")}>
                        Program runs
                    </ButtonLink>
                )}
                {canManage && (
                    <Button
                        onClick={() =>
                            templates
                                ? router.visit("/coach/training-plans/create")
                                : setEditing({})
                        }
                    >
                        <Plus size={17} />
                        {templates ? "Create Program" : "Create training plan"}
                    </Button>
                )}
            </PageHeader>
            {templates && (
                <div
                    className="panel coach-card live-filter-bar"
                    aria-busy={filter.processing}
                >
                    <Field label="Search programs">
                        <input
                            value={filter.data.search}
                            maxLength={100}
                            onChange={(e) =>
                                filter.setData("search", e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Training type">
                        <select
                            value={filter.data.type}
                            onChange={(e) =>
                                filter.setData("type", e.target.value)
                            }
                        >
                            <option value="all">Programs & plans</option>
                            <option value="program">Structured programs</option>
                            <option value="simple">Simple plans</option>
                        </select>
                    </Field>
                    <span className="filter-feedback" role="status">
                        {filter.processing
                            ? "Updating…"
                            : `${plans.total} plans found`}
                    </span>
                    {(filter.data.search || filter.data.type !== "all") && (
                        <Button
                            variant="secondary"
                            onClick={() =>
                                filter.setData({ search: "", type: "all" })
                            }
                        >
                            Clear all
                        </Button>
                    )}
                </div>
            )}
            {coaching && canManage && (
                <div className="coach-actions" style={{ marginBottom: 20 }}>
                    <ButtonLink href={url("/assign")}>
                        Assign a coach template
                    </ButtonLink>
                    <p className="field-hint">
                        Or create a custom plan just for this trainee.
                    </p>
                </div>
            )}
            {plans.data.length ? (
                <>
                    <div className="plan-grid">
                        {plans.data.map((plan, index) => (
                            <article className="panel plan-card" key={plan.id}>
                                <div className="plan-cover plan-cover-photo">
                                    <PlanThumbnail
                                        src={plan.thumbnail_url}
                                        name={plan.name}
                                    />
                                    <span>
                                        {plan.structure_type === "program"
                                            ? `${plan.duration_weeks}-WEEK PROGRAM`
                                            : "YOUR TRAINING PLAN"}
                                    </span>
                                    <span className="plan-number">
                                        {String(index + 1).padStart(2, "0")}
                                    </span>
                                </div>
                                <div className="plan-body">
                                    <h2>{plan.name}</h2>
                                    {coaching && plan.assigned_at && (
                                        <p className="field-hint">
                                            Assigned{" "}
                                            <DateLabel
                                                date={plan.assigned_at.slice(
                                                    0,
                                                    10,
                                                )}
                                            />{" "}
                                            ·{" "}
                                            {plan.run_status
                                                ? `Run ${plan.run_status}`
                                                : plan.assignment_status ===
                                                    "ended"
                                                  ? "Ended assignment"
                                                  : "Not started"}
                                        </p>
                                    )}
                                    {templates && (
                                        <p>
                                            {plan.assigned_trainees_count}{" "}
                                            active trainees
                                        </p>
                                    )}
                                    {plan.coach_name && (
                                        <p className="field-hint">
                                            Coach assigned · {plan.coach_name}
                                            {plan.assignment_status === "ended"
                                                ? " · Assignment ended"
                                                : ""}
                                        </p>
                                    )}
                                    <div className="plan-meta">
                                        {plan.workout_days_count} training{" "}
                                        {plan.workout_days_count === 1
                                            ? "day"
                                            : "days"}
                                        <span>·</span>
                                        {plan.rest_days_count} rest{" "}
                                        {plan.rest_days_count === 1
                                            ? "day"
                                            : "days"}
                                    </div>
                                    <p>
                                        {plan.description ||
                                            "A fresh plan, ready to make your own."}
                                    </p>
                                    <div className="plan-actions">
                                        {templates && (
                                            <ButtonLink
                                                href={`/coach/training-plans/${plan.id}/assign`}
                                            >
                                                Assign
                                            </ButtonLink>
                                        )}
                                        <ButtonLink
                                            href={url(
                                                `/training-plans/${plan.id}`,
                                            )}
                                            variant="secondary"
                                            className="small"
                                        >
                                            View plan
                                            <ArrowRight size={14} />
                                        </ButtonLink>
                                        <div>
                                            {canManage &&
                                                (!coaching ||
                                                    plan.assignment_status ===
                                                        "active") && (
                                                    <button
                                                        className="icon-btn"
                                                        aria-label={`Edit ${plan.name}`}
                                                        onClick={() =>
                                                            templates
                                                                ? router.visit(
                                                                      `/coach/training-plans/${plan.id}/edit`,
                                                                  )
                                                                : setEditing(
                                                                      plan,
                                                                  )
                                                        }
                                                    >
                                                        <Pencil size={16} />
                                                    </button>
                                                )}
                                            {!coaching && (
                                                <button
                                                    className="icon-btn"
                                                    aria-label={`Duplicate ${plan.name}`}
                                                    onClick={() =>
                                                        setDuplicating(plan)
                                                    }
                                                >
                                                    <Copy size={16} />
                                                </button>
                                            )}
                                            {!coaching && (
                                                <button
                                                    className="icon-btn danger-text"
                                                    aria-label={`Delete ${plan.name}`}
                                                    onClick={() =>
                                                        setDeleting(plan)
                                                    }
                                                >
                                                    <Trash2 size={16} />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </div>
                            </article>
                        ))}
                    </div>
                    <Pagination links={plans.links} />
                </>
            ) : (
                <section className="panel">
                    <EmptyState
                        title="Every routine starts with a plan"
                        description="Create your first plan, then add training days and exercises."
                    >
                        {canManage && (
                            <Button
                                onClick={() =>
                                    templates
                                        ? router.visit(
                                              "/coach/training-plans/create",
                                          )
                                        : setEditing({})
                                }
                            >
                                <Plus size={16} />
                                Create your first plan
                            </Button>
                        )}
                    </EmptyState>
                </section>
            )}
            {duplicating && (
                <DuplicatePlanDialog
                    plan={duplicating}
                    onClose={() => setDuplicating(null)}
                />
            )}
            {editing && (
                <Modal
                    title={
                        editing.id
                            ? "Edit training plan"
                            : "Create training plan"
                    }
                    onClose={() => setEditing(null)}
                >
                    <TrainingPlanForm
                        plan={editing.id ? editing : null}
                        onSaved={() => setEditing(null)}
                        onCancel={() => setEditing(null)}
                    />
                </Modal>
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete training plan?"
                    description={`“${deleting.name}” and its training days, exercises, and thumbnails will be permanently removed. Your scheduled session history will remain.`}
                    url={url(`/training-plans/${deleting.id}`)}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
