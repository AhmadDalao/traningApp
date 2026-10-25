import { Dumbbell, Link2 } from "lucide-react";
import { EmptyState } from "../Components/UI";
export default function ExerciseList({ exercises = [] }) {
    return exercises.length ? (
        <div className="exercise-list">
            {exercises.map((exercise, i) => (
                <article
                    className={`exercise-item ${exercise.superset_group ? "superset-item" : ""}`}
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
                            <Dumbbell size={23} />
                        </div>
                    )}
                    <div className="exercise-info">
                        <h3>{exercise.name}</h3>
                        {exercise.notes && <p>{exercise.notes}</p>}
                        {exercise.superset_group && (
                            <span className="superset-badge">
                                <Link2 size={12} />
                                Superset {exercise.superset_group}
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
                </article>
            ))}
        </div>
    ) : (
        <EmptyState
            compact
            title="No exercises yet"
            description="Edit this training day to add your exercises."
        />
    );
}
