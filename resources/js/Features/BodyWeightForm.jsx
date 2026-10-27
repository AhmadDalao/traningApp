import { useForm, usePage } from "@inertiajs/react";
import { Modal, Field, Button } from "../Components/UI";

export default function BodyWeightForm({ entry, onClose }) {
    const { today } = usePage().props;
    const form = useForm({
        recorded_date: entry?.recorded_date || today,
        weight_kg: entry?.weight_kg ?? "",
        note: entry?.note || "",
    });
    return (
        <Modal
            title={entry ? "Edit body weight" : "Record body weight"}
            description="Keep a dated record of your progress."
            onClose={() => !form.processing && onClose()}
        >
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    const settings = {
                        preserveScroll: true,
                        onSuccess: onClose,
                    };
                    entry
                        ? form.put(`/body-weight/${entry.id}`, settings)
                        : form.post("/body-weight", settings);
                }}
            >
                <Field label="Date" required error={form.errors.recorded_date}>
                    <input
                        type="date"
                        required
                        max={today}
                        value={form.data.recorded_date}
                        onInput={(event) =>
                            form.setData("recorded_date", event.target.value)
                        }
                    />
                </Field>
                <Field
                    label="Body weight"
                    required
                    error={form.errors.weight_kg}
                >
                    <div className="input-with-unit">
                        <input
                            autoFocus
                            type="number"
                            required
                            min="20"
                            max="500"
                            step="0.01"
                            inputMode="decimal"
                            value={form.data.weight_kg}
                            onChange={(event) =>
                                form.setData("weight_kg", event.target.value)
                            }
                        />
                        <span>kg</span>
                    </div>
                </Field>
                <Field label="Note (optional)" error={form.errors.note}>
                    <textarea
                        rows={3}
                        maxLength={1000}
                        value={form.data.note}
                        onChange={(event) =>
                            form.setData("note", event.target.value)
                        }
                    />
                </Field>
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
                        {form.processing ? "Saving…" : "Save weight"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
