import test from "node:test";
import assert from "node:assert/strict";
import {
    filterPlans,
    emptyPlanFilters,
} from "../../resources/js/Features/planDiscovery.js";
const base = {
    name: "Strength",
    description: "",
    level: "beginner",
    level_label: "Beginner",
    equipment_label: "Gym",
    goal_labels: [],
    split: [],
    goals: ["get_stronger"],
    days_per_week: 3,
};
test("program discovery type composes with existing filters and legacy simple plans", () => {
    const plans = [
        { ...base, key: "simple" },
        { ...base, key: "program", structure_type: "program" },
        {
            ...base,
            key: "advanced",
            structure_type: "program",
            level: "advanced",
        },
    ];
    assert.equal(filterPlans(plans, emptyPlanFilters).length, 3);
    assert.deepEqual(
        filterPlans(plans, { ...emptyPlanFilters, type: "simple" }).map(
            (p) => p.key,
        ),
        ["simple"],
    );
    assert.deepEqual(
        filterPlans(
            plans,
            {
                ...emptyPlanFilters,
                type: "program",
                level: "beginner",
                days: "3",
            },
            "strength",
        ).map((p) => p.key),
        ["program"],
    );
    assert.equal(
        filterPlans(plans, { ...emptyPlanFilters, type: "program", days: "4" })
            .length,
        0,
    );
});
