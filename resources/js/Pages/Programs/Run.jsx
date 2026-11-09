import WeeklyScheduleEditor from "../../Features/WeeklyScheduleEditor";
import { WorkoutDate } from "../../Features/CoachWorkspace";
import TrainingThumbnail from "../../Components/TrainingThumbnail";
import ProgramControlModal from "../../Features/ProgramControlModal";
import ScheduleChangeModal from "../../Features/ScheduleChangeModal";
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
    programStatus,
    ProgramStats,
    ProgramWeeks,
    ProgramSessions,
    BlockTimeline,
} from "../../Features/ProgramUI";
export default function Run({ run, position, summary, scheduleDefinition }) {
    const { url, canManage, coaching } = useTraining();
    const [preview, setPreview] = useState(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState("");
    const [control, setControl] = useState(null);
    const [moving, setMoving] = useState(null);
    const Details = coaching ? "details" : "div";
    const lastScheduledDate = position.sessions
        .map((s) => s.scheduled_date)
        .sort()
        .at(-1);
    const definition = run.definition_snapshot,
        next = position.next_workout;
    function skip(entry) {
        router.patch(
            `/schedule-entries/${entry.id}/status`,
            { status: "skipped" },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
                onError: (errors) =>
                    setError(Object.values(errors).flat().join(" ")),
            },
        );
    }
    const recovery =
        run.status === "active" && !coaching
            ? { onReschedule: setMoving, onSkip: skip, busy }
            : {};
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
                await new Promise((resolve) =>
                    router.reload({ onFinish: resolve }),
                );
                setPreview(null);
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
                thumbnail={
                    <TrainingThumbnail
                        item={{ ...run, name: definition.name }}
                    />
                }
                eyebrow={
                    run.status === "completed"
                        ? "PROGRAM COMPLETE 🎉"
                        : run.status === "ended"
                          ? "PROGRAM ENDED EARLY"
                          : run.status === "paused"
                            ? "PROGRAM PAUSED"
                            : `WEEK ${position.current_week || definition.duration_weeks} OF ${definition.duration_weeks}`
                }
                title={definition.name}
                description={
                    position.current_block
                        ? `${programStatus(run.status)} · Week ${position.current_week} of ${position.duration_weeks} · ${position.current_block.name}`
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
                {["completed", "ended"].includes(run.status) &&
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
            {error && (
                <p className="field-error" role="alert">
                    {error}
                </p>
            )}
            {coaching && scheduleDefinition && (
                <section className="panel program-card coach-schedule-definition">
                    <div className="coach-card-title">
                        <h2>Edit Schedule</h2>
                        <Button
                            variant="secondary"
                            disabled={busy || run.status !== "active"}
                            onClick={() => changes()}
                        >
                            Review changes
                        </Button>
                    </div>
                    <p>
                        Started <DateLabel date={run.started_on} />
                        {lastScheduledDate && (
                            <>
                                {" "}
                                · Last workout{" "}
                                <DateLabel date={lastScheduledDate} />
                            </>
                        )}{" "}
                        · {definition.duration_weeks} weeks ·{" "}
                        {position.total_workouts} workouts
                    </p>
                    <p className="field-hint">
                        Current and completed blocks are protected. Edit future
                        weekly patterns, then review the affected dates. Use
                        Calendar to move an eligible individual workout.
                    </p>
                    {scheduleDefinition.blocks.map((block) => (
                        <details
                            key={block.id}
                            className="coach-secondary-details"
                            open={
                                block.id === position.current_block?.id ||
                                undefined
                            }
                        >
                            <summary>
                                {block.name} · Weeks {block.start_week}–
                                {block.end_week}
                                {!block.editable ? " · Protected" : ""}
                            </summary>
                            <WeeklyScheduleEditor
                                days={block.training_days}
                                editable={
                                    block.editable && run.status === "active"
                                }
                            />
                        </details>
                    ))}
                </section>
            )}
            {next &&
                !next.held &&
                ["active", "paused"].includes(run.status) && (
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
                                                onStart: () => {
                                                    setBusy(true);
                                                    setError("");
                                                },
                                                onError: (errors) =>
                                                    setError(
                                                        Object.values(errors)
                                                            .flat()
                                                            .join(" "),
                                                    ),
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
            <section className="panel program-card">
                <span className={`badge program-status-${run.status}`}>
                    {programStatus(run.status)}
                </span>
                {run.status === "paused" && (
                    <p>
                        Paused from <DateLabel date={run.paused_from} />
                        {run.resume_on && (
                            <>
                                {" "}
                                · Expected return{" "}
                                <DateLabel date={run.resume_on} />
                            </>
                        )}
                        . Workouts before the pause date remain available.
                        Resume explicitly when ready. Workouts on hold do not
                        become missed.
                    </p>
                )}
                {run.status === "ended" && (
                    <>
                        <p>
                            Ended at Week {position.current_week} of{" "}
                            {position.duration_weeks} ·{" "}
                            <DateLabel date={run.ended_at?.slice(0, 10)} />
                        </p>
                        <p>
                            {position.completed_workouts} completed ·{" "}
                            {position.skipped_workouts} skipped ·{" "}
                            {position.total_workouts -
                                position.completed_workouts -
                                position.skipped_workouts}{" "}
                            unresolved when ended. Your history and progress
                            remain.
                        </p>
                    </>
                )}
                {canManage && ["active", "paused"].includes(run.status) && (
                    <div className="program-actions">
                        <Button
                            variant={
                                run.status === "paused"
                                    ? "primary"
                                    : "secondary"
                            }
                            onClick={() =>
                                setControl(
                                    run.status === "paused"
                                        ? "resume"
                                        : "pause",
                                )
                            }
                        >
                            {run.status === "paused"
                                ? "Resume program"
                                : "Pause program"}
                        </Button>
                        {run.status === "active" && (
                            <Button
                                variant="secondary"
                                onClick={() => setControl("shift")}
                            >
                                Adjust remaining schedule
                            </Button>
                        )}
                        <Button
                            variant="secondary"
                            className="danger-text program-end"
                            onClick={() => setControl("end")}
                        >
                            End program
                        </Button>
                    </div>
                )}
            </section>
            {control && (
                <ProgramControlModal
                    run={run}
                    position={position}
                    action={control}
                    onClose={() => setControl(null)}
                />
            )}
            {moving && (
                <ScheduleChangeModal
                    entry={moving}
                    onClose={() => setMoving(null)}
                />
            )}
            <Details className={coaching ? "program-run-details" : undefined}>
                {coaching && (
                    <summary>Program progress & all scheduled weeks</summary>
                )}
                {position.current_week && (
                    <section className="panel program-card">
                        <h2>This program week</h2>
                        <ProgramSessions
                            {...recovery}
                            sessions={position.sessions.filter(
                                (row) =>
                                    row.program_week === position.current_week,
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
                {!coaching &&
                    canManage &&
                    run.status === "active" &&
                    run.training_plan_id && (
                        <section className="panel program-card">
                            <h2>Update future sessions</h2>
                            <p>
                                After editing the definition, review updates,
                                additions and removals. Completed, skipped,
                                active, past and individually moved sessions
                                stay protected.
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
                <section>
                    <h2>All program weeks</h2>
                    <ProgramWeeks
                        {...recovery}
                        sessions={position.sessions}
                        initialWeek={position.current_week}
                    />
                </section>
            </Details>
            {preview && (
                <Modal
                    title="Review future program changes"
                    onClose={() => setPreview(null)}
                >
                    {coaching ? (
                        <>
                            <p>Review the proposed Calendar changes.</p>
                            <div className="schedule-human-summary">
                                <span>
                                    <strong>{preview.keep.length}</strong>{" "}
                                    unchanged
                                </span>
                                <span>
                                    <strong>{preview.update.length}</strong>{" "}
                                    updated
                                </span>
                                <span>
                                    <strong>{preview.add.length}</strong> added
                                </span>
                                <span>
                                    <strong>{preview.remove.length}</strong>{" "}
                                    removed
                                </span>
                                <span>
                                    <strong>{preview.protected.length}</strong>{" "}
                                    protected, untouched
                                </span>
                            </div>
                            {preview.update.map((row) => {
                                const before = position.sessions.find(
                                    (s) => s.id === row.id,
                                );
                                return (
                                    <article
                                        className="coach-session"
                                        key={row.id}
                                    >
                                        <div className="coach-session-copy">
                                            <strong>{row.title}</strong>
                                            {before && (
                                                <small>
                                                    <WorkoutDate
                                                        entry={before}
                                                    />{" "}
                                                    →
                                                </small>
                                            )}
                                            <small>
                                                <WorkoutDate entry={row} />
                                            </small>
                                        </div>
                                    </article>
                                );
                            })}
                            {preview.add.length > 0 && (
                                <>
                                    <h3>New dates</h3>
                                    <ProgramSessions sessions={preview.add} />
                                </>
                            )}
                            {preview.remove.length > 0 && (
                                <>
                                    <h3>Dates to remove</h3>
                                    <ProgramSessions
                                        sessions={preview.remove}
                                    />
                                </>
                            )}
                        </>
                    ) : (
                        <p>Only eligible future generated sessions change.</p>
                    )}
                    <details
                        className={coaching ? "schedule-advanced" : undefined}
                        open={coaching ? undefined : true}
                    >
                        <summary hidden={!coaching}>
                            Advanced schedule details
                        </summary>
                        <div className="program-stack">
                            {[
                                "update",
                                "add",
                                "remove",
                                "keep",
                                "protected",
                            ].map((group) => (
                                <details key={group} className="program-day">
                                    <summary>
                                        {group.toUpperCase()} ·{" "}
                                        {preview[group].length}
                                    </summary>
                                    <ProgramSessions
                                        sessions={preview[group]}
                                    />
                                </details>
                            ))}
                        </div>
                    </details>
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
