// Stable local keys let newly added exercises be paired before anything is saved.
const keyOf = (exercise) => String(exercise.localKey ?? exercise.id);

export function supersetPartners(exercise, exercises) {
    if (!exercise.superset_group) return [];
    return exercises.filter(
        (other) =>
            keyOf(other) !== keyOf(exercise) &&
            other.superset_group === exercise.superset_group,
    );
}

export function clearSingleExerciseGroups(exercises) {
    const counts = new Map();
    for (const exercise of exercises) {
        if (exercise.superset_group)
            counts.set(
                exercise.superset_group,
                (counts.get(exercise.superset_group) || 0) + 1,
            );
    }
    return exercises.map((exercise) => ({
        ...exercise,
        superset_group:
            counts.get(exercise.superset_group) > 1
                ? exercise.superset_group
                : null,
    }));
}

function nextGroup(exercises) {
    const used = new Set(exercises.map((exercise) => exercise.superset_group));
    for (let index = 0; ; index++) {
        let number = index + 1;
        let label = "";
        while (number > 0) {
            label = String.fromCharCode(65 + ((number - 1) % 26)) + label;
            number = Math.floor((number - 1) / 26);
        }
        if (!used.has(label)) return label;
    }
}

export function saveExerciseWithPartner(exercises, draft, partnerKey, libraryPartner = null) {
    if (
        libraryPartner && keyOf(libraryPartner) === String(partnerKey) &&
        keyOf(libraryPartner) !== keyOf(draft) &&
        !exercises.some((item) => keyOf(item) === keyOf(libraryPartner))
    ) {
        exercises = [...exercises];
        let index = exercises.findIndex((item) => keyOf(item) === keyOf(draft));
        if (index < 0) {
            index = exercises.length;
            exercises.push(draft);
        }
        exercises.splice(index + 1, 0, { ...libraryPartner, superset_group: null });
    }
    const partner = exercises.find(
        (exercise) =>
            keyOf(exercise) === String(partnerKey) &&
            keyOf(exercise) !== keyOf(draft),
    );
    const group = partner
        ? partner.superset_group || nextGroup(exercises)
        : null;
    const saved = { ...draft, superset_group: group };
    let found = false;
    const result = exercises.map((exercise) => {
        if (keyOf(exercise) === keyOf(saved)) {
            found = true;
            return saved;
        }
        if (partner && keyOf(exercise) === keyOf(partner))
            return { ...exercise, superset_group: group };
        return exercise;
    });
    if (!found) result.push(saved);
    return clearSingleExerciseGroups(result);
}
