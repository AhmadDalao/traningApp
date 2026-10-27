import test from "node:test";
import assert from "node:assert/strict";
import {
    metricText,
    progressNumber,
} from "../../resources/js/Features/exerciseProgress.js";

test("progress weights and volume preserve decimals without excessive precision", () => {
    assert.equal(metricText("heaviest_weight", 82.5), "82.5 kg");
    assert.equal(metricText("weighted_volume", 2887.5), "2,887.5 kg");
    assert.equal(progressNumber(2887.501), "2,887.5");
    assert.equal(
        metricText("best_set", { weight: 82.5, reps: 8 }),
        "82.5 kg × 8",
    );
});
test("missing weighted results stay missing and reps remain reps", () => {
    assert.equal(metricText("heaviest_weight", null), "—");
    assert.equal(metricText("weighted_volume", undefined), "—");
    assert.equal(metricText("max_reps", 20), "20 reps");
    assert.equal(metricText("total_reps", 53), "53 reps");
});
test("durations reuse the workout formatter with readable units", () => {
    assert.equal(metricText("longest_duration", 45), "45 sec");
    assert.equal(metricText("longest_duration", 90), "1:30");
    assert.equal(metricText("total_duration", 135), "2:15");
    assert.equal(metricText("total_duration", 600), "10:00");
    assert.equal(metricText("longest_duration", null), "—");
});
