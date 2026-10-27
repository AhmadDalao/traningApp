import test from "node:test";
import assert from "node:assert/strict";
import {
    parseDuration,
    formatDuration,
    workoutProgress,
} from "../../resources/js/Features/workout.js";

test("mm:ss is converted to integer seconds and round-trips", () => {
    for (const [text, seconds] of [
        ["00:01", 1],
        ["01:30", 90],
        ["52:59", 3179],
        ["1440:00", 86400],
    ]) {
        assert.equal(parseDuration(text), seconds);
        assert.equal(formatDuration(seconds), text);
    }
    assert.equal(parseDuration(" 01:30 "), 90);
    assert.equal(parseDuration(""), null);
    assert.equal(formatDuration(null), "");
    assert.equal(formatDuration(0), "00:00");
});

test("invalid, ambiguous, negative and out-of-range durations are rejected", () => {
    for (const value of [
        "90",
        "1:2",
        "00:60",
        "-1:30",
        "1.5:00",
        "00:00",
        "1440:01",
        "nope",
        "1:30:00",
    ]) {
        assert.ok(Number.isNaN(parseDuration(value)), value);
    }
});

test("progress counts only completed results and excludes skipped work from finish warnings", () => {
    const result = workoutProgress([
        { status: "completed", sets: [{ completed_at: "now" }] },
        {
            status: "active",
            sets: [{ completed_at: "now" }, { completed_at: null }],
        },
        {
            status: "skipped",
            sets: [{ completed_at: "now" }, { completed_at: null }],
        },
    ]);
    assert.deepEqual(result, {
        exercises: 3,
        completedExercises: 1,
        sets: 5,
        completedSets: 3,
        incompleteSets: 1,
    });
});

test("empty workouts have no fabricated completed exercises or sets", () => {
    assert.deepEqual(workoutProgress([]), {
        exercises: 0,
        completedExercises: 0,
        sets: 0,
        completedSets: 0,
        incompleteSets: 0,
    });
});
