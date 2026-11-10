import { useState } from "react";
import { Head, Link, useForm, usePage } from "@inertiajs/react";
import {
    ArrowLeft,
    Bell,
    CalendarDays,
    Check,
    CheckCircle2,
    ChevronRight,
    Dumbbell,
    Flame,
    Heart,
    Layers,
    Megaphone,
    PersonStanding,
    Settings as SettingsIcon,
    Smartphone,
    TrendingUp,
    Users,
    UserRound,
} from "lucide-react";
import { Button, Field } from "../../Components/UI";
import { profileDefaults } from "../../Features/ProfileFields";
import Notifications from "../Notifications/Index";

const sections = [
    ["profile", "Coach profile", "Your coaching identity and bio", UserRound],
    [
        "preferences",
        "Training preferences",
        "Goals, experience, equipment",
        Dumbbell,
    ],
    ["notifications", "Notifications", "Workout reminders and updates", Bell],
    [
        "devices",
        "App & devices",
        "Install app and manage this device",
        Smartphone,
    ],
];
const preferenceKeys = [
    "training_goals",
    "body_weight_goal",
    "target_body_weight_kg",
    "experience_level",
    "training_days_per_week",
    "session_duration_minutes",
    "equipment_access",
];
const goalIcons = [
    Dumbbell,
    Flame,
    TrendingUp,
    Heart,
    PersonStanding,
    PersonStanding,
];

export default function Settings({
    coachProfile,
    profile,
    options,
    preferences,
    ...deviceProps
}) {
    const { props, url } = usePage();
    const requested = new URLSearchParams(url.split("?")[1]).get("section");
    const [section, setSection] = useState(
        sections.some(([key]) => key === requested)
            ? requested
            : requested === "account"
              ? "preferences"
              : "profile",
    );
    const [detail, setDetail] = useState(Boolean(requested));
    const defaults = profileDefaults(profile);
    const form = useForm({
        display_name: coachProfile?.display_name || "",
        specialization: coachProfile?.specialization || "",
        bio: coachProfile?.bio || "",
        training_preferences: Object.fromEntries(
            preferenceKeys.map((key) => [key, defaults[key]]),
        ),
        notifications: preferences,
    });
    const summary = props.settingsSummary;
    const p = form.data.training_preferences;
    const n = form.data.notifications;
    const setPreference = (key, value) =>
        form.setData("training_preferences", { ...p, [key]: value });
    const setNotification = (key, value) =>
        form.setData("notifications", { ...n, [key]: value });
    const [goalMessage, setGoalMessage] = useState("");
    const chooseSection = (key) => {
        setSection(key);
        setDetail(true);
        if (window.matchMedia("(min-width: 761px)").matches)
            document
                .getElementById(`settings-${key}`)
                ?.scrollIntoView({ behavior: "smooth", block: "start" });
        else window.scrollTo({ top: 0 });
    };
    const field = (key) => ({
        value: p[key],
        onChange: (event) => setPreference(key, event.target.value),
    });
    const error = (key) => form.errors[`training_preferences.${key}`];
    const sectionTitle = (key, hint) => {
        const [, title, , Icon] = sections.find(([id]) => id === key);
        return (
            <div className="settings-section-title">
                <Icon size={25} />
                <div>
                    <h2>{title}</h2>
                    <p>{hint}</p>
                </div>
            </div>
        );
    };
    const save = (event) => {
        event.preventDefault();
        form.post("/coach/settings", {
            preserveScroll: true,
            onSuccess: () => form.setDefaults(),
            onError: (errors) => {
                chooseSection(
                    Object.keys(errors).some((key) =>
                        key.startsWith("training_preferences"),
                    )
                        ? "preferences"
                        : Object.keys(errors).some((key) =>
                                key.startsWith("notifications"),
                            )
                          ? "notifications"
                          : "profile",
                );
            },
        });
    };
    return (
        <div
            className={`reference-settings ${detail ? "is-detail" : "is-directory"}`}
            data-section={section}
        >
            <Head title="Profile & Settings" />
            <header className="settings-page-title">
                <div>
                    <span className="eyebrow">Settings</span>
                    <h1>Profile & Settings</h1>
                    <p>
                        Manage your coaching profile, training preferences,
                        notifications and app access.
                    </p>
                </div>
                <div className="settings-global-actions">
                    <Button
                        variant="secondary"
                        disabled={form.processing}
                        onClick={() => {
                            form.reset();
                            form.clearErrors();
                        }}
                    >
                        Discard changes
                    </Button>
                    <Button
                        form="coach-settings-form"
                        type="submit"
                        disabled={form.processing}
                    >
                        <Check size={18} />
                        {form.processing ? "Saving…" : "Save changes"}
                    </Button>
                </div>
            </header>
            <button
                className="settings-mobile-back"
                aria-label="Back to settings"
                onClick={() => {
                    setDetail(false);
                    window.scrollTo({ top: 0 });
                }}
            >
                <ArrowLeft size={22} />
                <strong>
                    {sections.find(([key]) => key === section)?.[1]}
                </strong>
            </button>
            <section className="panel settings-hero">
                <button
                    className="settings-hero-identity"
                    onClick={() => chooseSection("profile")}
                    aria-label="Edit coach profile"
                >
                    <span className="settings-avatar">
                        {(coachProfile?.display_name || props.auth.user.name)
                            .slice(0, 1)
                            .toUpperCase()}
                    </span>
                    <span>
                        <h2>
                            {coachProfile?.display_name || props.auth.user.name}
                        </h2>
                        <p>
                            Coach
                            {coachProfile?.specialization
                                ? ` · ${coachProfile.specialization}`
                                : ""}
                        </p>
                        <span className="badge completed">
                            <CheckCircle2 size={14} />
                            Active
                        </span>
                    </span>
                    <ChevronRight className="settings-hero-chevron" size={20} />
                </button>
                <div className="settings-hero-stats">
                    {[
                        [summary?.trainees, "Connected trainees", Users],
                        [summary?.workouts, "Workouts today", CalendarDays],
                        [summary?.programs, "Programs", Layers],
                    ].map(([count, label, Icon]) => (
                        <div key={label}>
                            <Icon size={23} />
                            <strong>{count ?? 0}</strong>
                            <span>{label}</span>
                        </div>
                    ))}
                </div>
            </section>
            <div className="settings-layout">
                <nav
                    className="settings-section-nav panel"
                    aria-label="Settings sections"
                >
                    <h2>Settings</h2>
                    <p>Manage your account and preferences.</p>
                    {sections.map(([key, title, hint, Icon]) => (
                        <button
                            key={key}
                            type="button"
                            className={section === key ? "active" : ""}
                            onClick={() => chooseSection(key)}
                            aria-current={
                                section === key ? "location" : undefined
                            }
                        >
                            <Icon size={24} />
                            <span>
                                <strong>{title}</strong>
                                <small>{hint}</small>
                            </span>
                            <ChevronRight size={19} />
                        </button>
                    ))}
                    <Link
                        className="settings-account-link"
                        href="/coach/settings/account"
                    >
                        <SettingsIcon size={19} />
                        <span>Account details</span>
                        <ChevronRight size={17} />
                    </Link>
                </nav>
                <div className="settings-content">
                    <form id="coach-settings-form" onSubmit={save}>
                        {Object.keys(form.errors).length > 0 && (
                            <p className="field-error" role="alert">
                                Please check the highlighted settings. Nothing
                                has been saved.
                            </p>
                        )}
                        <section
                            id="settings-profile"
                            className="panel settings-section"
                            data-section="profile"
                        >
                            <div className="settings-mobile-avatar">
                                <span className="settings-avatar">
                                    {(
                                        coachProfile?.display_name ||
                                        props.auth.user.name
                                    )
                                        .slice(0, 1)
                                        .toUpperCase()}
                                </span>
                            </div>
                            {sectionTitle(
                                "profile",
                                "This information is visible in your coach workspace and to your trainees where relevant.",
                            )}
                            <div className="form-grid">
                                <Field
                                    label="Coach display name"
                                    required
                                    error={form.errors.display_name}
                                >
                                    <input
                                        required
                                        maxLength={100}
                                        value={form.data.display_name}
                                        onChange={(e) =>
                                            form.setData(
                                                "display_name",
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                                <Field
                                    label="Specialization (optional)"
                                    error={form.errors.specialization}
                                >
                                    <input
                                        maxLength={150}
                                        value={form.data.specialization}
                                        onChange={(e) =>
                                            form.setData(
                                                "specialization",
                                                e.target.value,
                                            )
                                        }
                                    />
                                </Field>
                            </div>
                            <Field
                                label="About your coaching (optional)"
                                error={form.errors.bio}
                            >
                                <textarea
                                    rows={3}
                                    maxLength={2000}
                                    value={form.data.bio}
                                    onChange={(e) =>
                                        form.setData("bio", e.target.value)
                                    }
                                />
                                <small className="bio-count">
                                    {form.data.bio.length} / 2000
                                </small>
                            </Field>
                        </section>
                        <section
                            id="settings-preferences"
                            className="panel settings-section"
                            data-section="preferences"
                        >
                            {sectionTitle(
                                "preferences",
                                "Your goals and preferences help tailor training suggestions in your personal workspace.",
                            )}
                            <fieldset className="settings-goals">
                                <legend>Training goals (choose up to 3)</legend>
                                {Object.entries(options.goals).map(
                                    ([key, label], index) => {
                                        const Icon =
                                            goalIcons[index] || Dumbbell;
                                        const checked =
                                            p.training_goals.includes(key);
                                        return (
                                            <label
                                                className={
                                                    checked ? "selected" : ""
                                                }
                                                key={key}
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={checked}
                                                    onChange={() => {
                                                        if (
                                                            !checked &&
                                                            p.training_goals
                                                                .length === 3
                                                        ) {
                                                            setGoalMessage(
                                                                "Choose up to 3 goals.",
                                                            );
                                                            return;
                                                        }
                                                        setGoalMessage("");
                                                        setPreference(
                                                            "training_goals",
                                                            checked
                                                                ? p.training_goals.filter(
                                                                      (goal) =>
                                                                          goal !==
                                                                          key,
                                                                  )
                                                                : [
                                                                      ...p.training_goals,
                                                                      key,
                                                                  ],
                                                        );
                                                    }}
                                                />
                                                <Icon size={23} />
                                                <span>{label}</span>
                                            </label>
                                        );
                                    },
                                )}
                                <small role="status">{goalMessage}</small>
                            </fieldset>
                            {Object.entries(form.errors)
                                .filter(([key]) =>
                                    key.startsWith(
                                        "training_preferences.training_goals",
                                    ),
                                )
                                .map(([key, text]) => (
                                    <p className="field-error" key={key}>
                                        {text}
                                    </p>
                                ))}
                            <div className="settings-preference-grid">
                                <Field
                                    label="Experience level"
                                    error={error("experience_level")}
                                >
                                    <select {...field("experience_level")}>
                                        <option value="">Select level</option>
                                        {Object.entries(options.levels).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>
                                <Field
                                    label="Training days per week"
                                    error={error("training_days_per_week")}
                                >
                                    <select
                                        {...field("training_days_per_week")}
                                    >
                                        <option value="">Select days</option>
                                        {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                                            <option key={n} value={n}>
                                                {n} {n === 1 ? "day" : "days"}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                                <Field
                                    label="Typical session duration"
                                    error={error("session_duration_minutes")}
                                >
                                    <select
                                        {...field("session_duration_minutes")}
                                    >
                                        <option value="">
                                            Select duration
                                        </option>
                                        {[30, 45, 60, 90].map((n) => (
                                            <option key={n} value={n}>
                                                {n} minutes
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <div className="form-grid">
                                <Field
                                    label="Body weight goal"
                                    error={error("body_weight_goal")}
                                >
                                    <select
                                        value={p.body_weight_goal}
                                        onChange={(e) =>
                                            form.setData(
                                                "training_preferences",
                                                {
                                                    ...p,
                                                    body_weight_goal:
                                                        e.target.value,
                                                    target_body_weight_kg: [
                                                        "lose",
                                                        "gain",
                                                    ].includes(e.target.value)
                                                        ? p.target_body_weight_kg
                                                        : "",
                                                },
                                            )
                                        }
                                    >
                                        {Object.entries(options.bodyGoals).map(
                                            ([key, label]) => (
                                                <option key={key} value={key}>
                                                    {label}
                                                </option>
                                            ),
                                        )}
                                    </select>
                                </Field>
                                {["lose", "gain"].includes(
                                    p.body_weight_goal,
                                ) && (
                                    <Field
                                        label="Target body weight"
                                        error={error("target_body_weight_kg")}
                                    >
                                        <div className="input-with-unit">
                                            <input
                                                type="number"
                                                min={20}
                                                max={500}
                                                step="0.01"
                                                {...field(
                                                    "target_body_weight_kg",
                                                )}
                                            />
                                            <span>kg</span>
                                        </div>
                                    </Field>
                                )}
                            </div>
                            <fieldset className="settings-equipment">
                                <legend>Equipment access</legend>
                                {Object.entries(options.equipment).map(
                                    ([key, label]) => (
                                        <label key={key}>
                                            <input
                                                type="checkbox"
                                                checked={p.equipment_access.includes(
                                                    key,
                                                )}
                                                onChange={(e) =>
                                                    setPreference(
                                                        "equipment_access",
                                                        e.target.checked
                                                            ? [
                                                                  ...p.equipment_access,
                                                                  key,
                                                              ]
                                                            : p.equipment_access.filter(
                                                                  (item) =>
                                                                      item !==
                                                                      key,
                                                              ),
                                                    )
                                                }
                                            />
                                            {label}
                                        </label>
                                    ),
                                )}
                            </fieldset>
                            {error("equipment_access") && (
                                <p className="field-error">
                                    {error("equipment_access")}
                                </p>
                            )}
                        </section>
                        <section
                            id="settings-notifications"
                            className="panel settings-section"
                            data-section="notifications"
                        >
                            {sectionTitle(
                                "notifications",
                                "Choose what reaches you and how often.",
                            )}
                            <div className="settings-reminder-row">
                                <label className="notification-toggle">
                                    <input
                                        type="checkbox"
                                        role="switch"
                                        checked={n.workout_reminders}
                                        onChange={(e) =>
                                            setNotification(
                                                "workout_reminders",
                                                e.target.checked,
                                            )
                                        }
                                    />
                                    <span>
                                        <CalendarDays size={23} />
                                        <span>
                                            <strong>Workout reminders</strong>
                                            <small>
                                                Get a reminder before a planned
                                                workout with a start time.
                                            </small>
                                        </span>
                                    </span>
                                </label>
                                <Field label="Remind me before my workout">
                                    <select
                                        value={n.reminder_minutes}
                                        onChange={(e) =>
                                            setNotification(
                                                "reminder_minutes",
                                                Number(e.target.value),
                                            )
                                        }
                                    >
                                        {[
                                            [15, "15 minutes"],
                                            [30, "30 minutes"],
                                            [60, "1 hour"],
                                            [120, "2 hours"],
                                        ].map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </Field>
                            </div>
                            <label className="notification-toggle">
                                <input
                                    type="checkbox"
                                    role="switch"
                                    checked={n.coach_updates}
                                    onChange={(e) =>
                                        setNotification(
                                            "coach_updates",
                                            e.target.checked,
                                        )
                                    }
                                />
                                <span>
                                    <Megaphone size={23} />
                                    <span>
                                        <strong>Coach updates</strong>
                                        <small>
                                            Connection requests, assigned plans
                                            and program changes.
                                        </small>
                                    </span>
                                </span>
                            </label>
                            {!deviceProps.pushConfigured && (
                                <p className="settings-delivery-note">
                                    Push delivery is currently off. Your
                                    preferences will be saved for when delivery
                                    is available.
                                </p>
                            )}
                            {Object.entries(form.errors)
                                .filter(([key]) =>
                                    key.startsWith("notifications"),
                                )
                                .map(([key, text]) => (
                                    <p className="field-error" key={key}>
                                        {text}
                                    </p>
                                ))}
                        </section>
                        <div className="settings-mobile-save">
                            <Button type="submit" disabled={form.processing}>
                                <Check size={20} />
                                {form.processing ? "Saving…" : "Save changes"}
                            </Button>
                        </div>
                    </form>
                    <section
                        id="settings-devices"
                        className="panel settings-section"
                        data-section="devices"
                    >
                        {sectionTitle(
                            "devices",
                            "Get the best experience with the Training Planner app.",
                        )}
                        <Notifications
                            embedded
                            preferences={preferences}
                            {...deviceProps}
                        />
                    </section>
                </div>
            </div>
        </div>
    );
}
