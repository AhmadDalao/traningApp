import { programStatus } from "../../Features/ProgramUI";
import { Head } from "@inertiajs/react";
import {
    PageHeader,
    ButtonLink,
    Pagination,
    EmptyState,
} from "../../Components/UI";
import { useTraining } from "../../Features/CoachingUI";
export default function Index({ runs }) {
    const { url } = useTraining();
    return (
        <div className="program-page">
            <Head title="Program runs" />
            <PageHeader
                title="Program runs"
                description="Each start and repeat keeps its own training journey."
            >
                <ButtonLink variant="secondary" href={url("/training-plans")}>
                    Training plans
                </ButtonLink>
            </PageHeader>
            <div className="program-stack">
                {runs.data.map((run) => (
                    <section className="panel program-card" key={run.id}>
                        <h2>{run.definition_snapshot.name}</h2>
                        <p>
                            Started {run.started_on} ·{" "}
                            <span
                                className={`badge program-status-${run.status}`}
                            >
                                {programStatus(run.status)}
                            </span>
                        </p>
                        <p>
                            {run.position.current_week
                                ? `Week ${run.position.current_week} of ${run.position.duration_weeks} · ${run.position.current_block?.name || ""}`
                                : `${run.position.resolved_weeks} weeks resolved`}
                        </p>
                        <ButtonLink href={url(`/program-runs/${run.id}`)}>
                            View program run
                        </ButtonLink>
                    </section>
                ))}
            </div>
            {!runs.data.length && (
                <EmptyState
                    title="No program runs yet"
                    description="Create or choose a structured program, then preview its schedule to start."
                />
            )}
            <Pagination links={runs.links} />
        </div>
    );
}
