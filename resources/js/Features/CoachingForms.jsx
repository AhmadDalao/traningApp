import { useState } from "react";
import { useForm, usePage } from "@inertiajs/react";
import {
    Button,
    Field,
    Modal,
    DateLabel,
    ConfirmDialog,
} from "../Components/UI";
import { CoachStatus, Errors } from "./CoachingUI";
export function PackageForm({ item, close }) {
    const form = useForm({
        name: item?.name || "",
        description: item?.description || "",
        duration_days: item?.duration_days || "",
        active: item?.active ?? true,
    });
    return (
        <Modal
            title={item ? "Edit coaching package" : "Create coaching package"}
            onClose={close}
        >
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    item
                        ? form.put(`/coach/subscription-plans/${item.id}`, {
                              onSuccess: close,
                          })
                        : form.post("/coach/subscription-plans", {
                              onSuccess: close,
                          });
                }}
            >
                <Field label="Package name" required>
                    <input
                        required
                        maxLength={100}
                        value={form.data.name}
                        onChange={(e) => form.setData("name", e.target.value)}
                    />
                </Field>
                <Field label="Description">
                    <textarea
                        maxLength={2000}
                        value={form.data.description}
                        onChange={(e) =>
                            form.setData("description", e.target.value)
                        }
                    />
                </Field>
                <Field label="Duration in days (optional)">
                    <input
                        type="number"
                        min="1"
                        max="1095"
                        value={form.data.duration_days}
                        onChange={(e) =>
                            form.setData("duration_days", e.target.value)
                        }
                    />
                </Field>
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.active}
                        onChange={(e) =>
                            form.setData("active", e.target.checked)
                        }
                    />{" "}
                    Available for new subscriptions
                </label>
                <Errors errors={form.errors} />
                <div className="form-actions">
                    <Button variant="secondary" type="button" onClick={close}>
                        Cancel
                    </Button>
                    <Button disabled={form.processing}>Save package</Button>
                </div>
            </form>
        </Modal>
    );
}
export function StartSubscription({
    packages,
    relationships = [],
    close,
    relationship,
    endpoint = "/coach/subscriptions",
    onSaved = close,
    inline = false,
}) {
    const { today } = usePage().props;
    const form = useForm({
        relationship_id: relationship?.id || "",
        package_id: "",
        package_name: "",
        starts_on: today,
        ends_on: "",
        open_ended: false,
        notes: "",
    });
    return (
        <FormFrame inline={inline} close={close}>
            <form
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(endpoint, { onSuccess: onSaved });
                }}
            >
                {!relationship && (
                    <Field label="Connected trainee" required>
                        <select
                            required
                            value={form.data.relationship_id}
                            onChange={(e) =>
                                form.setData("relationship_id", e.target.value)
                            }
                        >
                            <option value="">Choose trainee</option>
                            {relationships.map((r) => (
                                <option key={r.id} value={r.id}>
                                    {r.trainee.name}
                                </option>
                            ))}
                        </select>
                    </Field>
                )}
                <Field label="Coaching plan">
                    <select
                        value={form.data.package_id}
                        onChange={(e) =>
                            form.setData("package_id", e.target.value)
                        }
                    >
                        <option value="">Custom coaching package</option>
                        {packages
                            .filter((p) => p.active)
                            .map((p) => (
                                <option key={p.id} value={p.id}>
                                    {p.name}
                                    {p.duration_days
                                        ? ` · ${p.duration_days} days`
                                        : ""}
                                </option>
                            ))}
                    </select>
                </Field>
                {!form.data.package_id && (
                    <Field label="Custom package name" required>
                        <input
                            required
                            maxLength={100}
                            value={form.data.package_name}
                            onChange={(e) =>
                                form.setData("package_name", e.target.value)
                            }
                        />
                    </Field>
                )}
                <div className="form-grid">
                    <Field label="Starts on" required>
                        <input
                            type="date"
                            required
                            value={form.data.starts_on}
                            onChange={(e) =>
                                form.setData("starts_on", e.target.value)
                            }
                        />
                    </Field>
                    <Field label="Ends on">
                        <input
                            type="date"
                            disabled={form.data.open_ended}
                            min={form.data.starts_on}
                            value={form.data.ends_on}
                            onChange={(e) =>
                                form.setData("ends_on", e.target.value)
                            }
                        />
                    </Field>
                </div>
                <p className="field-hint">
                    Leave the end date empty to use the package duration. Both
                    start and end dates are included.
                </p>
                <label className="checkbox">
                    <input
                        type="checkbox"
                        checked={form.data.open_ended}
                        onChange={(e) =>
                            form.setData("open_ended", e.target.checked)
                        }
                    />{" "}
                    Open-ended coaching
                </label>
                <Field label="Private administrative notes">
                    <textarea
                        value={form.data.notes}
                        maxLength={3000}
                        onChange={(e) => form.setData("notes", e.target.value)}
                    />
                </Field>
                <Errors errors={form.errors} />
                <div className="form-actions">
                    <Button type="button" variant="secondary" onClick={close}>
                        Cancel
                    </Button>
                    <Button disabled={form.processing}>
                        {inline ? "Save coaching & continue" : "Start coaching"}
                    </Button>
                </div>
            </form>
        </FormFrame>
    );
}
export function Subscription({ item }) {
    const form = useForm({ action: "pause" }),
        [ending, setEnding] = useState(false);
    return (
        <section className="panel coach-card">
            <div className="coach-card-title">
                <h2>{item.relationship.trainee.name}</h2>
                <CoachStatus status={item.effective_status} />
            </div>
            <strong>{item.package_name}</strong>
            <p>
                <DateLabel date={item.starts_on} /> —{" "}
                {item.ends_on ? (
                    <DateLabel date={item.ends_on} />
                ) : (
                    "Open-ended"
                )}
            </p>
            {item.notes && <p>Private notes: {item.notes}</p>}
            <Errors errors={form.errors} />
            {item.status !== "ended" &&
                item.relationship.status === "active" && (
                    <div className="coach-actions">
                        <Button
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => {
                                form.transform(() => ({
                                    action:
                                        item.status === "paused"
                                            ? "resume"
                                            : "pause",
                                }));
                                form.patch(`/coach/subscriptions/${item.id}`);
                            }}
                        >
                            {item.status === "paused" ? "Resume" : "Pause"}
                        </Button>
                        <Button
                            variant="secondary"
                            onClick={() => setEnding(true)}
                        >
                            End subscription
                        </Button>
                    </div>
                )}
            {ending && (
                <ConfirmDialog
                    title="End coaching subscription?"
                    description="The coach will have read-only access while the connection remains active. All plans and sessions are preserved."
                    url={`/coach/subscriptions/${item.id}`}
                    method="patch"
                    data={{ action: "end", confirmed: true }}
                    confirmLabel="End subscription"
                    onClose={() => setEnding(false)}
                />
            )}
        </section>
    );
}

function FormFrame({ inline, close, children }) {
    return inline ? (
        <section className="panel coach-form">{children}</section>
    ) : (
        <Modal title="Start coaching" onClose={close}>
            {children}
        </Modal>
    );
}
