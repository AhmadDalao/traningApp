import { Field, Button, DateLabel } from "../Components/UI";
export default function PlanPeriodFields({ form, required = false }) {
    const ongoing = form.data.schedule_mode === "ongoing";
    return (
        <>
            <Field label="Schedule type" error={form.errors.schedule_mode}>
                <select
                    value={form.data.schedule_mode || "fixed"}
                    onChange={(e) =>
                        form.setData({
                            ...form.data,
                            schedule_mode: e.target.value,
                            end_date: "",
                        })
                    }
                >
                    <option value="fixed">Fixed period</option>
                    <option value="ongoing">
                        Ongoing — repeats until stopped
                    </option>
                </select>
            </Field>
            <div className="form-grid">
                <Field label="Plan starts" error={form.errors.start_date}>
                    <input
                        type="date"
                        value={form.data.start_date}
                        required={required || ongoing || !!form.data.end_date}
                        max={
                            !ongoing
                                ? form.data.end_date || undefined
                                : undefined
                        }
                        onInput={(e) =>
                            form.setData("start_date", e.target.value)
                        }
                    />
                </Field>
                {!ongoing && (
                    <Field label="Plan ends" error={form.errors.end_date}>
                        <input
                            type="date"
                            value={form.data.end_date}
                            required={required || !!form.data.start_date}
                            min={form.data.start_date || undefined}
                            onInput={(e) =>
                                form.setData("end_date", e.target.value)
                            }
                        />
                    </Field>
                )}
            </div>
            {ongoing && form.data.end_date && (
                <p className="field-hint">
                    This schedule stops after{" "}
                    <DateLabel date={form.data.end_date} />.{" "}
                    <Button
                        type="button"
                        variant="secondary"
                        className="small"
                        onClick={() => form.setData("end_date", "")}
                    >
                        Remove stop date
                    </Button>
                </p>
            )}
            {ongoing && !form.data.end_date && (
                <p className="field-hint">
                    No end date. Confirm the first schedule in Manage Schedule
                    to start rolling generation. You can stop it later.
                </p>
            )}
        </>
    );
}
