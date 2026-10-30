import { Head, useForm } from "@inertiajs/react";
import {
    PageHeader,
    Button,
    ButtonLink,
    Field,
    Pagination,
} from "../../Components/UI";
export default function ChooseTrainee({ plan, relationships, search }) {
    const form = useForm({ search });
    return (
        <>
            <Head title="Assign to trainee" />
            <PageHeader title="Assign to trainee" description={plan.name}>
                <ButtonLink
                    variant="secondary"
                    href={`/coach/training-plans/${plan.id}`}
                >
                    Back to template
                </ButtonLink>
            </PageHeader>
            <p>
                Choose a connected trainee. New trainees continue guided setup;
                trainees with training can receive another independent copy.
            </p>
            <form
                className="panel coach-card coach-actions"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.get(`/coach/training-plans/${plan.id}/assign`);
                }}
            >
                <Field label="Search trainees">
                    <input
                        value={form.data.search}
                        onChange={(e) => form.setData("search", e.target.value)}
                    />
                </Field>
                <Button>Search</Button>
            </form>
            <div className="coach-grid">
                {relationships.data.map((row) => (
                    <article className="panel coach-card" key={row.id}>
                        <h2>{row.trainee.name}</h2>
                        <p>{row.trainee.training_code}</p>
                        <ButtonLink
                            href={`/coach/trainees/${row.trainee.id}/${row.has_training ? "assign" : "setup"}?template=${plan.id}`}
                        >
                            {row.has_training
                                ? "Assign training"
                                : "Continue setup"}
                        </ButtonLink>
                    </article>
                ))}
            </div>
            {!relationships.data.length && (
                <section className="panel coach-card">
                    <h2>No matching connected trainees</h2>
                    <ButtonLink href="/coach/trainees">Add trainee</ButtonLink>
                </section>
            )}
            <Pagination links={relationships.links} />
        </>
    );
}
