import { useEffect, useState } from "react";
import TrainingIcon from "./TrainingIcon";

export default function ExerciseIcon({ exercise, size = 22 }) {
    const [preview, setPreview] = useState(null);
    useEffect(() => {
        if (!exercise.custom_icon) { setPreview(null); return; }
        const url = URL.createObjectURL(exercise.custom_icon);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [exercise.custom_icon]);
    const url = preview || (!exercise.remove_custom_icon && exercise.custom_icon_url);
    return url ? <img className="custom-exercise-icon" src={url} width={size} height={size} alt="" /> : <TrainingIcon icon={exercise.icon} size={size} />;
}
