export function parseDuration(value) {
    if (!value.trim()) return null;
    if (!/^\d{1,4}:[0-5]\d$/.test(value.trim())) return NaN;
    const [minutes, seconds] = value.trim().split(":").map(Number);
    const total = minutes * 60 + seconds;
    return total > 0 && total <= 86400 ? total : NaN;
}

export function formatDuration(seconds) {
    if (seconds === null || seconds === undefined || seconds === "") return "";
    const value = Math.max(0, Math.floor(seconds));
    return `${Math.floor(value / 60)
        .toString()
        .padStart(2, "0")}:${(value % 60).toString().padStart(2, "0")}`;
}

export function workoutProgress(exercises) {
    const sets = exercises.flatMap((exercise) => exercise.sets);
    return {
        exercises: exercises.length,
        completedExercises: exercises.filter(
            (exercise) => exercise.status === "completed",
        ).length,
        sets: sets.length,
        completedSets: sets.filter((set) => set.completed_at).length,
        incompleteSets: exercises
            .filter((exercise) => exercise.status !== "skipped")
            .flatMap((exercise) => exercise.sets)
            .filter((set) => !set.completed_at).length,
    };
}
