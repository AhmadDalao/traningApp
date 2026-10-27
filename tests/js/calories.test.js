import test from "node:test";
import assert from "node:assert/strict";
import { calorieDisplay } from "../../resources/js/Features/calories.js";

test("reported calories take priority while estimates remain separate", () => {
    assert.deepEqual(
        calorieDisplay({
            calories_burned: 340,
            calories_source: "wearable",
            estimated_calories: 245,
        }),
        { value: "340 kcal", label: "Reported · Wearable" },
    );
});
test("zero reported calories are not replaced by an estimate", () => {
    assert.equal(
        calorieDisplay({ calories_burned: 0, estimated_calories: 245 }).value,
        "0 kcal",
    );
});
test("estimate-only values are explicitly approximate and missing values use a dash", () => {
    assert.deepEqual(calorieDisplay({ estimated_calories: 245 }), {
        value: "≈ 245 kcal",
        label: "Estimated",
    });
    assert.deepEqual(calorieDisplay({}), { value: "—", label: "" });
});
test("legacy manual values and each supported source are readable", () => {
    for (const [source, label] of [
        ["manual", "Manual entry"],
        ["exercise_machine", "Exercise machine"],
        [null, "Manual entry"],
    ]) {
        assert.deepEqual(
            calorieDisplay({ calories_burned: 410, calories_source: source }),
            { value: "410 kcal", label: `Reported · ${label}` },
        );
    }
});
