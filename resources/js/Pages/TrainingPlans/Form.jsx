import { useTraining } from "../../Features/CoachingUI";
import { Head, router } from "@inertiajs/react";
import { PageHeader, Breadcrumbs } from "../../Components/UI";
import TrainingPlanForm from "../../Features/TrainingPlanForm";
export default function Form({ plan }) {
    const { url } = useTraining();
    return (
        <>
            <Head title={plan ? "Edit plan" : "Create plan"} />
            <Breadcrumbs
                items={[
                    { label: "Training plans", href: url("/training-plans") },
                    { label: plan ? "Edit plan" : "Create plan" },
                ]}
            />
            <PageHeader
                title={plan ? "Edit training plan" : "Create training plan"}
                description="Build a routine that works for you."
            />
            <div className="panel form-panel">
                <TrainingPlanForm
                    plan={plan}
                    onCancel={() =>
                        router.visit(
                            plan
                                ? url(`/training-plans/${plan.id}`)
                                : url("/training-plans"),
                        )
                    }
                />
            </div>
        </>
    );
}
