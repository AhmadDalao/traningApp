import {
    Dumbbell,
    BicepsFlexed,
    Weight,
    Activity,
    HeartPulse,
    Bike,
    Footprints,
    PersonStanding,
    Accessibility,
    Flame,
    Target,
    Mountain,
    Waves,
    Timer,
    Wind,
    Coffee,
    Moon,
    Zap,
    Trophy,
} from "lucide-react";
import choices from "../../data/training-icons.json";

const icons = {
    dumbbell: Dumbbell,
    biceps: BicepsFlexed,
    weight: Weight,
    activity: Activity,
    heart: HeartPulse,
    bike: Bike,
    footprints: Footprints,
    bodyweight: PersonStanding,
    stretch: Accessibility,
    flame: Flame,
    target: Target,
    mountain: Mountain,
    waves: Waves,
    timer: Timer,
    wind: Wind,
    coffee: Coffee,
    moon: Moon,
    zap: Zap,
    trophy: Trophy,
};
export { choices as trainingIcons };

export default function TrainingIcon({
    icon,
    type = "training",
    focus,
    size = 22,
    ...props
}) {
    const automatic =
        type === "rest"
            ? "coffee"
            : {
                  Legs: "footprints",
                  Arms: "biceps",
                  Cardio: "activity",
                  Mobility: "stretch",
                  Core: "target",
                  "Full Body": "bodyweight",
              }[focus] || "dumbbell";
    const Icon = icons[icon] || icons[automatic];
    return <Icon size={size} aria-hidden="true" {...props} />;
}
