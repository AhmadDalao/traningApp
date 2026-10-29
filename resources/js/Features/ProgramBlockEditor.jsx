import { useForm } from "@inertiajs/react";
import { Button, Field, Modal } from "../Components/UI";
import { Errors, useTraining } from "./CoachingUI";
export default function ProgramBlockEditor({ plan, blocks, onClose }) {
    const { url } = useTraining();
    const form = useForm({
        duration_weeks: plan.duration_weeks,
        blocks: blocks.map(
            ({
                id,
                name,
                start_week,
                end_week,
                type,
                description,
                editable,
            }) => ({
                id,
                name,
                start_week,
                end_week,
                type,
                description: description || "",
                editable,
            }),
        ),
    });
    const set = (i, key, value) =>
        form.setData(
            "blocks",
            form.data.blocks.map((b, j) =>
                i === j ? { ...b, [key]: value } : b,
            ),
        );
    function move(i, offset) {
        const rows = [...form.data.blocks];
        [rows[i], rows[i + offset]] = [rows[i + offset], rows[i]];
        let week = 1;
        form.setData(
            "blocks",
            rows.map((row) => {
                const length =
                    Number(row.end_week) - Number(row.start_week) + 1;
                const next = {
                    ...row,
                    start_week: week,
                    end_week: week + length - 1,
                };
                week += length;
                return next;
            }),
        );
    }
    function add(source) {
        const start = Number(form.data.blocks.at(-1)?.end_week || 0) + 1;
        form.setData("blocks", [
            ...form.data.blocks,
            {
                name: source ? `${source.name} — Copy` : "New block",
                start_week: start,
                end_week: start,
                type: source?.type || "normal",
                description: source?.description || "",
                ...(source?.id ? { copy_from: source.id } : {}),
                editable: true,
            },
        ]);
    }
    return (
        <Modal
            title="Edit program blocks"
            description="Cover every program week once. Save the whole structure together; active runs change only after a separate review."
            onClose={onClose}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.put(url(`/training-plans/${plan.id}/blocks`), {
                        onSuccess: onClose,
                    });
                }}
            >
                <Field label="Program duration (weeks)" required>
                    <input
                        type="number"
                        min="1"
                        max="52"
                        required
                        value={form.data.duration_weeks}
                        onChange={(e) =>
                            form.setData("duration_weeks", e.target.value)
                        }
                    />
                </Field>
                <div className="program-stack">
                    {form.data.blocks.map((block, i) => (
                        <fieldset
                            className="program-block-fields"
                            key={block.id || `new-${i}`}
                            disabled={block.editable === false}
                        >
                            <legend>
                                Block {i + 1}
                                {block.editable === false ? " · Protected" : ""}
                            </legend>
                            <Field label="Block name" required>
                                <input
                                    maxLength={150}
                                    required
                                    value={block.name}
                                    onChange={(e) =>
                                        set(i, "name", e.target.value)
                                    }
                                />
                            </Field>
                            <div className="form-grid">
                                <Field label="From week" required>
                                    <input
                                        type="number"
                                        min="1"
                                        max="52"
                                        required
                                        value={block.start_week}
                                        onChange={(e) =>
                                            set(i, "start_week", e.target.value)
                                        }
                                    />
                                </Field>
                                <Field label="To week" required>
                                    <input
                                        type="number"
                                        min="1"
                                        max="52"
                                        required
                                        value={block.end_week}
                                        onChange={(e) =>
                                            set(i, "end_week", e.target.value)
                                        }
                                    />
                                </Field>
                            </div>
                            <Field label="Block type">
                                <select
                                    value={block.type}
                                    onChange={(e) =>
                                        set(i, "type", e.target.value)
                                    }
                                >
                                    <option value="normal">Normal</option>
                                    <option value="deload">Deload</option>
                                </select>
                            </Field>
                            <Field label="Description">
                                <textarea
                                    maxLength={5000}
                                    value={block.description}
                                    onChange={(e) =>
                                        set(i, "description", e.target.value)
                                    }
                                />
                            </Field>
                            <div className="program-actions">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={
                                        !i ||
                                        form.data.blocks[i - 1]?.editable ===
                                            false
                                    }
                                    onClick={() => move(i, -1)}
                                >
                                    Move up
                                </Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={
                                        i === form.data.blocks.length - 1 ||
                                        form.data.blocks[i + 1]?.editable ===
                                            false
                                    }
                                    onClick={() => move(i, 1)}
                                >
                                    Move down
                                </Button>
                                {block.id && (
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        onClick={() => add(block)}
                                    >
                                        Duplicate block
                                    </Button>
                                )}
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={form.data.blocks.length === 1}
                                    onClick={() =>
                                        form.setData(
                                            "blocks",
                                            form.data.blocks.filter(
                                                (_, j) => j !== i,
                                            ),
                                        )
                                    }
                                >
                                    Remove block
                                </Button>
                            </div>
                            {block.copy_from && (
                                <p className="field-hint">
                                    Workouts, prescriptions and images will be
                                    copied independently.
                                </p>
                            )}
                        </fieldset>
                    ))}
                </div>
                <Button type="button" variant="secondary" onClick={() => add()}>
                    Add block
                </Button>
                <Errors errors={form.errors} />
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button disabled={form.processing}>Save blocks</Button>
                </div>
            </form>
        </Modal>
    );
}
