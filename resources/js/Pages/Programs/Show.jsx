import { useState } from "react";
import { Head, Link } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Breadcrumbs,
    Modal,
    ConfirmDialog,
} from "../../Components/UI";
import { useTraining } from "../../Features/CoachingUI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
import ProgramBlockEditor from "../../Features/ProgramBlockEditor";
import { BlockTimeline } from "../../Features/ProgramUI";
import { weekdaySummary } from "../../Components/WeekdayPicker";
import { DuplicatePlanDialog } from "../../Features/TrainingCopyActions";
export default function Show({ plan, blocks, runs }) {
    const { url, canManage, templates, coaching } = useTraining();
    const [editing, setEditing] = useState(false),
        [blockEditor, setBlockEditor] = useState(false),
        [duplicate, setDuplicate] = useState(false),
        [deleting, setDeleting] = useState(null),
        [exercise, setExercise] = useState(null);
    return (
        <div className="program-page">
            <Head title={plan.name} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: url("/training-plans") },
                    { label: plan.name },
                ]}
            />
            <PageHeader
                eyebrow={`${plan.duration_weeks}-WEEK STRUCTURED PROGRAM`}
                title={plan.name}
                description={plan.description}
            >
                {canManage && !templates && (
                    <ButtonLink
                        href={url(`/training-plans/${plan.id}/program-start`)}
                    >
                        Start program
                    </ButtonLink>
                )}
                {canManage && (
                    <Button
                        variant="secondary"
                        onClick={() => setEditing(true)}
                    >
                        Edit details
                    </Button>
                )}
                {canManage && !coaching && (
                    <Button
                        variant="secondary"
                        onClick={() => setDuplicate(true)}
                    >
                        Duplicate program
                    </Button>
                )}
                {templates && (
                    <ButtonLink href={url(`/training-plans/${plan.id}/assign`)}>
                        Assign to trainee
                    </ButtonLink>
                )}
            </PageHeader>
            {templates && (
                <p className="field-hint">
                    Reusable template · {plan.assigned_trainees_count || 0}{" "}
                    assigned trainees. Each assigned copy stays independent.
                </p>
            )}
            {plan.coach_name && (
                <p className="coach-callout">Assigned by {plan.coach_name}</p>
            )}
            {!!runs.length && (
                <section className="panel program-card">
                    <h2>Program runs</h2>
                    <p>
                        Every start has its own schedule, progress and history.
                    </p>
                    <div className="program-stack">
                        {runs.map((run) => (
                            <Link
                                className="program-run-link"
                                key={run.id}
                                href={url(`/program-runs/${run.id}`)}
                            >
                                <strong>{run.definition_snapshot.name}</strong>
                                <span>
                                    {run.started_on} · {run.status} →
                                </span>
                            </Link>
                        ))}
                    </div>
                </section>
            )}
            <section>
                <div className="program-heading">
                    <div>
                        <h2>Blocks & workouts</h2>
                        <p>
                            Changes here update the reusable definition. Review
                            each active run to apply future changes.
                        </p>
                    </div>
                    {canManage && (
                        <Button
                            variant="secondary"
                            onClick={() => setBlockEditor(true)}
                        >
                            Edit blocks
                        </Button>
                    )}
                </div>
                <BlockTimeline blocks={blocks} />
                <div className="program-stack">
                    {blocks.map((block) => (
                        <section className="panel program-card" key={block.id}>
                            <div className="program-heading">
                                <div>
                                    <h2>{block.name}</h2>
                                    <p>
                                        Weeks {block.start_week}–
                                        {block.end_week} ·{" "}
                                        {block.type === "deload"
                                            ? "Deload"
                                            : "Training"}
                                    </p>
                                </div>
                                {canManage && block.editable && (
                                    <ButtonLink
                                        variant="secondary"
                                        href={url(
                                            `/training-plans/${plan.id}/days/create?block=${block.id}`,
                                        )}
                                    >
                                        Add training day
                                    </ButtonLink>
                                )}
                            </div>
                            {!block.editable && (
                                <p className="field-hint">
                                    Current or completed block. Coach structural
                                    editing is protected.
                                </p>
                            )}
                            {!block.training_days.length && (
                                <p>
                                    Add a workout, choose its weekdays, then add
                                    exercises using the existing builder.
                                </p>
                            )}
                            {block.training_days.map((day) => (
                                <details className="program-day" key={day.id}>
                                    <summary>
                                        <strong>{day.title}</strong>
                                        <span>
                                            {weekdaySummary(day.weekdays || [])}{" "}
                                            · {day.exercises.length} exercises
                                        </span>
                                    </summary>
                                    <div className="program-actions">
                                        <ButtonLink
                                            variant="secondary"
                                            href={url(
                                                `/training-days/${day.id}`,
                                            )}
                                        >
                                            View day
                                        </ButtonLink>
                                        {canManage && block.editable && (
                                            <>
                                                <ButtonLink
                                                    variant="secondary"
                                                    href={url(
                                                        `/training-days/${day.id}/edit`,
                                                    )}
                                                >
                                                    Edit day
                                                </ButtonLink>
                                                <Button
                                                    variant="secondary"
                                                    onClick={() =>
                                                        setDeleting(day)
                                                    }
                                                >
                                                    Delete day
                                                </Button>
                                            </>
                                        )}
                                    </div>
                                    <ul className="program-prescriptions">
                                        {day.exercises.map((ex) => (
                                            <li key={ex.id}>
                                                <span>
                                                    <strong>{ex.name}</strong>
                                                    <small>
                                                        {ex.sets} × {ex.reps}
                                                        {ex.target_weight
                                                            ? ` · ${ex.target_weight} kg`
                                                            : ""}
                                                        {ex.superset_group
                                                            ? ` · Superset ${ex.superset_group}`
                                                            : ""}
                                                    </small>
                                                </span>
                                                {ex.library_key && (
                                                    <Button
                                                        variant="secondary"
                                                        onClick={() =>
                                                            setExercise(ex)
                                                        }
                                                    >
                                                        Across blocks
                                                    </Button>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            ))}
                        </section>
                    ))}
                </div>
            </section>
            {editing && (
                <Modal
                    title="Edit program details"
                    onClose={() => setEditing(false)}
                >
                    <TrainingPlanForm
                        plan={plan}
                        onCancel={() => setEditing(false)}
                        onSaved={() => setEditing(false)}
                    />
                </Modal>
            )}
            {blockEditor && (
                <ProgramBlockEditor
                    plan={plan}
                    blocks={blocks}
                    onClose={() => setBlockEditor(false)}
                />
            )}
            {duplicate && (
                <DuplicatePlanDialog
                    plan={plan}
                    onClose={() => setDuplicate(false)}
                />
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete program day?"
                    description="The reusable day will be removed. Existing runs keep their saved prescriptions; review future schedule changes separately."
                    url={url(`/training-days/${deleting.id}`)}
                    onClose={() => setDeleting(null)}
                />
            )}
            {exercise && (
                <Modal
                    title={`${exercise.name} across blocks`}
                    onClose={() => setExercise(null)}
                >
                    <div className="program-stack">
                        {blocks.map((block) => (
                            <section
                                className="panel program-card"
                                key={block.id}
                            >
                                <h3>{block.name}</h3>
                                <p>
                                    Weeks {block.start_week}–{block.end_week}
                                </p>
                                {block.training_days.flatMap((day) =>
                                    day.exercises
                                        .filter(
                                            (ex) =>
                                                ex.library_key ===
                                                exercise.library_key,
                                        )
                                        .map((ex) => (
                                            <p key={ex.id}>
                                                {day.title}: {ex.sets} ×{" "}
                                                {ex.reps}
                                                {ex.target_weight
                                                    ? ` · ${ex.target_weight} kg`
                                                    : ""}
                                            </p>
                                        )),
                                )}
                            </section>
                        ))}
                    </div>
                    <p>
                        Each block has its own prescription. Exercise history
                        stays connected by library identity.
                    </p>
                </Modal>
            )}
        </div>
    );
}
