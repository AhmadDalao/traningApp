import { useEffect, useState } from "react";
import { useForm } from "@inertiajs/react";
import { Check, RotateCcw, Trash2 } from "lucide-react";
import { Button, ConfirmDialog, Field, formatWeight } from "../Components/UI";
import { formatDuration, parseDuration } from "./workout";

export function DurationInput({ value, onChange, ...props }) {
    return (
        <input
            {...props}
            type="text"
            inputMode="text"
            placeholder="mm:ss"
            maxLength={7}
            value={value}
            onChange={(event) => onChange(event.target.value)}
        />
    );
}

export default function WorkoutSetRow({ set, exercise, onDirty }) {
    const durationMode = exercise.tracking_mode === "duration";
    const initial = {
        result: durationMode
            ? formatDuration(set.actual_duration_seconds)
            : (set.actual_reps ?? ""),
        weight:
            set.actual_weight_kg ??
            (set.completed_at ? "" : (set.planned_weight_kg ?? "")),
    };
    const form = useForm(initial);
    const [removing, setRemoving] = useState(false);
    const [durationError, setDurationError] = useState("");
    const dirty =
        String(form.data.result) !== String(initial.result) ||
        String(form.data.weight) !== String(initial.weight);
    useEffect(() => {
        onDirty(set.id, dirty);
        return () => onDirty(set.id, false);
    }, [dirty, onDirty, set.id]);
    function save(completed) {
        setDurationError("");
        const result = durationMode
            ? parseDuration(form.data.result)
            : form.data.result === ""
              ? null
              : Number(form.data.result);
        if (Number.isNaN(result)) {
            setDurationError("Use mm:ss, from 00:01 to 1440:00.");
            return;
        }
        form.transform(() => ({
            [durationMode ? "actual_duration_seconds" : "actual_reps"]: result,
            actual_weight_kg: form.data.weight === "" ? null : form.data.weight,
            completed,
        }));
        form.patch(`/session-sets/${set.id}`, { preserveScroll: true });
    }
    return (
        <div
            className={`workout-set ${set.completed_at ? "is-complete" : ""}`}
            aria-label={`${exercise.name} set ${set.set_number}`}
        >
            <div className="set-target">
                <strong>Set {set.set_number}</strong>
                <span>
                    Target: {set.planned_reps_text || "—"}
                    {set.planned_weight_kg != null
                        ? ` · ${formatWeight(set.planned_weight_kg)}`
                        : ""}
                </span>
            </div>
            <Field label="Weight (kg)" error={form.errors.actual_weight_kg}>
                <input
                    type="number"
                    inputMode="decimal"
                    min="0"
                    max="9999.99"
                    step="0.01"
                    value={form.data.weight}
                    placeholder={set.planned_weight_kg ?? "Optional"}
                    onChange={(event) =>
                        form.setData("weight", event.target.value)
                    }
                />
            </Field>
            <Field
                label={durationMode ? "Duration (mm:ss)" : "Reps"}
                error={
                    durationError ||
                    form.errors.actual_reps ||
                    form.errors.actual_duration_seconds
                }
            >
                {durationMode ? (
                    <DurationInput
                        value={form.data.result}
                        onChange={(value) => form.setData("result", value)}
                    />
                ) : (
                    <input
                        type="number"
                        inputMode="numeric"
                        min="1"
                        max="10000"
                        step="1"
                        value={form.data.result}
                        placeholder="Actual reps"
                        onChange={(event) =>
                            form.setData("result", event.target.value)
                        }
                    />
                )}
            </Field>
            <div className="set-actions">
                <Button
                    variant={set.completed_at ? "secondary" : "primary"}
                    disabled={form.processing}
                    onClick={() => save(true)}
                    aria-label={
                        set.completed_at
                            ? `Save set ${set.set_number}`
                            : `Complete set ${set.set_number}`
                    }
                >
                    <Check size={18} />
                    {form.processing
                        ? "Saving…"
                        : set.completed_at
                          ? dirty
                              ? "Save changes"
                              : "Done"
                          : "Done"}
                </Button>
                {!set.completed_at && dirty && (
                    <Button
                        variant="secondary"
                        disabled={form.processing}
                        onClick={() => save(false)}
                    >
                        Save draft
                    </Button>
                )}
                {set.completed_at && (
                    <button
                        className="icon-btn"
                        type="button"
                        disabled={form.processing}
                        aria-label={`Mark set ${set.set_number} incomplete`}
                        onClick={() => save(false)}
                    >
                        <RotateCcw size={18} />
                    </button>
                )}
                <button
                    className="icon-btn danger-text"
                    type="button"
                    disabled={form.processing}
                    aria-label={`Remove set ${set.set_number}`}
                    onClick={() => {
                        if (set.completed_at) setRemoving(true);
                        else
                            form.delete(`/session-sets/${set.id}`, {
                                preserveScroll: true,
                            });
                    }}
                >
                    <Trash2 size={18} />
                </button>
            </div>
            {dirty && (
                <small className="unsaved-set">
                    Unsaved changes — tap Done or Save
                    {set.completed_at ? " changes" : " draft"}.
                </small>
            )}
            {form.errors.workout && (
                <p role="alert" className="field-error">
                    {form.errors.workout}
                </p>
            )}
            {removing && (
                <ConfirmDialog
                    title="Remove completed set?"
                    description="This set and its actual results will be removed from this workout. Your training plan stays the same."
                    url={`/session-sets/${set.id}`}
                    data={{ confirmed: true }}
                    onClose={() => setRemoving(false)}
                />
            )}
        </div>
    );
}
