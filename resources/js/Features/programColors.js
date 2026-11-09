// Stable across month navigation and both workspaces; labels also identify each program.
export function programColors(id) {
    if (!id)
        return { background: "#f1f5f9", border: "#64748b", text: "#334155" };
    const hue = Math.round((Number(id) * 137.508) % 360);
    return {
        background: `hsl(${hue} 70% 94%)`,
        border: `hsl(${hue} 55% 43%)`,
        text: `hsl(${hue} 55% 24%)`,
    };
}
