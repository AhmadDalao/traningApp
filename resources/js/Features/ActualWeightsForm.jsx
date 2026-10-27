import { useForm } from "@inertiajs/react";
import { Button, Field, Modal, formatWeight } from "../Components/UI";

export default function ActualWeightsForm({ entry, exercises, onClose }) {
    const form = useForm({
        weights: exercises.map((exercise, index) => ({
            index,
            actual_weight: exercise.actual_weight ?? "",
        })),
    });
    return (
        <Modal
            title="Edit actual weights"
            description="Record the weight used for each exercise. Every field is optional."
            onClose={() => !form.processing && onClose()}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    form.patch(`/schedule-entries/${entry.id}/weights`, {
                        preserveScroll: true,
                        onSuccess: onClose,
                    });
                }}
            >
                {form.errors.weights && (
                    <p className="field-error" role="alert">
                        {form.errors.weights}
                    </p>
                )}
                <div className="actual-weight-fields">
                    {exercises.map((exercise, index) => (
                        <div className="actual-weight-row" key={index}>
                            <div>
                                <strong>{exercise.name}</strong>
                                <p>
                                    {exercise.sets} × {exercise.reps} · Planned:{" "}
                                    {formatWeight(exercise.target_weight)}
                                </p>
                            </div>
                            <Field
                                label={`Actual weight for ${exercise.name}`}
                                error={
                                    form.errors[
                                        `weights.${index}.actual_weight`
                                    ] || form.errors[`weights.${index}.index`]
                                }
                            >
                                <div className="input-with-unit">
                                    <input
                                        type="number"
                                        inputMode="decimal"
                                        min="0"
                                        max="9999.99"
                                        step="0.01"
                                        placeholder="Optional"
                                        value={
                                            form.data.weights[index]
                                                .actual_weight
                                        }
                                        onChange={(event) =>
                                            form.setData(
                                                "weights",
                                                form.data.weights.map(
                                                    (weight, position) =>
                                                        position === index
                                                            ? {
                                                                  ...weight,
                                                                  actual_weight:
                                                                      event
                                                                          .target
                                                                          .value,
                                                              }
                                                            : weight,
                                                ),
                                            )
                                        }
                                    />
                                    <span aria-hidden="true">kg</span>
                                </div>
                            </Field>
                        </div>
                    ))}
                </div>
                <div className="form-actions">
                    <Button
                        type="button"
                        variant="secondary"
                        disabled={form.processing}
                        onClick={onClose}
                    >
                        Cancel
                    </Button>
                    <Button disabled={form.processing}>
                        {form.processing ? "Saving…" : "Save actual weights"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
