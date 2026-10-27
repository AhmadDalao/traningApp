import { useEffect, useState } from "react";
import { ImagePlus, Library, X } from "lucide-react";
import { Modal, Field, Button, formatWeight } from "../Components/UI";
import IconPicker from "../Components/IconPicker";
import ExerciseIcon from "../Components/ExerciseIcon";
import ExerciseLibraryPicker from "./ExerciseLibraryPicker";
import { supersetPartners } from "./supersets";
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
            <ExerciseIcon exercise={exercise} size={26} />
        </div>
    );
}
export default function ExerciseEditor({
    exercise,
    exercises,
    onSave,
    onClose,
    customExercises = [],
    partnerFor = null,
}) {
    const [choosing, setChoosing] = useState(!exercise);
    const [draft, setDraft] = useState(
        exercise || {
            id: null,
            localKey: crypto.randomUUID(),
            icon: "",
            name: "",
            sets: 3,
            reps: "",
            target_weight: "",
            superset_group: "",
            notes: "",
            thumbnail: null,
            thumbnail_url: null,
            remove_thumbnail: false,
            library_key: null,
            save_to_library: false,
            custom_icon: null,
            custom_icon_url: null,
            remove_custom_icon: false,
        },
    );
    const [partnerKey, setPartnerKey] = useState(() =>
        exercise
            ? String(supersetPartners(exercise, exercises)[0]?.localKey ?? "")
            : "",
    );
    const [libraryPartner, setLibraryPartner] = useState(null);
    const [partnerEditor, setPartnerEditor] = useState(null);
    const partners = [...exercises, ...(libraryPartner ? [libraryPartner] : [])].filter(
        (item) => item.localKey !== draft.localKey,
    );
    const selectedPartner = partners.find(
        (item) => String(item.localKey) === partnerKey,
    );
    const addingLibraryPartner = libraryPartner && partnerKey === String(libraryPartner.localKey);
    const linkedNames = selectedPartner
        ? [
              selectedPartner,
              ...supersetPartners(selectedPartner, exercises).filter(
                  (item) => item.localKey !== draft.localKey,
              ),
          ].map((item) => item.name)
        : [];
    const [fileError, setFileError] = useState("");
    const [iconError, setIconError] = useState("");
    const update = (key, value) => setDraft((d) => ({ ...d, [key]: value }));
    function selectExercise(item) {
        setDraft((current) => ({
            ...current, library_key: item?.key || null, name: item?.name || "",
            icon: item?.icon || "dumbbell", thumbnail: null, thumbnail_url: item?.thumbnail_url || null,
            custom_icon: null, custom_icon_url: item?.custom_icon_url || null,
            remove_thumbnail: false, remove_custom_icon: false, save_to_library: !item,
        }));
        setFileError("");
        setIconError("");
        setChoosing(false);
    }
    if (partnerEditor) {
        return <ExerciseEditor
            exercise={partnerEditor.exercise}
            exercises={[]}
            customExercises={customExercises}
            partnerFor={draft.name || "your exercise"}
            onClose={() => setPartnerEditor(null)}
            onSave={(partner) => {
                setLibraryPartner(partner);
                setPartnerKey(String(partner.localKey));
                setPartnerEditor(null);
            }}
        />;
    }
    return (
        <Modal
            title={partnerFor ? "Superset partner" : exercise ? "Edit exercise" : "Add exercise"}
            description={partnerFor ? `Pair with ${partnerFor}. Choose an exercise and set its own sets, reps or duration, and weight.` : choosing ? "Choose a movement, then set your weight, sets, and reps or duration." : "Keep it simple. Make every movement count."}
            onClose={onClose}
            wide
        >
            {choosing ? <ExerciseLibraryPicker customExercises={customExercises} selected={draft.library_key}
                onSelect={selectExercise} onCustom={() => selectExercise(null)} /> : <form
                onSubmit={(e) => {
                    e.preventDefault();
                    if (!fileError && !iconError) onSave(draft, partnerKey, addingLibraryPartner ? libraryPartner : null);
                }}
            >
                <div className="exercise-editor-grid">
                    <div>
                        <Button type="button" variant="secondary" className="small library-change" onClick={() => setChoosing(true)}>
                            Choose from exercise library
                        </Button>
                        <Field label="Exercise name" required>
                            <input
                                autoFocus
                                readOnly={!!draft.library_key}
                                required
                                maxLength={150}
                                value={draft.name}
                                onChange={(e) => update("name", e.target.value)}
                                placeholder="e.g. Back Squat"
                            />
                        </Field>
                        {draft.save_to_library && <p className="field-hint custom-library-note">This custom exercise will be added to your private library when you save the training day.</p>}
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
                            label="Planned Weight (Optional)"
                            hint="Leave empty for exercises without a weight."
                        >
                            <div className="input-with-unit">
                                <input
                                    type="number"
                                    inputMode="decimal"
                                    min="0"
                                    max="9999.99"
                                    step="0.01"
                                    value={draft.target_weight ?? ""}
                                    onChange={(event) =>
                                        update(
                                            "target_weight",
                                            event.target.value,
                                        )
                                    }
                                    placeholder="e.g. 80"
                                />
                                <span aria-hidden="true">kg</span>
                            </div>
                        </Field>
                        {!partnerFor && <>
                        <Field
                            label="Superset with"
                            hint={
                                addingLibraryPartner
                                    ? "Your library partner will be added with this exercise."
                                    : partners.length
                                    ? "Choose another exercise in this workout to perform together."
                                    : "Choose a partner directly from the library below."
                            }
                        >
                            <select
                                value={partnerKey}
                                disabled={!partners.length}
                                onChange={(event) =>
                                    setPartnerKey(event.target.value)
                                }
                            >
                                <option value="">
                                    No superset — do on its own
                                </option>
                                {partners.map((item) => (
                                    <option
                                        key={item.localKey}
                                        value={String(item.localKey)}
                                    >
                                        {item === libraryPartner ? "New · " : `${exercises.indexOf(item) + 1}. `}
                                        {item.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <Button type="button" variant="secondary" className="small superset-library-link" onClick={() => setPartnerEditor({})}>
                            <Library size={15} />Choose partner from library
                        </Button>
                        {addingLibraryPartner ? (
                            <div className="superset-partner-card">
                                <div className="superset-partner-summary">
                                    <Thumbnail exercise={libraryPartner} className="superset-partner-thumb" />
                                    <div><strong>{libraryPartner.name}</strong><p>{libraryPartner.sets} sets × {libraryPartner.reps}{libraryPartner.target_weight !== "" && libraryPartner.target_weight != null ? ` · ${formatWeight(libraryPartner.target_weight)}` : ""}</p></div>
                                </div>
                                <p>Both exercises will be linked when you save below.</p>
                                <div className="superset-partner-actions">
                                    <Button type="button" variant="secondary" className="small" onClick={() => setPartnerEditor({ exercise: libraryPartner })}>Edit partner</Button>
                                    <button type="button" className="text-button danger-text" onClick={() => { setLibraryPartner(null); setPartnerKey(""); }}>Remove partner</button>
                                </div>
                            </div>
                        ) : linkedNames.length > 0 && (
                            <p className="superset-preview">
                                Linked with{" "}
                                <strong>{linkedNames.join(" + ")}</strong>.
                                These exercises are updated together when you
                                save this training day.
                            </p>
                        )}
                        </>}
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
                    <div className="exercise-visuals">
                        <IconPicker
                            label="Exercise icon"
                            value={draft.icon}
                            onChange={(value) => {
                                setIconError("");
                                setDraft((current) => ({ ...current, icon: value, custom_icon: null, remove_custom_icon: true }));
                            }}
                        />
                        <div className="custom-icon-editor">
                            <div className="custom-icon-preview"><ExerciseIcon exercise={draft} size={40} /><span>Exercise icon preview</span></div>
                            <label className="btn btn-secondary small file-picker">
                                <ImagePlus size={15} />Upload custom icon
                                <input type="file" accept="image/jpeg,image/png,image/webp" onChange={async (event) => {
                                    const file = event.target.files[0];
                                    if (!file) return;
                                    if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || file.size > 512 * 1024) {
                                        setIconError("Use a square JPG, PNG, or WebP icon under 512 KB.");
                                        return;
                                    }
                                    try {
                                        const bitmap = await createImageBitmap(file);
                                        const valid = bitmap.width === bitmap.height && bitmap.width >= 64 && bitmap.width <= 512;
                                        bitmap.close();
                                        if (!valid) { setIconError("Icons must be square, between 64 × 64 and 512 × 512 pixels."); return; }
                                        setIconError("");
                                        setDraft((current) => ({ ...current, custom_icon: file, remove_custom_icon: false }));
                                    } catch { setIconError("This image could not be opened. Choose another icon."); }
                                }} />
                            </label>
                            <p className="field-hint">128 × 128 px recommended. Square, 64–512 px · Max 512 KB.</p>
                            {iconError && <p className="field-error" role="alert">{iconError}</p>}
                            {(draft.custom_icon || (draft.custom_icon_url && !draft.remove_custom_icon)) && <button type="button" className="text-button" onClick={() => {
                                setIconError("");
                                setDraft((current) => ({ ...current, custom_icon: null, remove_custom_icon: true }));
                            }}>Use selected built-in icon</button>}
                        </div>
                        <div className="thumbnail-editor">
                            <span className="field-label">
                                Exercise thumbnail
                            </span>
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
                </div>
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={onClose}>
                        {partnerFor ? "Back to exercise" : "Cancel"}
                    </Button>
                    <Button>
                        {partnerFor ? "Use this partner" : addingLibraryPartner ? (exercise ? "Save linked exercises" : "Add linked exercises") : exercise ? "Save exercise" : "Add exercise"}
                    </Button>
                </div>
            </form>}
            {choosing && partnerFor && <div className="form-actions"><Button type="button" variant="secondary" onClick={onClose}>Back to exercise</Button></div>}
        </Modal>
    );
}
