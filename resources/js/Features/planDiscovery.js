export const emptyPlanFilters = {
    level: "",
    goal: "",
    days: "",
    equipment: "",
};

export function filterPlans(plans, filters, search = "") {
    const words = search
        .trim()
        .toLocaleLowerCase()
        .split(/\s+/)
        .filter(Boolean);
    return plans.filter((plan) => {
        const text = [
            plan.name,
            plan.description,
            plan.level_label,
            plan.equipment_label,
            ...plan.goal_labels,
            ...plan.split,
        ]
            .join(" ")
            .toLocaleLowerCase();
        return (
            (!filters.level || plan.level === filters.level) &&
            (!filters.goal || plan.goals.includes(filters.goal)) &&
            (!filters.days || plan.days_per_week === Number(filters.days)) &&
            (!filters.equipment || plan.equipment === filters.equipment) &&
            words.every((word) => text.includes(word))
        );
    });
}
