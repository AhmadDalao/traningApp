import { useState } from "react";
import { Head, router } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Breadcrumbs,
    DateLabel,
    Modal,
} from "../../Components/UI";
import { useTraining, coachingJson } from "../../Features/CoachingUI";
import {
    ProgramStats,
    ProgramWeeks,
    ProgramSessions,
    BlockTimeline,
} from "../../Features/ProgramUI";
export default function Run({ run, position, summary }) {
    const { url, canManage, coaching } = useTraining();
    const [preview, setPreview] = useState(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    const definition = run.definition_snapshot,
        next = position.next_workout;
    async function changes(apply = false) {
        setBusy(true);
        setError("");
        try {
            const result = await coachingJson(
                url(
                    `/program-runs/${run.id}/${apply ? "changes" : "change-preview"}`,
                ),
                apply ? { signature: preview.signature, confirmed: true } : {},
            );
            if (apply) {
                setPreview(null);
                router.reload();
            } else setPreview(result.preview);
        } catch (e) {
            setError(e.message);
            setPreview(null);
        } finally {
            setBusy(false);
        }
    }
    return (
        <div className="program-page">
            <Head title={definition.name} />
            <Breadcrumbs
                items={[
                    { label: "Program runs", href: url("/program-runs") },
                    { label: definition.name },
                ]}
            />
            <PageHeader
                eyebrow={
                    run.status === "completed"
                        ? "PROGRAM COMPLETE 🎉"
                        : `WEEK ${position.current_week || definition.duration_weeks} OF ${definition.duration_weeks}`
                }
                title={definition.name}
                description={
                    position.current_block
                        ? `${position.current_block.name} · Weeks ${position.current_block.start_week}–${position.current_block.end_week}`
                        : "Your completed training journey"
                }
            >
                {run.training_plan_id && (
                    <ButtonLink
                        variant="secondary"
                        href={url(`/training-plans/${run.training_plan_id}`)}
                    >
                        Program definition
                    </ButtonLink>
                )}
                {run.status === "completed" &&
                    canManage &&
                    run.training_plan_id && (
                        <ButtonLink
                            href={url(
                                `/training-plans/${run.training_plan_id}/program-start`,
                            )}
                        >
                            Repeat program
                        </ButtonLink>
                    )}
            </PageHeader>
            {next && (
                <section className="panel program-card program-next">
                    <small>
                        NEXT UNRESOLVED WORKOUT · WEEK {next.program_week}
                    </small>
                    <h2>{next.title}</h2>
                    <p>
                        <DateLabel date={next.scheduled_date} />{" "}
                        {next.scheduled_time?.slice(0, 5)}
                    </p>
                    <div className="program-actions">
                        <ButtonLink
                            variant="secondary"
                            href={url(`/schedule-entries/${next.id}`)}
                        >
                            View session
                        </ButtonLink>
                        {!coaching && (
                            <Button
                                disabled={busy}
                                onClick={() =>
                                    router.post(
                                        `/schedule-entries/${next.id}/workout/start`,
                                        {},
                                        {
                                            onStart: () => setBusy(true),
                                            onFinish: () => setBusy(false),
                                        },
                                    )
                                }
                            >
                                {next.started_at
                                    ? "Resume workout"
                                    : "Start workout"}
                            </Button>
                        )}
                    </div>
                </section>
            )}
            {position.current_week && (
                <section className="panel program-card">
                    <h2>This program week</h2>
                    <ProgramSessions
                        sessions={position.sessions.filter(
                            (row) => row.program_week === position.current_week,
                        )}
                    />
                </section>
            )}
            <ProgramStats position={position} />
            {summary && (
                <section className="panel program-card">
                    <h2>Your program summary</h2>
                    <p>
                        {position.completed_workouts} /{" "}
                        {position.total_workouts} workouts completed ·{" "}
                        {position.skipped_workouts} skipped
                    </p>
                    <p>
                        {summary.pr_count
                            ? `${summary.pr_count} new personal records`
                            : "No new PRs recorded"}
                    </p>
                    {summary.weight_gains.map((gain, i) => (
                        <p key={i}>
                            <a href={gain.url}>{gain.name}</a>:{" "}
                            {gain.change_kg > 0 ? "+" : ""}
                            {gain.change_kg} kg · first to last weighted
                            performance in this run
                        </p>
                    ))}
                    {summary.body_weight_change !== null && (
                        <p>
                            Body weight change:{" "}
                            {summary.body_weight_change > 0 ? "+" : ""}
                            {summary.body_weight_change} kg
                        </p>
                    )}
                    <ButtonLink href="/recommendations" variant="secondary">
                        Find next program
                    </ButtonLink>
                </section>
            )}
            <section>
                <h2>Block timeline</h2>
                <BlockTimeline
                    blocks={definition.blocks}
                    currentWeek={position.current_week}
                />
            </section>
            {canManage && run.status === "active" && run.training_plan_id && (
                <section className="panel program-card">
                    <h2>Update future sessions</h2>
                    <p>
                        After editing the definition, review updates, additions
                        and removals. Completed, skipped, active, past and
                        individually moved sessions stay protected.
                    </p>
                    <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => changes()}
                    >
                        Review program changes
                    </Button>
                </section>
            )}
            {error && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
            <section>
                <h2>All program weeks</h2>
                <ProgramWeeks
                    sessions={position.sessions}
                    initialWeek={position.current_week}
                />
            </section>
            {preview && (
                <Modal
                    title="Review future program changes"
                    onClose={() => setPreview(null)}
                >
                    <p>Only eligible future generated sessions change.</p>
                    <div className="program-stack">
                        {["update", "add", "remove", "keep", "protected"].map(
                            (group) => (
                                <details key={group} className="program-day">
                                    <summary>
                                        {group.toUpperCase()} ·{" "}
                                        {preview[group].length}
                                    </summary>
                                    <ProgramSessions
                                        sessions={preview[group]}
                                    />
                                </details>
                            ),
                        )}
                    </div>
                    <div className="form-actions">
                        <Button
                            variant="secondary"
                            onClick={() => setPreview(null)}
                        >
                            Cancel
                        </Button>
                        <Button disabled={busy} onClick={() => changes(true)}>
                            Confirm changes
                        </Button>
                    </div>
                </Modal>
            )}
        </div>
    );
}
