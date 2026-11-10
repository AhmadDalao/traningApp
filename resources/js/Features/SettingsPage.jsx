import { useEffect, useState, useSyncExternalStore } from "react";
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
import { Button, Field } from "../Components/UI";
import ProfileFields, { profileDefaults } from "./ProfileFields";
import Notifications from "./Notifications/DeviceSettings";

import { pwaSnapshot, subscribePwa } from "./Notifications/pwa";

const baseSections = [
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

export default function SettingsPage({
    initialSection,
    coachProfile,
    profile,
    options,
    preferences,
    ...deviceProps
}) {
    const { props, url } = usePage();
    const { online } = useSyncExternalStore(subscribePwa, pwaSnapshot);
    const coach = props.trainingContext?.workspace === "coach";
    const sections = coach
        ? [
              baseSections[0],
              [
                  "account",
                  "Account details",
                  "Name, body measurements and personal details",
                  SettingsIcon,
              ],
              ...baseSections.slice(1),
          ]
        : [
              [
                  "profile",
                  "Personal profile",
                  "Your account and body measurements",
                  UserRound,
              ],
              ...baseSections.slice(1),
          ];
    const requested =
        new URLSearchParams(url.split("?")[1]).get("section") || initialSection;
    const [section, setSection] = useState(
        sections.some(([key]) => key === requested)
            ? requested
            : requested === "account"
              ? "preferences"
              : "profile",
    );
    const [detail, setDetail] = useState(Boolean(requested));
    useEffect(() => {
        if (requested && sections.some(([key]) => key === requested)) {
            setSection(requested);
            setDetail(true);
        }
    }, [requested]);
    const defaults = profileDefaults(profile, props.auth.user.name);
    const form = useForm({
        account: Object.fromEntries(
            ["name", "date_of_birth", "height_cm", "current_weight_kg"].map(
                (key) => [key, defaults[key]],
            ),
        ),
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
    const error = (key) =>
        form.errors[`training_preferences.${key}`] || form.errors[key];
    const accountForm = {
        data: form.data.account,
        errors: Object.fromEntries(
            Object.entries(form.errors).map(([key, value]) => [
                key.replace(/^account\./, ""),
                value,
            ]),
        ),
        setData: (key, value) =>
            form.setData("account", { ...form.data.account, [key]: value }),
    };
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
        if (!online) return;
        form.transform((data) =>
            coach
                ? data
                : {
                      ...data.account,
                      ...data.training_preferences,
                      notifications: data.notifications,
                  },
        );
        form.submit(
            coach ? "post" : "put",
            `${coach ? "/coach/settings" : "/profile"}?section=${section}`,
            {
                preserveScroll: true,
                onSuccess: () => form.setDefaults(),
                onError: (errors) => {
                    chooseSection(
                        Object.keys(errors).some(
                            (key) =>
                                key.startsWith("training_preferences") ||
                                preferenceKeys.includes(key.split(".")[0]),
                        )
                            ? "preferences"
                            : Object.keys(errors).some((key) =>
                                    key.startsWith("notifications"),
                                )
                              ? "notifications"
                              : coach &&
                                  Object.keys(errors).some((key) =>
                                      key.startsWith("account."),
                                  )
                                ? "account"
                                : "profile",
                    );
                },
            },
        );
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
                        Manage your{" "}
                        {coach ? "coaching profile" : "personal profile"},
                        training preferences, notifications and app access.
                    </p>
                </div>
                <div className="settings-global-actions">
                    <Button
                        variant="secondary"
                        disabled={form.processing || !online}
                        onClick={() => {
                            form.reset();
                            form.clearErrors();
                        }}
                    >
                        Discard changes
                    </Button>
                    <Button
                        form="workspace-settings-form"
                        type="submit"
                        disabled={form.processing || !online}
                    >
                        <Check size={18} />
                        {form.processing ? "Saving…" : "Save changes"}
                    </Button>
                </div>
            </header>
            <div className="settings-mobile-back">
                <button
                    type="button"
                    className="icon-btn"
                    aria-label="Back to settings"
                    onClick={() => {
                        setDetail(false);
                        window.scrollTo({ top: 0 });
                    }}
                >
                    <ArrowLeft size={22} />
                </button>
                <h1>{sections.find(([key]) => key === section)?.[1]}</h1>
            </div>
            <section className="panel settings-hero">
                <button
                    className="settings-hero-identity"
                    onClick={() => chooseSection("profile")}
                    aria-label={
                        coach ? "Edit coach profile" : "Edit personal profile"
                    }
                >
                    <span className="settings-avatar">
                        {(
                            (coach && coachProfile?.display_name) ||
                            props.auth.user.name
                        )
                            .slice(0, 1)
                            .toUpperCase()}
                    </span>
                    <span>
                        <h2>
                            {(coach && coachProfile?.display_name) ||
                                props.auth.user.name}
                        </h2>
                        <p>
                            {coach ? "Coach" : props.auth.user.training_code}
                            {coach && coachProfile?.specialization
                                ? ` · ${coachProfile.specialization}`
                                : ""}
                        </p>
                        <span className="badge completed">
                            <CheckCircle2 size={14} />
                            {coach ? "Coach workspace" : "Personal workspace"}
                        </span>
                    </span>
                    <ChevronRight className="settings-hero-chevron" size={20} />
                </button>
                <div className="settings-hero-stats">
                    {(coach
                        ? [
                              [summary?.trainees, "Connected trainees", Users],
                              [
                                  summary?.workouts,
                                  "Workouts today",
                                  CalendarDays,
                              ],
                              [summary?.programs, "Programs", Layers],
                          ]
                        : [
                              [
                                  profile?.training_goals?.length || 0,
                                  "Training goals",
                                  Dumbbell,
                              ],
                              [
                                  profile?.training_days_per_week || "—",
                                  "Days per week",
                                  CalendarDays,
                              ],
                              [
                                  profile?.session_duration_minutes || "—",
                                  "Minutes per session",
                                  Layers,
                              ],
                          ]
                    ).map(([count, label, Icon]) => (
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
                </nav>
                <div className="settings-content">
                    <form id="workspace-settings-form" onSubmit={save}>
                        {Object.keys(form.errors).length > 0 && (
                            <p className="field-error" role="alert">
                                Please check the highlighted settings. Nothing
                                has been saved.
                            </p>
                        )}
                        {coach ? (
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
                        ) : null}
                        <section
                            id={coach ? "settings-account" : "settings-profile"}
                            className="panel settings-section"
                            data-section={coach ? "account" : "profile"}
                        >
                            {sectionTitle(
                                coach ? "account" : "profile",
                                "Your personal details stay with your account in both workspaces.",
                            )}
                            <p className="settings-account-id">
                                <strong>Training Planner ID</strong>{" "}
                                <code>{props.auth.user.training_code}</code>{" "}
                                <Link href="/my-coaching">
                                    My Coaching & QR
                                </Link>
                            </p>
                            <p className="field-hint">
                                {props.auth.user.email}
                            </p>
                            <ProfileFields
                                form={accountForm}
                                options={options}
                                section="about"
                                today={props.today}
                            />
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
                                .filter(
                                    ([key]) =>
                                        key.startsWith(
                                            "training_preferences.training_goals",
                                        ) || key.startsWith("training_goals"),
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
                            <Button
                                type="submit"
                                disabled={form.processing || !online}
                            >
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
                        <Notifications {...deviceProps} />
                    </section>
                </div>
            </div>
        </div>
    );
}
