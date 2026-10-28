import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, useForm, router } from "@inertiajs/react";
import { CalendarPlus, RefreshCw, Trash2 } from "lucide-react";
import {
    PageHeader,
    Breadcrumbs,
    Button,
    ButtonLink,
    TypeBadge,
    TimeLabel,
    EmptyState,
    Modal,
    DateLabel,
} from "../../Components/UI";
import { weekdaySummary } from "../../Components/WeekdayPicker";

function Sessions({ rows, label }) {
    if (!rows.length)
        return (
            <EmptyState
                compact
                title="No sessions in this group"
                description="There are no dates affected here."
            />
        );
    return (
        <div
            className="plan-schedule-list"
            role="region"
            aria-label={label}
            tabIndex={0}
        >
            {rows.map((session) => (
                <div
                    className="plan-schedule-row"
                    key={session.key || session.id}
                >
                    <div>
                        <strong>
                            <DateLabel date={session.scheduled_date} long />
                        </strong>
                        <small>
                            {session.scheduled_time ? (
                                <TimeLabel time={session.scheduled_time} />
                            ) : (
                                "No set time"
                            )}
                        </small>
                    </div>
                    <div className="plan-schedule-title">
                        <strong>{session.title}</strong>
                        <TypeBadge type={session.entry_type} />
                        {session.reason && <small>{session.reason}</small>}
                        {session.replaces_id && (
                            <small>
                                Replaces an eligible session at its new time or
                                day type. Requires regeneration with removal
                                selected.
                            </small>
                        )}
                    </div>
                </div>
            ))}
        </div>
    );
}
export default function Schedule({ plan, preview }) {
    const { url } = useTraining();
    const [group, setGroup] = useState("add");
    const [action, setAction] = useState(null);
    const [removeObsolete, setRemoveObsolete] = useState(false);
    const form = useForm({});
    const groups = [
        { key: "add", name: "Add" },
        { key: "keep", name: "Keep" },
        { key: "remove", name: "Remove" },
        { key: "protected", name: "Protected" },
        ...(preview.excluded?.length
            ? [{ key: "excluded", name: "Outside service dates" }]
            : []),
    ];
    const adding =
        action === "remove"
            ? []
            : preview.add.filter(
                  (row) =>
                      !row.replaces_id ||
                      (action === "regenerate" && removeObsolete),
              );
    const removing =
        action === "remove"
            ? preview.eligible
            : action === "regenerate" && removeObsolete
              ? preview.remove
              : [];
    const actionTitle =
        action === "add"
            ? "Add missing sessions"
            : action === "remove"
              ? "Remove future generated sessions"
              : "Regenerate future schedule";
    return (
        <>
            <Head title={`Manage schedule · ${plan.name}`} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: url("/training-plans") },
                    {
                        label: plan.name,
                        href: url(`/training-plans/${plan.id}`),
                    },
                    { label: "Manage schedule" },
                ]}
            />
            <PageHeader title="Manage schedule" description={plan.name}>
                <ButtonLink
                    href={url(`/training-plans/${plan.id}`)}
                    variant="secondary"
                >
                    Back to plan
                </ButtonLink>
            </PageHeader>
            <section className="panel schedule-settings panel-padding">
                <h2>Plan period</h2>
                <p>
                    {plan.start_date && plan.end_date ? (
                        <>
                            <DateLabel date={plan.start_date} long /> –{" "}
                            <DateLabel date={plan.end_date} long />
                        </>
                    ) : (
                        "No plan period set"
                    )}
                </p>
                <div className="schedule-days">
                    {plan.training_days.map((day) => (
                        <div key={day.id}>
                            <strong>{day.title}</strong>
                            <span>
                                {weekdaySummary(day.weekdays || [])}
                                {day.default_time && (
                                    <>
                                        {" "}
                                        · <TimeLabel time={day.default_time} />
                                    </>
                                )}
                            </span>
                        </div>
                    ))}
                </div>
                <p className="field-hint">
                    Calendar changes happen only after you review and confirm.
                    Today’s timed sessions qualify only while their time is
                    still ahead; today’s untimed sessions are kept.
                </p>
            </section>
            <section className="panel plan-schedule-preview">
                <div className="panel-heading">
                    <div>
                        <h2>Schedule changes</h2>
                        <p>
                            Review the dates in each group before choosing an
                            action.
                        </p>
                    </div>
                    <Button
                        variant="secondary"
                        className="small"
                        disabled={form.processing}
                        onClick={() => {
                            setAction(null);
                            router.reload();
                        }}
                    >
                        <RefreshCw size={15} />
                        Preview schedule
                    </Button>
                </div>
                {(form.errors.schedule ||
                    form.errors.signature ||
                    form.errors.confirmed) && (
                    <p className="panel-padding field-error" role="alert">
                        {form.errors.schedule ||
                            form.errors.signature ||
                            form.errors.confirmed}
                    </p>
                )}
                {preview.message && (
                    <p className="panel-padding field-hint">
                        {preview.message}
                    </p>
                )}
                <div
                    className="schedule-diff-tabs"
                    aria-label="Schedule change categories"
                >
                    {groups.map(({ key, name }) => (
                        <button
                            type="button"
                            key={key}
                            aria-pressed={group === key}
                            onClick={() => setGroup(key)}
                            className={group === key ? "active" : ""}
                        >
                            <strong>{preview[key].length}</strong>
                            <span>{name}</span>
                        </button>
                    ))}
                </div>
                <p className="panel-padding field-hint">
                    {group === "add"
                        ? `${preview.new_count} missing sessions${preview.replacement_count ? ` and ${preview.replacement_count} replacements` : ""}.`
                        : group === "keep"
                          ? "These future generated sessions already match and will stay unchanged."
                          : group === "remove"
                            ? "These eligible generated sessions no longer match the plan. Nothing is removed until you confirm."
                            : "Completed, skipped, active, saved, historical and independent sessions stay untouched. Older generated sessions are kept because their individual edit history is unknown."}
                </p>
                <Sessions
                    rows={preview[group]}
                    label={`${group} sessions preview`}
                />
                <div className="schedule-manager-actions">
                    <Button
                        disabled={!preview.new_count}
                        onClick={() => setAction("add")}
                    >
                        <CalendarPlus size={16} />
                        Add missing sessions
                    </Button>
                    <Button
                        variant="secondary"
                        disabled={!preview.add.length && !preview.remove.length}
                        onClick={() => {
                            setRemoveObsolete(false);
                            setAction("regenerate");
                        }}
                    >
                        <RefreshCw size={16} />
                        Regenerate future schedule
                    </Button>
                    <Button
                        variant="secondary"
                        disabled={!preview.eligible.length}
                        onClick={() => setAction("remove")}
                    >
                        <Trash2 size={16} />
                        Remove future sessions
                    </Button>
                </div>
            </section>
            {action && (
                <Modal
                    title={actionTitle}
                    onClose={() => !form.processing && setAction(null)}
                    wide
                >
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.transform(() => ({
                                action,
                                signature: preview.signature,
                                remove_obsolete: removeObsolete,
                                confirmed: true,
                            }));
                            form.post(
                                url(`/training-plans/${plan.id}/schedule`),
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setAction(null),
                                    onError: () => setAction(null),
                                },
                            );
                        }}
                    >
                        {action === "regenerate" && (
                            <label className="schedule-remove-choice">
                                <input
                                    type="checkbox"
                                    checked={removeObsolete}
                                    onChange={(event) =>
                                        setRemoveObsolete(event.target.checked)
                                    }
                                />
                                Also remove obsolete eligible generated sessions
                            </label>
                        )}
                        <p className="copy-description">
                            {adding.length} to add · {removing.length} to
                            remove. {preview.protected.length} protected
                            sessions stay unchanged.
                        </p>
                        {action === "remove" && (
                            <p className="field-hint">
                                This removes all eligible future generated
                                sessions listed below, including those that
                                still match the plan. Your plan preferences
                                remain available for scheduling again.
                            </p>
                        )}
                        {!!adding.length && (
                            <>
                                <h3>Dates to add</h3>
                                <Sessions
                                    rows={adding}
                                    label="Confirm dates to add"
                                />
                            </>
                        )}
                        {!!removing.length && (
                            <>
                                <h3>Dates to remove</h3>
                                <Sessions
                                    rows={removing}
                                    label="Confirm dates to remove"
                                />
                            </>
                        )}
                        {action === "regenerate" &&
                            !removeObsolete &&
                            preview.replacement_count > 0 && (
                                <p className="field-hint">
                                    Time changes need the removal option.
                                    Existing occurrences will otherwise remain
                                    unchanged.
                                </p>
                            )}
                        <div className="form-actions">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                                onClick={() => setAction(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                variant={removing.length ? "danger" : "primary"}
                                disabled={
                                    form.processing ||
                                    (!adding.length && !removing.length)
                                }
                            >
                                {form.processing
                                    ? "Saving…"
                                    : "Confirm schedule changes"}
                            </Button>
                        </div>
                    </form>
                </Modal>
            )}
        </>
    );
}
