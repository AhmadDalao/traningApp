import { Field } from "../Components/UI";

export const bmiNote =
    "BMI is a general screening measurement and does not distinguish muscle from body fat.";
export function BmiSummary({ height, weight }) {
    const bmi =
        Number(height) >= 40 &&
        Number(height) <= 300 &&
        Number(weight) >= 20 &&
        Number(weight) <= 500
            ? Number(weight) / (Number(height) / 100) ** 2
            : null;
    return (
        <div className="bmi-summary">
            <span>BMI (calculated)</span>
            <strong>
                {bmi && Number.isFinite(bmi) ? bmi.toFixed(1) : "—"}
            </strong>
            <p>{bmiNote}</p>
        </div>
    );
}
export function profileDefaults(profile = {}, name) {
    profile ||= {};
    return {
        ...(name !== undefined ? { name } : {}),
        date_of_birth: profile.date_of_birth || "",
        height_cm: profile.height_cm ?? "",
        current_weight_kg: profile.current_weight_kg ?? "",
        training_goal: profile.training_goal || "",
        body_weight_goal: profile.body_weight_goal || "not_tracking",
        target_body_weight_kg: profile.target_body_weight_kg ?? "",
        experience_level: profile.experience_level || "",
        training_days_per_week: profile.training_days_per_week ?? "",
        session_duration_minutes: profile.session_duration_minutes ?? "",
        equipment_access: profile.equipment_access || [],
    };
}

export default function ProfileFields({ form, options, section, today }) {
    const { data, errors, setData } = form;
    const input = (key) => ({
        value: data[key],
        onChange: (event) => setData(key, event.target.value),
    });
    if (section === "about")
        return (
            <>
                {data.name !== undefined && (
                    <Field label="Name" required error={errors.name}>
                        <input
                            required
                            maxLength={100}
                            autoComplete="name"
                            {...input("name")}
                        />
                    </Field>
                )}
                <Field
                    label="Date of birth (optional)"
                    error={errors.date_of_birth}
                >
                    <input
                        type="date"
                        max={today}
                        value={data.date_of_birth}
                        onInput={(event) =>
                            setData("date_of_birth", event.target.value)
                        }
                    />
                </Field>
                <div className="form-grid">
                    <Field label="Height (optional)" error={errors.height_cm}>
                        <div className="input-with-unit">
                            <input
                                type="number"
                                min="40"
                                max="300"
                                step="0.01"
                                inputMode="decimal"
                                {...input("height_cm")}
                            />
                            <span>cm</span>
                        </div>
                    </Field>
                    <Field
                        label="Current Body Weight (optional)"
                        error={errors.current_weight_kg}
                    >
                        <div className="input-with-unit">
                            <input
                                type="number"
                                min="20"
                                max="500"
                                step="0.01"
                                inputMode="decimal"
                                {...input("current_weight_kg")}
                            />
                            <span>kg</span>
                        </div>
                    </Field>
                </div>
                <p className="field-hint">
                    A new body weight is recorded for today. Earlier entries
                    stay in Progress. Leaving this blank keeps any recorded
                    weight.
                </p>
                <BmiSummary
                    height={data.height_cm}
                    weight={data.current_weight_kg}
                />
            </>
        );
    if (section === "goal")
        return (
            <fieldset className="profile-choices">
                <legend>Primary training goal (optional)</legend>
                {Object.entries(options.goals).map(([key, label]) => (
                    <label
                        className={`choice-card ${data.training_goal === key ? "selected" : ""}`}
                        key={key}
                    >
                        <input
                            type="radio"
                            name="training_goal"
                            value={key}
                            checked={data.training_goal === key}
                            onChange={() => setData("training_goal", key)}
                        />
                        <span>{label}</span>
                    </label>
                ))}
                <button
                    className="text-button"
                    type="button"
                    onClick={() => setData("training_goal", "")}
                >
                    Clear goal
                </button>
                {errors.training_goal && (
                    <p className="field-error" role="alert">
                        {errors.training_goal}
                    </p>
                )}
            </fieldset>
        );
    return (
        <>
            <div className="form-grid">
                <Field
                    label="Experience level (optional)"
                    error={errors.experience_level}
                >
                    <select {...input("experience_level")}>
                        <option value="">Select experience</option>
                        {Object.entries(options.levels).map(([key, label]) => (
                            <option value={key} key={key}>
                                {label}
                            </option>
                        ))}
                    </select>
                </Field>
                <Field
                    label="Training days per week (optional)"
                    error={errors.training_days_per_week}
                >
                    <select {...input("training_days_per_week")}>
                        <option value="">Select days</option>
                        {[1, 2, 3, 4, 5, 6, 7].map((n) => (
                            <option key={n} value={n}>
                                {n} {n === 1 ? "day" : "days"}
                            </option>
                        ))}
                    </select>
                </Field>
            </div>
            <Field
                label="Typical session duration (optional)"
                error={errors.session_duration_minutes}
            >
                <select {...input("session_duration_minutes")}>
                    <option value="">Select duration</option>
                    {[30, 45, 60, 90].map((n) => (
                        <option key={n} value={n}>
                            {n} minutes
                        </option>
                    ))}
                </select>
            </Field>
            <fieldset className="profile-choices">
                <legend>Equipment access (optional)</legend>
                <div className="equipment-options">
                    {Object.entries(options.equipment).map(([key, label]) => (
                        <label className="checkbox" key={key}>
                            <input
                                type="checkbox"
                                checked={data.equipment_access.includes(key)}
                                onChange={(event) =>
                                    setData(
                                        "equipment_access",
                                        event.target.checked
                                            ? [...data.equipment_access, key]
                                            : data.equipment_access.filter(
                                                  (item) => item !== key,
                                              ),
                                    )
                                }
                            />
                            {label}
                        </label>
                    ))}
                </div>
                {(errors.equipment_access || errors["equipment_access.0"]) && (
                    <p className="field-error" role="alert">
                        {errors.equipment_access ||
                            errors["equipment_access.0"]}
                    </p>
                )}
                <p className="field-hint">
                    For home gyms, also select Dumbbells if you have them.
                    Otherwise recommendations use bodyweight exercises.
                </p>
            </fieldset>
            <div className="form-grid">
                <Field label="Body weight goal" error={errors.body_weight_goal}>
                    <select
                        value={data.body_weight_goal}
                        onChange={(event) => {
                            setData((values) => ({
                                ...values,
                                body_weight_goal: event.target.value,
                                target_body_weight_kg: [
                                    "lose",
                                    "gain",
                                ].includes(event.target.value)
                                    ? values.target_body_weight_kg
                                    : "",
                            }));
                        }}
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
                {["lose", "gain"].includes(data.body_weight_goal) && (
                    <Field
                        label="Target Body Weight (optional)"
                        error={errors.target_body_weight_kg}
                    >
                        <div className="input-with-unit">
                            <input
                                type="number"
                                min="20"
                                max="500"
                                step="0.01"
                                inputMode="decimal"
                                {...input("target_body_weight_kg")}
                            />
                            <span>kg</span>
                        </div>
                    </Field>
                )}
            </div>
            <p className="field-hint">
                Your body weight goal is separate from your training goal.
                Weight check-ins are optional.
            </p>
        </>
    );
}
