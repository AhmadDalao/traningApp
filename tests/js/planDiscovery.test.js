import test from "node:test";
import assert from "node:assert/strict";
import {
    filterPlans,
    emptyPlanFilters,
} from "../../resources/js/Features/planDiscovery.js";

const plans = [
    {
        name: "Bodyweight Foundations — 3 Days",
        description: "Floor-based strength and core",
        level: "beginner",
        level_label: "Beginner",
        equipment: "bodyweight",
        equipment_label: "Bodyweight",
        goals: ["general_fitness", "get_stronger"],
        goal_labels: ["General fitness", "Get stronger"],
        days_per_week: 3,
        split: ["Legs & Core", "Full Body"],
    },
    {
        name: "Upper / Lower + Arms — 5 Days",
        description: "Strength and muscle-building",
        level: "advanced",
        level_label: "Advanced",
        equipment: "full_gym",
        equipment_label: "Full gym",
        goals: ["build_muscle", "get_stronger"],
        goal_labels: ["Build muscle", "Get stronger"],
        days_per_week: 5,
        split: ["Upper Heavy", "Lower Heavy", "Arms & Shoulders"],
    },
];
test("cleared filters show every level without modifying source plans", () => {
    const before = JSON.stringify(plans);
    assert.deepEqual(filterPlans(plans, emptyPlanFilters), plans);
    assert.equal(JSON.stringify(plans), before);
});
test("level, goal, days, and equipment filters combine and accept select strings", () => {
    assert.deepEqual(
        filterPlans(plans, {
            level: "advanced",
            goal: "get_stronger",
            days: "5",
            equipment: "full_gym",
        }),
        [plans[1]],
    );
    assert.deepEqual(
        filterPlans(plans, {
            ...emptyPlanFilters,
            equipment: "bodyweight",
            level: "advanced",
        }),
        [],
    );
    assert.deepEqual(
        filterPlans(plans, { ...emptyPlanFilters, goal: "build_muscle" }),
        [plans[1]],
    );
});
test("search covers case-insensitive names, goals, descriptions, and weekly split", () => {
    for (const search of [
        "  ARMS  shoulders ",
        "build muscle",
        "full gym",
        "upper heavy",
    ]) {
        assert.deepEqual(filterPlans(plans, emptyPlanFilters, search), [
            plans[1],
        ]);
    }
    assert.deepEqual(filterPlans(plans, emptyPlanFilters, "floor core"), [
        plans[0],
    ]);
    assert.deepEqual(
        filterPlans(plans, { ...emptyPlanFilters, level: "beginner" }, "arms"),
        [],
    );
    assert.deepEqual(
        filterPlans(plans, emptyPlanFilters, "does not exist"),
        [],
    );
});
