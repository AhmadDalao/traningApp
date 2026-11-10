import SettingsLayout from "../../Components/SettingsLayout";
import { UserRound, Check } from "lucide-react";
import { Head, useForm } from "@inertiajs/react";
import { PageHeader, Field, Button, ButtonLink } from "../../Components/UI";
export default function Setup({ coachProfile, settings = false }) {
    const form = useForm({
        display_name: coachProfile?.display_name || "",
        specialization: coachProfile?.specialization || "",
        bio: coachProfile?.bio || "",
    });
    return (
        <SettingsLayout section="profile">
            <Head title="Coach profile" />
            <PageHeader
                title="Coach profile"
                description="Manage your coaching name and information."
            />
            <form
                className="panel coach-form settings-profile-form"
                onSubmit={(e) => {
                    e.preventDefault();
                    form.post(settings ? "/coach/settings" : "/profile/coach");
                }}
            >
                <h2>
                    <UserRound size={22} />
                    Coach profile
                </h2>
                <p>
                    Your coaching identity, visible to your connected trainees.
                </p>
                {[
                    ["display_name", "Coach display name", 100],
                    ["specialization", "Specialization (optional)", 150],
                    ["bio", "About your coaching (optional)", 2000],
                ].map(([key, label, max]) => (
                    <Field key={key} label={label} error={form.errors[key]}>
                        {key === "bio" ? (
                            <>
                                <textarea
                                    rows={5}
                                    maxLength={max}
                                    value={form.data[key]}
                                    onChange={(e) =>
                                        form.setData(key, e.target.value)
                                    }
                                />
                                <span className="field-hint bio-count">
                                    {form.data[key].length} / {max}
                                </span>
                            </>
                        ) : (
                            <input
                                required={key === "display_name"}
                                maxLength={max}
                                value={form.data[key]}
                                onChange={(e) =>
                                    form.setData(key, e.target.value)
                                }
                            />
                        )}
                    </Field>
                ))}
                <div className="form-actions">
                    <ButtonLink
                        variant="secondary"
                        href={settings ? "/coach" : "/profile"}
                    >
                        Cancel
                    </ButtonLink>
                    <Button disabled={form.processing}>
                        <Check size={17} />
                        Save coach profile
                    </Button>
                </div>
            </form>
        </SettingsLayout>
    );
}
