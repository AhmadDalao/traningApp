import { formatDate, formatWeight } from "../Components/UI";

export default function BodyWeightChart({ entries }) {
    if (!entries.length) return null;
    const values = entries.map((entry) => Number(entry.weight_kg));
    const low = Math.min(...values) - 1,
        high = Math.max(...values) + 1;
    const first = Date.parse(`${entries[0].recorded_date}T12:00:00Z`),
        last = Date.parse(`${entries.at(-1).recorded_date}T12:00:00Z`);
    const points = entries.map((entry, index) => ({
        ...entry,
        x:
            last === first
                ? 225
                : 55 +
                  ((Date.parse(`${entry.recorded_date}T12:00:00Z`) - first) /
                      (last - first)) *
                      340,
        y: 190 - ((values[index] - low) / (high - low)) * 155,
    }));
    return (
        <figure className="weight-chart">
            <svg
                viewBox="0 0 450 240"
                role="img"
                aria-label={`Body weight trend: ${formatWeight(values[0])} on ${formatDate(entries[0].recorded_date, true)} to ${formatWeight(values.at(-1))} on ${formatDate(entries.at(-1).recorded_date, true)}`}
            >
                {[low, (low + high) / 2, high].map((value) => {
                    const y = 190 - ((value - low) / (high - low)) * 155;
                    return (
                        <g key={value}>
                            <line
                                x1="55"
                                x2="395"
                                y1={y}
                                y2={y}
                                stroke="#e7ebf1"
                            />
                            <text x="46" y={y + 4} textAnchor="end">
                                {value.toFixed(1)}
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
                        key={point.id}
                        cx={point.x}
                        cy={point.y}
                        r="4"
                        fill="#1763ef"
                    >
                        <title>
                            {formatDate(point.recorded_date, true)}:{" "}
                            {formatWeight(point.weight_kg)}
                        </title>
                    </circle>
                ))}
                <text x="55" y="220">
                    {formatDate(entries[0].recorded_date)}
                </text>
                <text x="395" y="220" textAnchor="end">
                    {formatDate(entries.at(-1).recorded_date)}
                </text>
            </svg>
            <figcaption>
                Body weight in kg.{" "}
                {entries.length === 1
                    ? "Record another entry to see a trend."
                    : "Each point is a recorded entry."}
            </figcaption>
        </figure>
    );
}
