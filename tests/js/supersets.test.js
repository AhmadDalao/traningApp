import test from "node:test";
import assert from "node:assert/strict";
import {
    saveExerciseWithPartner,
    clearSingleExerciseGroups,
    supersetPartners,
} from "../../resources/js/Features/supersets.js";

const exercise = (localKey, name, superset_group = null) => ({
    localKey,
    name,
    superset_group,
    sets: 3,
    reps: "12",
    icon: "dumbbell",
});

test("new unsaved exercises can be paired by selecting a named partner", () => {
    const first = exercise("unsaved-1", "Leg Extension");
    const result = saveExerciseWithPartner(
        [first],
        exercise("unsaved-2", "Leg Curl"),
        "unsaved-1",
    );
    assert.equal(result.length, 2);
    assert.ok(result[0].superset_group);
    assert.equal(result[0].superset_group, result[1].superset_group);
    assert.deepEqual(
        supersetPartners(result[1], result).map((item) => item.name),
        ["Leg Extension"],
    );
    assert.equal(
        first.superset_group,
        null,
        "cancelable editor must not mutate original exercises",
    );
});

test("editing an existing pair preserves its group and selected icon", () => {
    const items = [
        exercise(1, "Leg Extension", "A"),
        exercise(2, "Leg Curl", "A"),
    ];
    const result = saveExerciseWithPartner(
        items,
        { ...items[0], icon: "footprints", reps: "8-10" },
        "2",
    );
    assert.equal(result.length, 2);
    assert.deepEqual(
        result.map((item) => item.superset_group),
        ["A", "A"],
    );
    assert.equal(result[0].icon, "footprints");
    assert.equal(result[0].reps, "8-10");
});

test("choosing no superset unlinks both exercises in a pair", () => {
    const items = [
        exercise(1, "Leg Extension", "A"),
        exercise(2, "Leg Curl", "A"),
    ];
    assert.deepEqual(
        saveExerciseWithPartner(items, items[0], "").map(
            (item) => item.superset_group,
        ),
        [null, null],
    );
});

test("joining an existing group keeps its members linked", () => {
    const items = [
        exercise(1, "Lateral Raise", "B"),
        exercise(2, "Front Raise", "B"),
    ];
    const result = saveExerciseWithPartner(
        items,
        exercise("new", "Shoulder Press"),
        "2",
    );
    assert.deepEqual(
        result.map((item) => item.superset_group),
        ["B", "B", "B"],
    );
    assert.equal(supersetPartners(result[2], result).length, 2);
});

test("switching partners detaches only the edited exercise from its former group", () => {
    const items = [
        exercise(1, "One", "A"),
        exercise(2, "Two", "A"),
        exercise(3, "Three", "B"),
        exercise(4, "Four", "B"),
    ];
    const result = saveExerciseWithPartner(items, items[0], "3");
    assert.deepEqual(
        result.map((item) => item.superset_group),
        ["B", null, "B", "B"],
    );
});

test("removing one member keeps larger groups and clears a lone partner", () => {
    const items = [
        exercise(1, "One", "A"),
        exercise(2, "Two", "A"),
        exercise(3, "Three", "A"),
    ];
    assert.deepEqual(
        clearSingleExerciseGroups(items.slice(1)).map(
            (item) => item.superset_group,
        ),
        ["A", "A"],
    );
    assert.equal(
        clearSingleExerciseGroups(items.slice(2))[0].superset_group,
        null,
    );
});

test("new groups do not accidentally join another pair and work after Z", () => {
    const items = Array.from({ length: 26 }, (_, i) => [
        exercise(`a${i}`, "A", String.fromCharCode(65 + i)),
        exercise(`b${i}`, "B", String.fromCharCode(65 + i)),
    ]).flat();
    const target = exercise("standalone", "Target");
    const result = saveExerciseWithPartner(
        [...items, target],
        exercise("new", "New"),
        "standalone",
    );
    assert.equal(result.at(-1).superset_group, "AA");
    assert.equal(result.at(-2).superset_group, "AA");
    assert.equal(result[0].superset_group, "A");
});

test("partner names stay accurate after reordering and duplicate exercise names", () => {
    const items = [
        exercise(2, "Squat", "A"),
        exercise(1, "Squat", "A"),
        exercise(3, "Squat"),
    ];
    assert.deepEqual(
        supersetPartners(items[0], items).map((item) => item.localKey),
        [1],
    );
    const result = saveExerciseWithPartner(items, items[1], "3");
    assert.equal(result[0].superset_group, null);
    assert.equal(result[1].superset_group, result[2].superset_group);
});

test("a row cannot be linked to itself or to a missing exercise", () => {
    const row = exercise(1, "Squat");
    assert.equal(
        saveExerciseWithPartner([row], row, "1")[0].superset_group,
        null,
    );
    assert.equal(
        saveExerciseWithPartner([row], row, "missing")[0].superset_group,
        null,
    );
});

test("a library partner is added after the first exercise with independent settings and media", () => {
    const draft = { ...exercise("first", "Sit-Up"), sets: 4, reps: "12", target_weight: 50 };
    const partner = {
        ...exercise("partner", "Leg Curl"), sets: 2, reps: "15", target_weight: 25,
        library_key: "builtin:Seated_Leg_Curl", thumbnail_url: "/exercise-library/Seated_Leg_Curl.jpg",
        custom_icon: { name: "icon.png" },
    };
    const original = [];
    const result = saveExerciseWithPartner(original, draft, "partner", partner);
    assert.deepEqual(result.map((item) => item.localKey), ["first", "partner"]);
    assert.deepEqual(result.map((item) => [item.sets, item.reps, item.target_weight]), [[4, "12", 50], [2, "15", 25]]);
    assert.ok(result[0].superset_group);
    assert.equal(result[0].superset_group, result[1].superset_group);
    assert.equal(result[1].library_key, partner.library_key);
    assert.equal(result[1].thumbnail_url, partner.thumbnail_url);
    assert.equal(result[1].custom_icon, partner.custom_icon);
    assert.deepEqual(original, []);
    assert.equal(draft.superset_group, null);
    assert.equal(partner.superset_group, null);
});

test("switching an existing exercise to a library partner keeps its position and clears the old pair", () => {
    const items = [exercise(1, "One", "A"), exercise(2, "Two", "A"), exercise(3, "Three")];
    const partner = exercise("library", "New partner");
    const result = saveExerciseWithPartner(items, { ...items[0], reps: "30 seconds" }, "library", partner);
    assert.deepEqual(result.map((item) => item.localKey), [1, "library", 2, 3]);
    assert.equal(result[0].reps, "30 seconds");
    assert.equal(result[0].superset_group, result[1].superset_group);
    assert.ok(result[0].superset_group);
    assert.equal(result[2].superset_group, null);
    assert.deepEqual(items.map((item) => item.superset_group), ["A", "A", null]);
});

test("an unselected library partner is not added when choosing no superset or an existing exercise", () => {
    const existing = exercise("existing", "Existing");
    const draft = exercise("draft", "Draft");
    const unused = exercise("unused", "Unused");
    for (const selected of ["", "existing"]) {
        const result = saveExerciseWithPartner([existing], draft, selected, unused);
        assert.deepEqual(result.map((item) => item.localKey), ["existing", "draft"]);
        assert.equal(Boolean(result[1].superset_group), Boolean(selected));
    }
});

test("repeated pair updates do not duplicate a newly added library partner", () => {
    const draft = exercise("draft", "Draft");
    const partner = exercise("partner", "Partner");
    const first = saveExerciseWithPartner([], draft, "partner", partner);
    const second = saveExerciseWithPartner(first, { ...first[0], reps: "20" }, "partner", partner);
    assert.equal(second.length, 2);
    assert.equal(second[0].reps, "20");
    assert.equal(second[0].superset_group, second[1].superset_group);
});
