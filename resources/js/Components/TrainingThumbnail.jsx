import PlanThumbnail from "./PlanThumbnail";
import { DayIcon } from "./UI";

export default function TrainingThumbnail({ item, className = "" }) {
    if (!item) return null;
    if ((item.entry_type || item.type) === "rest") {
        return <DayIcon type="rest" icon={item.icon} />;
    }
    const days = item.training_days || item.days || [];
    const exercises =
        item.exercises ||
        days
            .filter((day) => day.type !== "rest")
            .flatMap((day) => day.exercises || []);
    const src =
        item.thumbnail_url ||
        exercises.find((exercise) => exercise.thumbnail_url)?.thumbnail_url ||
        days.find((day) => day.type !== "rest" && day.thumbnail_url)
            ?.thumbnail_url;
    return (
        <PlanThumbnail
            src={src}
            name={item.title || item.name || "Workout"}
            className={`training-thumbnail ${className}`}
        />
    );
}
