import { useState } from "react";
import { Link } from "@inertiajs/react";
import { formatDate } from "../Components/UI";
import { metricText } from "./exerciseProgress";

const labels = {
    heaviest_weight: [
        "Weight progression",
        "Heaviest completed weight in each workout",
    ],
    weighted_volume: [
        "Weighted volume",
        "Sum of positive actual weight × actual reps in each workout",
    ],
    max_reps: ["Rep progression", "Most reps in one completed set per workout"],
    longest_duration: [
        "Duration progression",
        "Longest completed set per workout",
    ],
};
export default function ExerciseProgressChart({ chart }) {
    const [selected, setSelected] = useState(null);
    const [title, description] = labels[chart.metric];
    const values = chart.points.map((point) => Number(point.value));
    const low = Math.min(...values),
        high = Math.max(...values);
    const padding =
        high === low ? Math.max(high * 0.05, 1) : (high - low) * 0.12;
    const bottom = Math.max(0, low - padding),
        top = high + padding;
    const first = Date.parse(`${chart.points[0]?.date}T12:00:00Z`),
        last = Date.parse(`${chart.points.at(-1)?.date}T12:00:00Z`);
    const points = chart.points.map((point) => ({
        ...point,
        x:
            first === last
                ? 310
                : 75 +
                  ((Date.parse(`${point.date}T12:00:00Z`) - first) /
                      (last - first)) *
                      470,
        y: 200 - ((point.value - bottom) / (top - bottom)) * 165,
    }));
    const current =
        points.find((point) => point.session_id === selected) || points.at(-1);
    return (
        <section className="panel exercise-chart-panel">
            <h2>{title}</h2>
            <p className="progress-explanation">{description}.</p>
            {!points.length ? (
                <p className="exercise-chart-empty">
                    No qualifying results in this period. Try a wider date
                    range.
                </p>
            ) : (
                <figure className="exercise-chart">
                    <svg
                        viewBox="0 0 600 250"
                        role="group"
                        aria-label={`${title}: ${points.length} completed workouts`}
                    >
                        {[bottom, (bottom + top) / 2, top].map((value) => {
                            const y =
                                200 - ((value - bottom) / (top - bottom)) * 165;
                            return (
                                <g key={value}>
                                    <line
                                        x1="75"
                                        x2="545"
                                        y1={y}
                                        y2={y}
                                        stroke="#e7ebf1"
                                    />
                                    <text x="64" y={y + 4} textAnchor="end">
                                        {chart.metric === "longest_duration"
                                            ? metricText(
                                                  chart.metric,
                                                  Math.round(value),
                                              )
                                            : new Intl.NumberFormat("en-GB", {
                                                  notation: "compact",
                                                  maximumFractionDigits: 1,
                                              }).format(value)}
                                    </text>
                                </g>
                            );
                        })}
                        <polyline
                            points={points
                                .map((point) => `${point.x},${point.y}`)
                                .join(" ")}
                            fill="none"
                            stroke="#1763ef"
                            strokeWidth="2.5"
                        />
                        {points.map((point) => (
                            <circle
                                key={point.session_id}
                                cx={point.x}
                                cy={point.y}
                                r={
                                    current.session_id === point.session_id
                                        ? 6
                                        : 4
                                }
                                fill="#1763ef"
                                tabIndex="0"
                                role="button"
                                aria-label={`${formatDate(point.date, true)}: ${metricText(chart.metric, point.value)}`}
                                onFocus={() => setSelected(point.session_id)}
                                onClick={() => setSelected(point.session_id)}
                                onKeyDown={(event) => {
                                    if (["Enter", " "].includes(event.key)) {
                                        event.preventDefault();
                                        setSelected(point.session_id);
                                    }
                                }}
                            >
                                <title>
                                    {formatDate(point.date, true)}:{" "}
                                    {metricText(chart.metric, point.value)}
                                </title>
                            </circle>
                        ))}
                        <text x="75" y="235">
                            {formatDate(points[0].date)}
                        </text>
                        <text x="545" y="235" textAnchor="end">
                            {formatDate(points.at(-1).date)}
                        </text>
                    </svg>
                    <p className="exercise-chart-selection">
                        <strong>
                            {metricText(chart.metric, current.value)}
                        </strong>
                        <span>{formatDate(current.date, true)}</span>
                    </p>
                    <figcaption>
                        <label>
                            Inspect workout
                            <select
                                value={current.session_id}
                                onChange={(event) =>
                                    setSelected(Number(event.target.value))
                                }
                                aria-label={`Inspect ${title.toLowerCase()} workout`}
                            >
                                {points.map((point, index) => (
                                    <option
                                        key={point.session_id}
                                        value={point.session_id}
                                    >
                                        {formatDate(point.date, true)} ·{" "}
                                        {metricText(chart.metric, point.value)}{" "}
                                        · {index + 1} of {points.length}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <Link
                            className="inline-link"
                            href={`/schedule-entries/${current.session_id}`}
                        >
                            View workout
                        </Link>
                    </figcaption>
                    {points.length === 1 && (
                        <p className="progress-explanation">
                            One recorded performance in this period. Log another
                            workout to see a trend.
                        </p>
                    )}
                </figure>
            )}
        </section>
    );
}
