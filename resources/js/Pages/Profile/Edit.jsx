import { Head, useForm, usePage } from "@inertiajs/react";
import { PageHeader, Button, ButtonLink } from "../../Components/UI";
import ProfileFields, { profileDefaults } from "../../Features/ProfileFields";

export default function Edit({ profile, options }) {
    const { auth, today, trainingContext } = usePage().props;
    const coach = trainingContext?.workspace === "coach";
    const form = useForm(profileDefaults(profile, auth.user.name));
    return (
        <>
            <Head title="Edit profile" />
            <PageHeader
                title="Edit profile"
                description="Keep your details and training preferences up to date."
            />
            <form
                className="profile-edit"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.put(coach ? "/coach/settings/account" : "/profile", {
                        preserveScroll: true,
                    });
                }}
            >
                {Object.keys(form.errors).length > 0 && (
                    <p className="field-error" role="alert">
                        Please check the highlighted fields below.
                    </p>
                )}
                <section className="panel profile-panel">
                    <h2>Personal information</h2>
                    <ProfileFields
                        form={form}
                        options={options}
                        section="about"
                        today={today}
                    />
                </section>
                <section className="panel profile-panel" id="preferences">
                    <h2>Training preferences</h2>
                    <ProfileFields
                        form={form}
                        options={options}
                        section="goal"
                        today={today}
                    />
                    <ProfileFields
                        form={form}
                        options={options}
                        section="preferences"
                        today={today}
                    />
                </section>
                <div className="form-actions">
                    <ButtonLink
                        href={coach ? "/coach/settings" : "/profile"}
                        variant="secondary"
                    >
                        Cancel
                    </ButtonLink>
                    <Button disabled={form.processing}>
                        {form.processing ? "Saving…" : "Save profile"}
                    </Button>
                </div>
            </form>
        </>
    );
}
