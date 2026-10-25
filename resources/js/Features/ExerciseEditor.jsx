import { useEffect, useState } from "react";
import { ImagePlus, X } from "lucide-react";
import { Modal, Field, Button } from "../Components/UI";
export function Thumbnail({ exercise, className = "exercise-thumb" }) {
    const [preview, setPreview] = useState(null);
    useEffect(() => {
        if (!exercise.thumbnail) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(exercise.thumbnail);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [exercise.thumbnail]);
    const url =
        preview || (!exercise.remove_thumbnail && exercise.thumbnail_url);
    return url ? (
        <img
            className={className}
            src={url}
            alt={exercise.name || "Exercise preview"}
        />
    ) : (
        <div className={`${className} placeholder`}>
            <ImagePlus size={24} />
        </div>
    );
}
export default function ExerciseEditor({ exercise, onSave, onClose }) {
    const [draft, setDraft] = useState(
        exercise || {
            id: null,
            name: "",
            sets: 3,
            reps: "",
            superset_group: "",
            notes: "",
            thumbnail: null,
            thumbnail_url: null,
            remove_thumbnail: false,
        },
    );
    const [fileError, setFileError] = useState("");
    const update = (key, value) => setDraft((d) => ({ ...d, [key]: value }));
    return (
        <Modal
            title={exercise ? "Edit exercise" : "Add exercise"}
            description="Keep it simple. Make every movement count."
            onClose={onClose}
            wide
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!fileError) onSave(draft);
                }}
            >
                <div className="exercise-editor-grid">
                    <div>
                        <Field label="Exercise name" required>
                            <input
                                autoFocus
                                required
                                maxLength={150}
                                value={draft.name}
                                onChange={(e) => update("name", e.target.value)}
                                placeholder="e.g. Back Squat"
                            />
                        </Field>
                        <div className="form-grid">
                            <Field label="Sets" required>
                                <input
                                    type="number"
                                    required
                                    min={1}
                                    max={99}
                                    value={draft.sets}
                                    onChange={(e) =>
                                        update("sets", e.target.value)
                                    }
                                />
                            </Field>
                            <Field label="Reps / duration" required>
                                <input
                                    required
                                    maxLength={100}
                                    value={draft.reps}
                                    onChange={(e) =>
                                        update("reps", e.target.value)
                                    }
                                    placeholder="8–12 or 30 seconds"
                                />
                            </Field>
                        </div>
                        <Field
                            label="Superset group"
                            hint="Use the same letter to link exercises, e.g. A, B, C."
                        >
                            <input
                                maxLength={10}
                                pattern="[A-Za-z0-9]+"
                                value={draft.superset_group || ""}
                                onChange={(e) =>
                                    update(
                                        "superset_group",
                                        e.target.value.toUpperCase(),
                                    )
                                }
                                placeholder="e.g. A (optional)"
                            />
                        </Field>
                        <Field label="Notes">
                            <textarea
                                maxLength={2000}
                                rows={3}
                                value={draft.notes || ""}
                                onChange={(e) =>
                                    update("notes", e.target.value)
                                }
                                placeholder="Form cues, tempo, or anything useful"
                            />
                        </Field>
                    </div>
                    <div className="thumbnail-editor">
                        <span className="field-label">Exercise thumbnail</span>
                        <Thumbnail
                            exercise={draft}
                            className="thumbnail-preview"
                        />
                        <label className="btn btn-secondary small file-picker">
                            <ImagePlus size={15} />
                            Choose image
                            <input
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                onChange={(e) => {
                                    const file = e.target.files[0];
                                    if (!file) return;
                                    if (
                                        ![
                                            "image/jpeg",
                                            "image/png",
                                            "image/webp",
                                        ].includes(file.type) ||
                                        file.size > 2 * 1024 * 1024
                                    ) {
                                        setFileError(
                                            "Use a JPG, PNG, or WebP image under 2 MB.",
                                        );
                                        e.target.value = "";
                                        return;
                                    }
                                    setFileError("");
                                    setDraft((d) => ({
                                        ...d,
                                        thumbnail: file,
                                        remove_thumbnail: false,
                                    }));
                                }}
                            />
                        </label>
                        <span className="field-hint">
                            JPG, PNG, WebP · Max 2 MB
                        </span>
                        {fileError && (
                            <p className="field-error" role="alert">
                                {fileError}
                            </p>
                        )}
                        {(draft.thumbnail ||
                            (draft.thumbnail_url &&
                                !draft.remove_thumbnail)) && (
                            <button
                                type="button"
                                className="text-button danger-text"
                                onClick={() => {
                                    setFileError("");
                                    setDraft((d) => ({
                                        ...d,
                                        thumbnail: null,
                                        remove_thumbnail: true,
                                    }));
                                }}
                            >
                                <X size={13} />
                                Remove image
                            </button>
                        )}
                    </div>
                </div>
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button>
                        {exercise ? "Save exercise" : "Add exercise"}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
