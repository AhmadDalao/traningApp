import { useState } from "react";
import { Head } from "@inertiajs/react";
import { Plus, Pencil, Trash2, ArrowRight, Layers } from "lucide-react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Modal,
    ConfirmDialog,
    EmptyState,
    Pagination,
} from "../../Components/UI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
import { DuplicatePlanDialog } from "../../Features/TrainingCopyActions";
import { Copy } from "lucide-react";
export default function Index({ plans }) {
    const [duplicating, setDuplicating] = useState(null);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    return (
        <>
            <Head title="Training plans" />
            <PageHeader
                eyebrow="BUILD YOUR ROUTINE"
                title="Training plans"
                description="Your goals, broken down into days you can show up for."
            >
                <Button onClick={() => setEditing({})}>
                    <Plus size={17} />
                    Create training plan
                </Button>
            </PageHeader>
            {plans.data.length ? (
                <>
                    <div className="plan-grid">
                        {plans.data.map((plan, index) => (
                            <article className="panel plan-card" key={plan.id}>
                                <div
                                    className={`plan-cover cover-${index % 3}`}
                                >
                                    <Layers size={38} strokeWidth={1.2} />
                                    <span>YOUR TRAINING PLAN</span>
                                    <span className="plan-number">
                                        {String(index + 1).padStart(2, "0")}
                                    </span>
                                </div>
                                <div className="plan-body">
                                    <h2>{plan.name}</h2>
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
                                        <ButtonLink
                                            href={`/training-plans/${plan.id}`}
                                            variant="secondary"
                                            className="small"
                                        >
                                            View plan
                                            <ArrowRight size={14} />
                                        </ButtonLink>
                                        <div>
                                            <button
                                                className="icon-btn"
                                                aria-label={`Edit ${plan.name}`}
                                                onClick={() => setEditing(plan)}
                                            >
                                                <Pencil size={16} />
                                            </button>
                                            <button
                                                className="icon-btn"
                                                aria-label={`Duplicate ${plan.name}`}
                                                onClick={() =>
                                                    setDuplicating(plan)
                                                }
                                            >
                                                <Copy size={16} />
                                            </button>
                                            <button
                                                className="icon-btn danger-text"
                                                aria-label={`Delete ${plan.name}`}
                                                onClick={() =>
                                                    setDeleting(plan)
                                                }
                                            >
                                                <Trash2 size={16} />
                                            </button>
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
                        <Button onClick={() => setEditing({})}>
                            <Plus size={16} />
                            Create your first plan
                        </Button>
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
                    url={`/training-plans/${deleting.id}`}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
