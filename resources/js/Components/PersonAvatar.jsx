export default function PersonAvatar({ name = "", large = false }) {
    const words = name.trim().split(/\s+/);
    const initials =
        `${words[0]?.[0] || "?"}${words.length > 1 ? words.at(-1)[0] : ""}`.toUpperCase();
    const tone =
        [...name].reduce((sum, letter) => sum + letter.charCodeAt(0), 0) % 4;
    return (
        <span
            className={`person-avatar tone-${tone}${large ? " large" : ""}`}
            aria-hidden="true"
        >
            {initials}
        </span>
    );
}
