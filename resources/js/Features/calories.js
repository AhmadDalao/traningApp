export const calorieSources = {
    wearable: "Wearable",
    exercise_machine: "Exercise machine",
    manual: "Manual entry",
};

export function calorieDisplay(entry) {
    if (entry.calories_burned != null) {
        return {
            value: `${entry.calories_burned} kcal`,
            label: `Reported · ${calorieSources[entry.calories_source] || calorieSources.manual}`,
        };
    }
    return entry.estimated_calories != null
        ? { value: `≈ ${entry.estimated_calories} kcal`, label: "Estimated" }
        : { value: "—", label: "" };
}
