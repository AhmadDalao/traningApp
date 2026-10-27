import { useEffect, useState } from "react";
import { Head, useForm, usePage, router } from "@inertiajs/react";
import { Check } from "lucide-react";
import { Button } from "../../Components/UI";
import ProfileFields, { profileDefaults } from "../../Features/ProfileFields";

export default function Index({ profile, options }) {
    const [step, setStep] = useState(0);
    const [skipping, setSkipping] = useState(false);
    const { today } = usePage().props;
    const form = useForm(profileDefaults(profile));
    useEffect(() => {
        window.scrollTo({ top: 0, behavior: "instant" });
    }, [step]);
    const titles = [
        "Welcome to Training Planner",
        "What are you training for?",
        "How do you train?",
    ];
    return (
        <div className="onboarding-wrap">
            <Head title="Set up your profile" />
            <section className="panel onboarding-panel">
                <ol
                    className="onboarding-steps"
                    aria-label="Profile setup progress"
                >
                    {["About you", "Your goals", "Your training"].map(
                        (label, index) => (
                            <li
                                key={label}
                                className={index <= step ? "active" : ""}
                                aria-current={
                                    step === index ? "step" : undefined
                                }
                            >
                                <span>
                                    {index < step ? (
                                        <Check size={14} />
                                    ) : (
                                        index + 1
                                    )}
                                </span>
                                {label}
                            </li>
                        ),
                    )}
                </ol>
                <h1>{titles[step]}</h1>
                <p className="onboarding-intro">
                    {step === 0
                        ? "A few optional details help personalize your planner. You can skip this and come back anytime."
                        : step === 1
                          ? "Choose up to 2 goals. Your first choice is primary."
                          : "Tell us about your experience, availability, and equipment."}
                </p>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (step < 2) {
                            setStep(step + 1);
                            return;
                        }
                        form.post("/onboarding", {
                            onError: (errors) =>
                                setStep(
                                    Object.keys(errors).some((key) =>
                                        [
                                            "date_of_birth",
                                            "height_cm",
                                            "current_weight_kg",
                                        ].includes(key),
                                    )
                                        ? 0
                                        : Object.keys(errors).some((key) =>
                                                key.startsWith("training_goal"),
                                            )
                                          ? 1
                                          : 2,
                                ),
                        });
                    }}
                >
                    <ProfileFields
                        form={form}
                        options={options}
                        section={["about", "goal", "preferences"][step]}
                        today={today}
                    />
                    <div className="form-actions onboarding-actions">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing || skipping}
                            onClick={() => {
                                setSkipping(true);
                                router.post(
                                    "/onboarding/skip",
                                    {},
                                    { onFinish: () => setSkipping(false) },
                                );
                            }}
                        >
                            Skip for now
                        </Button>
                        <div>
                            {step > 0 && (
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={form.processing}
                                    onClick={() => setStep(step - 1)}
                                >
                                    Back
                                </Button>
                            )}
                            <Button disabled={form.processing || skipping}>
                                {form.processing
                                    ? "Saving…"
                                    : step === 2
                                      ? "Save & see recommendations"
                                      : "Next"}
                            </Button>
                        </div>
                    </div>
                </form>
            </section>
        </div>
    );
}
