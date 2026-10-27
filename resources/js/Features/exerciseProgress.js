import { formatDuration } from "./workout.js";

export function progressNumber(value) {
    return Number(value).toLocaleString("en-GB", { maximumFractionDigits: 2 });
}

export function metricText(metric, value) {
    if (value === null || value === undefined) return "—";
    if (metric === "best_set")
        return `${progressNumber(value.weight)} kg × ${value.reps}`;
    if (["longest_duration", "total_duration"].includes(metric)) {
        return value < 60
            ? `${value} sec`
            : formatDuration(value).replace(/^0(?=\d:)/, "");
    }
    if (["max_reps", "total_reps"].includes(metric))
        return `${progressNumber(value)} reps`;
    return `${progressNumber(value)} kg`;
}
