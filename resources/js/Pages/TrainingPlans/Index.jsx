import { CurrentTraining, TrainingRow } from "../../Features/CoachWorkspace";
import useLiveFilters from "../../Features/useLiveFilters";
import { useTraining } from "../../Features/CoachingUI";
import { useState } from "react";
import { Head, Link, useForm, router } from "@inertiajs/react";
import {
    Plus,
    Pencil,
    Trash2,
    ArrowRight,
    LayoutGrid,
    List,
    ShieldCheck,
    Search,
    SlidersHorizontal,
} from "lucide-react";
import PlanThumbnail from "../../Components/PlanThumbnail";
import {
    PageHeader,
    Button,
    ButtonLink,
    Modal,
    ConfirmDialog,
    EmptyState,
    Pagination,
    Field,
    Breadcrumbs,
    DateLabel,
} from "../../Components/UI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
import { DuplicatePlanDialog } from "../../Features/TrainingCopyActions";
import { Copy } from "lucide-react";
export default function Index({
    plans,
    currentTraining,
    libraryCounts,
    filters = { search: "", type: "all" },
}) {
    const { url, canManage, coaching, templates } = useTraining();
    const filter = useLiveFilters(url("/training-plans"), filters, [
        "plans",
        "filters",
        "libraryCounts",
    ]);
    const [view, setView] = useState("grid");
    const [moreFilters, setMoreFilters] = useState(false);
    const [duplicating, setDuplicating] = useState(null);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);
    return (
        <>
            <Head
                title={
                    templates
                        ? "Programs"
                        : coaching
                          ? "Training"
                          : "Training plans"
                }
            />
            {(templates || coaching) && (
                <Breadcrumbs
                    items={[
                        { label: "Coach Overview", href: "/coach" },
                        { label: coaching ? "Trainee training" : "Programs" },
                    ]}
                />
            )}
            <PageHeader
                eyebrow={
                    templates
                        ? "REUSABLE TRAINING LIBRARY"
                        : "BUILD YOUR ROUTINE"
                }
                title={
                    templates
                        ? "Programs"
                        : coaching
                          ? "Training"
                          : "Training plans"
                }
                description={
                    templates
                        ? "Build once, refine confidently, and assign independently to each trainee."
                        : coaching
                          ? "Current training and previous assignments."
                          : "Your goals, broken down into days you can show up for."
                }
            >
                {!coaching && (
                    <ButtonLink
                        href={url("/recommendations")}
                        variant="secondary"
                    >
                        {templates ? "Explore plans" : "Find a training plan"}
                    </ButtonLink>
                )}
                {!templates && !coaching && (
                    <ButtonLink variant="secondary" href={url("/program-runs")}>
                        Program runs
                    </ButtonLink>
                )}
                {canManage && !coaching && (
                    <Button
                        onClick={() =>
                            templates
                                ? router.visit("/coach/training-plans/create")
                                : setEditing({})
                        }
                    >
                        <Plus size={17} />
                        {templates ? "Create Program" : "Create training plan"}
                    </Button>
                )}
            </PageHeader>
            {templates && (
                <nav
                    className="library-filters trainee-filters"
                    aria-label="Program readiness"
                >
                    {[
                        ["all", "All"],
                        ["ready", "Ready"],
                        ["draft", "Draft"],
                    ].map(([key, label]) => (
                        <button
                            key={key}
                            type="button"
                            aria-pressed={filter.data.readiness === key}
                            onClick={() => filter.setData("readiness", key)}
                        >
                            {label}
                            <span>{libraryCounts?.[key] || 0}</span>
                        </button>
                    ))}
                </nav>
            )}
            {(templates || coaching) && (
                <div
                    className={`panel coach-card live-filter-bar ${templates ? "program-search-bar" : ""}`}
                    aria-busy={filter.processing}
                >
                    <Field label="Search programs">
                        <div className="search-input">
                            <Search size={18} />
                            <input
                                placeholder="Find a program or training focus"
                                type="search"
                                value={filter.data.search}
                                maxLength={100}
                                onChange={(e) =>
                                    filter.setData("search", e.target.value)
                                }
                            />
                        </div>
                    </Field>
                    {templates && (
                        <>
                            <Button
                                variant="secondary"
                                aria-expanded={moreFilters}
                                onClick={() => setMoreFilters(!moreFilters)}
                            >
                                <SlidersHorizontal size={17} />
                                Filters
                            </Button>
                            <div
                                className="view-switch"
                                role="group"
                                aria-label="Program layout"
                            >
                                <button
                                    aria-label="Grid view"
                                    aria-pressed={view === "grid"}
                                    onClick={() => setView("grid")}
                                >
                                    <LayoutGrid size={19} />
                                </button>
                                <button
                                    aria-label="List view"
                                    aria-pressed={view === "list"}
                                    onClick={() => setView("list")}
                                >
                                    <List size={19} />
                                </button>
                            </div>
                        </>
                    )}
                    <div
                        className="directory-advanced-filters"
                        hidden={templates && !moreFilters}
                    >
                        <Field label="Training type">
                            <select
                                value={filter.data.type}
                                onChange={(e) =>
                                    filter.setData("type", e.target.value)
                                }
                            >
                                <option value="all">Programs & plans</option>
                                <option value="program">
                                    Structured programs
                                </option>
                                <option value="simple">Simple plans</option>
                            </select>
                        </Field>
                        <Field label="Items per page">
                            <select
                                value={filter.data.per_page || 10}
                                onChange={(e) =>
                                    filter.setData(
                                        "per_page",
                                        Number(e.target.value),
                                    )
                                }
                            >
                                {[10, 25, 50, 100].map((n) => (
                                    <option key={n} value={n}>
                                        {n}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        {(filter.data.search ||
                            filter.data.type !== "all" ||
                            filter.data.readiness !== "all") && (
                            <Button
                                variant="secondary"
                                onClick={() =>
                                    filter.setData({
                                        search: "",
                                        type: "all",
                                        readiness: "all",
                                        per_page: 10,
                                    })
                                }
                            >
                                Clear all
                            </Button>
                        )}
                    </div>
                </div>
            )}
            {templates && (
                <div className="library-view-bar">
                    <span>
                        {plans.total} programs · showing {plans.from || 0}–
                        {plans.to || 0}
                    </span>
                </div>
            )}
            {coaching && <CurrentTraining training={currentTraining} />}
            {(templates || coaching) && (
                <div
                    className={`coach-training-library ${templates ? `program-library ${view}-view` : ""}`}
                >
                    {templates ? (
                        plans.data.map((plan) => (
                            <TrainingRow key={plan.id} plan={plan}>
                                <ButtonLink
                                    href={url(`/training-plans/${plan.id}`)}
                                    variant="secondary"
                                >
                                    Preview
                                </ButtonLink>
                                {plan.workout_days_count > 0 &&
                                !plan.empty_workouts_count ? (
                                    <ButtonLink
                                        href={`/coach/training-plans/${plan.id}/assign`}
                                    >
                                        Assign <ArrowRight size={16} />
                                    </ButtonLink>
                                ) : (
                                    <ButtonLink
                                        variant="secondary"
                                        href={`/coach/training-plans/${plan.id}/edit`}
                                    >
                                        Finish program
                                    </ButtonLink>
                                )}
                                <details className="trainee-more">
                                    <summary
                                        aria-label={`More actions for ${plan.name}`}
                                    >
                                        •••
                                    </summary>
                                    <div>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                router.visit(
                                                    `/coach/training-plans/${plan.id}/edit`,
                                                )
                                            }
                                        >
                                            Edit program
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => setDuplicating(plan)}
                                        >
                                            Duplicate
                                        </button>
                                        <hr />
                                        <button
                                            className="danger-text"
                                            type="button"
                                            onClick={() => setDeleting(plan)}
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </details>
                            </TrainingRow>
                        ))
                    ) : (
                        <>
                            {plans.data.filter(
                                (p) =>
                                    p.id !== currentTraining?.id &&
                                    p.assignment_status !== "ended" &&
                                    !["completed", "ended"].includes(
                                        p.run_status,
                                    ),
                            ).length > 0 && (
                                <section>
                                    <h2>Other Training</h2>
                                    {plans.data
                                        .filter(
                                            (p) =>
                                                p.id !== currentTraining?.id &&
                                                p.assignment_status !==
                                                    "ended" &&
                                                ![
                                                    "completed",
                                                    "ended",
                                                ].includes(p.run_status),
                                        )
                                        .map((plan) => (
                                            <TrainingRow
                                                key={plan.id}
                                                plan={plan}
                                            >
                                                <ButtonLink
                                                    variant="secondary"
                                                    href={url(
                                                        `/training-plans/${plan.id}`,
                                                    )}
                                                >
                                                    View Training
                                                </ButtonLink>
                                            </TrainingRow>
                                        ))}
                                </section>
                            )}
                            {plans.data.some(
                                (p) =>
                                    p.id !== currentTraining?.id &&
                                    (p.assignment_status === "ended" ||
                                        ["completed", "ended"].includes(
                                            p.run_status,
                                        )),
                            ) && (
                                <details className="panel coach-card coach-secondary-details">
                                    <summary>Past Training</summary>
                                    {plans.data
                                        .filter(
                                            (p) =>
                                                p.id !== currentTraining?.id &&
                                                (p.assignment_status ===
                                                    "ended" ||
                                                    [
                                                        "completed",
                                                        "ended",
                                                    ].includes(p.run_status)),
                                        )
                                        .map((plan) => (
                                            <TrainingRow
                                                key={plan.id}
                                                plan={plan}
                                            >
                                                <ButtonLink
                                                    variant="secondary"
                                                    href={url(
                                                        `/training-plans/${plan.id}`,
                                                    )}
                                                >
                                                    View Training
                                                </ButtonLink>
                                            </TrainingRow>
                                        ))}
                                </details>
                            )}
                            <Link
                                className="text-link"
                                href={url("/program-runs")}
                            >
                                All program runs →
                            </Link>
                        </>
                    )}
                    <div className="library-pagination">
                        <Pagination links={plans.links} />
                    </div>
                    {templates && !plans.data.length && (
                        <section className="panel coach-card">
                            <h2>No programs found</h2>
                            <p>Create a program or change the filters.</p>
                        </section>
                    )}
                </div>
            )}
            {templates && (
                <p className="library-guidance">
                    <ShieldCheck size={19} />
                    Assignments create independent trainee copies. Ready to
                    assign means training days have exercises; dates and
                    coverage are checked during assignment.
                </p>
            )}
            {!templates && !coaching && (
                <>
                    {plans.data.length ? (
                        <>
                            <div className="plan-grid">
                                {plans.data.map((plan, index) => (
                                    <article
                                        className="panel plan-card"
                                        key={plan.id}
                                    >
                                        <div className="plan-cover plan-cover-photo">
                                            <PlanThumbnail
                                                src={plan.thumbnail_url}
                                                name={plan.name}
                                            />
                                            <span>
                                                {plan.structure_type ===
                                                "program"
                                                    ? `${plan.duration_weeks}-WEEK PROGRAM`
                                                    : "YOUR TRAINING PLAN"}
                                            </span>
                                            <span className="plan-number">
                                                {String(index + 1).padStart(
                                                    2,
                                                    "0",
                                                )}
                                            </span>
                                        </div>
                                        <div className="plan-body">
                                            <h2>{plan.name}</h2>
                                            {coaching && plan.assigned_at && (
                                                <p className="field-hint">
                                                    Assigned{" "}
                                                    <DateLabel
                                                        date={plan.assigned_at.slice(
                                                            0,
                                                            10,
                                                        )}
                                                    />{" "}
                                                    ·{" "}
                                                    {plan.run_status
                                                        ? `Run ${plan.run_status}`
                                                        : plan.assignment_status ===
                                                            "ended"
                                                          ? "Ended assignment"
                                                          : "Not started"}
                                                </p>
                                            )}
                                            {templates && (
                                                <p>
                                                    {
                                                        plan.assigned_trainees_count
                                                    }{" "}
                                                    active trainees
                                                </p>
                                            )}
                                            {plan.coach_name && (
                                                <p className="field-hint">
                                                    Coach assigned ·{" "}
                                                    {plan.coach_name}
                                                    {plan.assignment_status ===
                                                    "ended"
                                                        ? " · Assignment ended"
                                                        : ""}
                                                </p>
                                            )}
                                            <div className="plan-meta">
                                                {plan.workout_days_count}{" "}
                                                training{" "}
                                                {plan.workout_days_count === 1
                                                    ? "day"
                                                    : "days"}
                                                <span>·</span>
                                                {plan.rest_days_count} rest{" "}
                                                {plan.rest_days_count === 1
                                                    ? "day"
                                                    : "days"}
                                            </div>
                                            <p>
                                                {plan.description ||
                                                    "A fresh plan, ready to make your own."}
                                            </p>
                                            <div className="plan-actions">
                                                {templates && (
                                                    <ButtonLink
                                                        href={`/coach/training-plans/${plan.id}/assign`}
                                                    >
                                                        Assign
                                                    </ButtonLink>
                                                )}
                                                <ButtonLink
                                                    href={url(
                                                        `/training-plans/${plan.id}`,
                                                    )}
                                                    variant="secondary"
                                                    className="small"
                                                >
                                                    View plan
                                                    <ArrowRight size={14} />
                                                </ButtonLink>
                                                <div>
                                                    {canManage &&
                                                        (!coaching ||
                                                            plan.assignment_status ===
                                                                "active") && (
                                                            <button
                                                                className="icon-btn"
                                                                aria-label={`Edit ${plan.name}`}
                                                                onClick={() =>
                                                                    templates
                                                                        ? router.visit(
                                                                              `/coach/training-plans/${plan.id}/edit`,
                                                                          )
                                                                        : setEditing(
                                                                              plan,
                                                                          )
                                                                }
                                                            >
                                                                <Pencil
                                                                    size={16}
                                                                />
                                                            </button>
                                                        )}
                                                    {!coaching && (
                                                        <button
                                                            className="icon-btn"
                                                            aria-label={`Duplicate ${plan.name}`}
                                                            onClick={() =>
                                                                setDuplicating(
                                                                    plan,
                                                                )
                                                            }
                                                        >
                                                            <Copy size={16} />
                                                        </button>
                                                    )}
                                                    {!coaching && (
                                                        <button
                                                            className="icon-btn danger-text"
                                                            aria-label={`Delete ${plan.name}`}
                                                            onClick={() =>
                                                                setDeleting(
                                                                    plan,
                                                                )
                                                            }
                                                        >
                                                            <Trash2 size={16} />
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    </article>
                                ))}
                            </div>
                            <div className="library-pagination">
                                <Pagination links={plans.links} />
                            </div>
                        </>
                    ) : (
                        <section className="panel">
                            <EmptyState
                                title="Every routine starts with a plan"
                                description="Create your first plan, then add training days and exercises."
                            >
                                {canManage && (
                                    <Button
                                        onClick={() =>
                                            templates
                                                ? router.visit(
                                                      "/coach/training-plans/create",
                                                  )
                                                : setEditing({})
                                        }
                                    >
                                        <Plus size={16} />
                                        Create your first plan
                                    </Button>
                                )}
                            </EmptyState>
                        </section>
                    )}
                </>
            )}
            {duplicating && (
                <DuplicatePlanDialog
                    plan={duplicating}
                    onClose={() => setDuplicating(null)}
                />
            )}
            {editing && (
                <Modal
                    title={
                        editing.id
                            ? "Edit training plan"
                            : "Create training plan"
                    }
                    onClose={() => setEditing(null)}
                >
                    <TrainingPlanForm
                        plan={editing.id ? editing : null}
                        onSaved={() => setEditing(null)}
                        onCancel={() => setEditing(null)}
                    />
                </Modal>
            )}
            {deleting && (
                <ConfirmDialog
                    title="Delete training plan?"
                    description={`“${deleting.name}” and its training days, exercises, and thumbnails will be permanently removed. Your scheduled session history will remain.`}
                    url={url(`/training-plans/${deleting.id}`)}
                    onClose={() => setDeleting(null)}
                />
            )}
        </>
    );
}
