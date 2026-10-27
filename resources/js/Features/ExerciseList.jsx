import { Link2 } from "lucide-react";
import { EmptyState, formatWeight } from "../Components/UI";
import ExerciseIcon from "../Components/ExerciseIcon";
import { supersetPartners } from "./supersets";

export default function ExerciseList({
    exercises = [],
    showActualWeights = false,
    emptyDescription = "Edit this training day to add your exercises.",
}) {
    return exercises.length ? (
        <div className="exercise-list">
            {exercises.map((exercise, i) => (
                <article
                    className={`exercise-item ${supersetPartners(exercise, exercises).length ? "superset-item" : ""}`}
                    key={exercise.id || i}
                >
                    <span className="row-number">
                        {String(i + 1).padStart(2, "0")}
                    </span>
                    {exercise.thumbnail_url ? (
                        <img
                            className="exercise-thumb"
                            src={exercise.thumbnail_url}
                            alt={exercise.name}
                        />
                    ) : (
                        <div className="exercise-thumb placeholder">
                            <ExerciseIcon exercise={exercise} size={23} />
                        </div>
                    )}
                    <div className="exercise-info">
                        <h3 className="exercise-name-with-icon"><ExerciseIcon exercise={exercise} size={17} />{exercise.name}</h3>
                        {exercise.notes && <p>{exercise.notes}</p>}
                        {supersetPartners(exercise, exercises).length > 0 && (
                            <span className="superset-badge superset-partners">
                                <Link2 size={12} />
                                Superset with{" "}
                                {supersetPartners(exercise, exercises)
                                    .map((item) => item.name)
                                    .join(" + ")}
                            </span>
                        )}
                    </div>
                    <div className="exercise-prescription">
                        <strong>
                            {exercise.sets}
                            <span>sets</span>
                        </strong>
                        <span className="times">×</span>
                        <strong>
                            {exercise.reps}
                            <span>reps / duration</span>
                        </strong>
                    </div>
                    <div className="exercise-weights">
                        <div>
                            <span>Planned Weight</span>
                            <strong>
                                {formatWeight(exercise.target_weight)}
                            </strong>
                        </div>
                        {showActualWeights && (
                            <div>
                                <span>Actual Weight Used</span>
                                <strong>
                                    {formatWeight(exercise.actual_weight)}
                                </strong>
                            </div>
                        )}
                    </div>
                </article>
            ))}
        </div>
    ) : (
        <EmptyState
            compact
            title="No exercises yet"
            description={emptyDescription}
        />
    );
}
