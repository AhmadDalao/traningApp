export const weekdays = ["Sunday", "Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"];

export function weekdaySummary(values = []) {
    return values.length ? values.map((day) => weekdays[Number(day)].slice(0, 3)).join(" · ") : "Schedule manually";
}

export default function WeekdayPicker({ value = [], onChange, error }) {
    return (
        <fieldset className="weekday-picker">
            <legend>Repeat this workout on</legend>
            <div className="weekday-options">
                {weekdays.map((label, day) => (
                    <label key={label} className={value.map(Number).includes(day) ? "selected" : ""}>
                        <input type="checkbox" aria-label={label} checked={value.map(Number).includes(day)}
                            onChange={(event) => onChange(event.target.checked ? [...value, day].map(Number).sort() : value.filter((item) => Number(item) !== day))} />
                        <span>{label.slice(0, 3)}</span>
                    </label>
                ))}
            </div>
            <div className="weekday-shortcuts">
                <button type="button" className="text-button" title="Sunday through Thursday" onClick={() => onChange([0, 1, 2, 3, 4])}>Weekdays</button>
                <button type="button" className="text-button" title="Friday and Saturday" onClick={() => onChange([5, 6])}>Weekends</button>
                <button type="button" className="text-button" onClick={() => onChange([0, 1, 2, 3, 4, 5, 6])}>Every day</button>
                <button type="button" className="text-button" onClick={() => onChange([])}>Clear</button>
            </div>
            <p className="field-hint">{value.length ? "Preview the plan schedule before adding these dates to Calendar." : "Leave empty to schedule this workout manually."}</p>
            {error && <p className="field-error" role="alert">{error}</p>}
        </fieldset>
    );
}
