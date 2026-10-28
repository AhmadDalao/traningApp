import { useForm } from "@inertiajs/react";
import { Copy, MoreHorizontal } from "lucide-react";
import { Button, ConfirmDialog, Field, Modal } from "../Components/UI";

export function CopyActions({ label = "More actions", onDuplicate, onCopy }) {
    return (
        <details className="copy-actions">
            <summary className="btn btn-secondary small" aria-label={label}>
                <MoreHorizontal size={17} />
                <span>More</span>
            </summary>
            <div className="copy-actions-list">
                <button
                    type="button"
                    onClick={(event) => {
                        event.currentTarget.closest("details").open = false;
                        onDuplicate();
                    }}
                >
                    <Copy size={15} />
                    Duplicate
                </button>
                {onCopy && (
                    <button
                        type="button"
                        onClick={(event) => {
                            event.currentTarget.closest("details").open = false;
                            onCopy();
                        }}
                    >
                        Copy to plan
                    </button>
                )}
            </div>
        </details>
    );
}

export function DuplicatePlanDialog({ plan, onClose }) {
    return (
        <ConfirmDialog
            title="Duplicate training plan?"
            description={`Create an independent copy of “${plan.name}”, including its days, exercises and schedule preferences. The copy starts unscheduled, with no workout history.`}
            url={`/training-plans/${plan.id}/duplicate`}
            method="post"
            data={{ confirmed: true }}
            confirmLabel="Duplicate plan"
            variant="primary"
            onClose={onClose}
        />
    );
}

export function DayCopyDialog({ day, plans, mode, onClose }) {
    const form = useForm({
        confirmed: true,
        training_plan_id: mode === "copy" ? plans[0]?.id || "" : "",
    });
    return (
        <Modal
            title={
                mode === "copy"
                    ? "Copy training day to plan"
                    : "Duplicate training day?"
            }
            onClose={() => !form.processing && onClose()}
        >
            <p className="copy-description">
                Create an independent copy of “{day.title}”, including its
                exercises, media and weekday preferences. Calendar sessions and
                workout history stay unchanged.
            </p>
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/training-days/${day.id}/duplicate`, {
                        onSuccess: onClose,
                    });
                }}
            >
                {mode === "copy" && (
                    <Field
                        label="Destination plan"
                        error={form.errors.training_plan_id}
                    >
                        <select
                            value={form.data.training_plan_id}
                            onChange={(event) =>
                                form.setData(
                                    "training_plan_id",
                                    event.target.value,
                                )
                            }
                            required
                        >
                            <option value="">Choose a plan</option>
                            {plans.map((plan) => (
                                <option key={plan.id} value={plan.id}>
                                    {plan.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                {mode === "copy" && !plans.length && (
                    <p className="field-hint">
                        Create another training plan first, then copy this day
                        into it.
                    </p>
                )}
                {Object.entries(form.errors)
                    .filter(([key]) => key !== "training_plan_id")
                    .map(([key, error]) => (
                        <p className="field-error" role="alert" key={key}>
                            {error}
                        </p>
                    ))}
                <div className="form-actions">
                    <Button
                        type="button"
                        variant="secondary"
                        onClick={onClose}
                        disabled={form.processing}
                    >
                        Cancel
                    </Button>
                    <Button
                        disabled={
                            form.processing ||
                            (mode === "copy" && !form.data.training_plan_id)
                        }
                    >
                        {form.processing
                            ? "Copying…"
                            : mode === "copy"
                              ? "Copy day"
                              : "Duplicate day"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
