import { useEffect, useState } from "react";
import ExerciseIcon from "./ExerciseIcon";
import PlanThumbnail from "./PlanThumbnail";

export default function ExerciseThumbnail({
    exercise,
    className = "exercise-thumb",
}) {
    const [preview, setPreview] = useState(null);
    useEffect(() => {
        if (!exercise.thumbnail) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(exercise.thumbnail);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [exercise.thumbnail]);
    const url =
        preview || (!exercise.remove_thumbnail && exercise.thumbnail_url);
    const placeholder = (
        <div className={`${className} placeholder`}>
            <ExerciseIcon exercise={exercise} size={26} />
        </div>
    );
    return (
        <PlanThumbnail
            src={url}
            name={exercise.name || "Exercise preview"}
            className={`exercise-image ${className}`}
            fallbackContent={placeholder}
        />
    );
}
