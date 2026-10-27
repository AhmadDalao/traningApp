import { Head, useForm, router } from "@inertiajs/react";
import { CalendarPlus, RefreshCw } from "lucide-react";
import { PageHeader, Breadcrumbs, Button, ButtonLink, TypeBadge, TimeLabel, EmptyState } from "../../Components/UI";

export default function Schedule({ plan, preview }) {
    const form = useForm({ signature: preview.signature });
    return (
        <>
            <Head title={`Schedule ${plan.name}`} />
            <Breadcrumbs items={[{ label: "Training plans", href: "/training-plans" }, { label: plan.name, href: `/training-plans/${plan.id}` }, { label: "Preview schedule" }]} />
            <PageHeader title="Preview your plan schedule" description={plan.name}>
                <ButtonLink href={`/training-plans/${plan.id}`} variant="secondary">Back to plan</ButtonLink>
            </PageHeader>
            <section className="panel plan-schedule-preview">
                <div className="panel-heading">
                    <div>
                        <h2>{preview.new_count} sessions to add</h2>
                        <p>{plan.start_date && `${plan.start_date} to ${plan.end_date} · `}{preview.existing_count} already scheduled</p>
                    </div>
                    <Button variant="secondary" className="small" disabled={form.processing} onClick={() => router.reload()}><RefreshCw size={15} />Refresh preview</Button>
                </div>
                <p className="panel-padding field-hint">Review the dates below. Only today and future dates will be added. Existing sessions and completed history stay unchanged.</p>
                {(form.errors.schedule || form.errors.signature) && <p className="panel-padding field-error" role="alert">{form.errors.schedule || form.errors.signature}</p>}
                {preview.sessions.length ? (
                    <div className="plan-schedule-list" role="region" aria-label="Scheduled dates preview" tabIndex={0}>
                        {preview.sessions.map((session) => (
                            <div className="plan-schedule-row" key={session.key}>
                                <div><strong>{new Date(`${session.scheduled_date}T12:00:00`).toLocaleDateString("en-GB", { weekday: "short", day: "numeric", month: "short", year: "numeric" })}</strong><small>{session.scheduled_time ? <TimeLabel time={session.scheduled_time} /> : "No set time"}</small></div>
                                <div className="plan-schedule-title"><strong>{session.title}</strong><TypeBadge type={session.entry_type} />{session.existing_date && <small>Moved to {session.existing_date} in Calendar</small>}</div>
                                <span className={`badge ${session.existing ? "rest" : "training"}`}>{session.existing ? "Already scheduled" : "To add"}</span>
                            </div>
                        ))}
                    </div>
                ) : <EmptyState compact title="No sessions to add" description={preview.message} />}
                <form className="form-actions schedule-preview-actions" onSubmit={(event) => {
                    event.preventDefault();
                    form.transform(() => ({ signature: preview.signature }));
                    form.post(`/training-plans/${plan.id}/schedule`);
                }}>
                    <ButtonLink href={`/training-plans/${plan.id}`} variant="secondary">Cancel</ButtonLink>
                    <Button disabled={form.processing || !preview.new_count}><CalendarPlus size={16} />{form.processing ? "Adding sessions…" : `Add ${preview.new_count} sessions to Calendar`}</Button>
                </form>
            </section>
        </>
    );
}
