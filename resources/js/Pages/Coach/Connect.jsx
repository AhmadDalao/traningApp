import { Head, useForm } from "@inertiajs/react";
import { PageHeader, Button } from "../../Components/UI";
import { Errors } from "../../Features/CoachingUI";
export default function Connect({ member, token }) {
    const form = useForm({ token });
    return (
        <>
            <Head title="Connect with trainee" />
            <PageHeader
                title="Send a coaching request"
                description="You remain signed in to your own coach account."
            />
            <form
                className="panel coach-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post("/coach/requests");
                }}
            >
                <h2>{member.name}</h2>
                <p>Training Planner member · {member.training_code}</p>
                <p>
                    Training data remains private until they accept your
                    request.
                </p>
                <Errors errors={form.errors} />
                <Button disabled={form.processing}>Send request</Button>
            </form>
        </>
    );
}
