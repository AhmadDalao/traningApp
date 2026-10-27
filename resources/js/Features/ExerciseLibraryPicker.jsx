import { useMemo, useState } from "react";
import { Search, Plus, Check } from "lucide-react";
import { Field, Button } from "../Components/UI";
import TrainingIcon from "../Components/TrainingIcon";
import catalog from "../../data/exercise-library.json";

export default function ExerciseLibraryPicker({ customExercises = [], selected, onSelect, onCustom }) {
    const [query, setQuery] = useState("");
    const [focus, setFocus] = useState("");
    const [equipment, setEquipment] = useState("");
    const [limit, setLimit] = useState(24);
    const items = useMemo(() => [...customExercises, ...catalog], [customExercises]);
    const matches = items.filter((item) => {
        const text = [item.name, item.equipment, item.focus_area, item.category, ...(item.muscles || [])].join(" ").toLowerCase();
        return query.toLowerCase().trim().split(/\s+/).every((word) => text.includes(word))
            && (!focus || item.focus_area === focus)
            && (!equipment || (equipment === "mine" ? item.key.startsWith("custom:") : item.equipment === equipment));
    });
    return (
        <div className="exercise-library">
            <div className="library-search-row">
                <Field label="Find an exercise">
                    <div className="library-search-input">
                        <Search size={17} />
                        <input autoFocus type="search" value={query} placeholder="Search squats, bench press, cycling…"
                            onChange={(event) => { setQuery(event.target.value); setLimit(24); }} />
                    </div>
                </Field>
                <Field label="Muscle / focus">
                    <select value={focus} onChange={(event) => { setFocus(event.target.value); setLimit(24); }}>
                        <option value="">All focus areas</option>
                        {[...new Set(items.map((item) => item.focus_area).filter(Boolean))].sort().map((item) => <option key={item}>{item}</option>)}
                    </select>
                </Field>
                <Field label="Equipment">
                    <select value={equipment} onChange={(event) => { setEquipment(event.target.value); setLimit(24); }}>
                        <option value="">All equipment</option>
                        <option value="mine">My custom exercises</option>
                        {[...new Set(catalog.map((item) => item.equipment))].sort().map((item) => <option key={item}>{item}</option>)}
                    </select>
                </Field>
            </div>
            <div className="library-count">
                <span>{matches.length} {matches.length === 1 ? "exercise" : "exercises"}</span>
                <Button type="button" variant="secondary" className="small" onClick={onCustom}><Plus size={15} />Other / create custom</Button>
            </div>
            <div className="library-grid">
                {matches.slice(0, limit).map((item) => (
                    <button type="button" className={`library-card ${selected === item.key ? "selected" : ""}`}
                        key={item.key} onClick={() => onSelect(item)} aria-label={`Select ${item.name}`}>
                        {item.thumbnail_url ? <img src={item.thumbnail_url} alt="" loading="lazy" /> : <span className="library-placeholder"><TrainingIcon icon={item.icon} size={36} /></span>}
                        <span className="library-card-name">{item.name}{selected === item.key && <Check size={15} />}</span>
                        <small>{item.focus_area || "Custom"} · {item.key.startsWith("custom:") ? "My exercise" : item.equipment}</small>
                    </button>
                ))}
            </div>
            {!matches.length && <p className="field-hint">No match. Try a different name or create your own exercise.</p>}
            {matches.length > limit && <Button type="button" variant="secondary" className="library-more" onClick={() => setLimit(limit + 24)}>Show more exercises</Button>}
            <p className="library-credit">Built-in library: <a href="https://github.com/yuhonas/free-exercise-db" target="_blank" rel="noreferrer">Free Exercise DB</a> · Public domain</p>
        </div>
    );
}
