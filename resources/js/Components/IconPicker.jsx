import { useState } from "react";
import { Check, ChevronDown, Search } from "lucide-react";
import TrainingIcon, { trainingIcons } from "./TrainingIcon";

export default function IconPicker({
    value,
    onChange,
    label = "Icon",
    type,
    focus,
    error,
}) {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState("");
    const selected = trainingIcons.find((item) => item.id === value);
    const options = [{ id: "", label: "Automatic" }, ...trainingIcons].filter(
        (item) => item.label.toLowerCase().includes(query.toLowerCase().trim()),
    );
    return (
        <div className="icon-picker">
            <span className="field-label">{label}</span>
            <button
                type="button"
                className="icon-picker-trigger"
                aria-label={`Choose ${label.toLowerCase()}: ${selected?.label || "Automatic"}`}
                aria-expanded={open}
                onClick={() => setOpen(!open)}
            >
                <span className="icon-picker-preview">
                    <TrainingIcon icon={value} type={type} focus={focus} />
                </span>
                <span>
                    <strong>{selected?.label || "Automatic"}</strong>
                    <small>Choose an icon to recognize it at a glance</small>
                </span>
                <ChevronDown size={16} />
            </button>
            {open && (
                <div className="icon-picker-options">
                    <label className="icon-search">
                        <Search size={16} />
                        <input
                            type="search"
                            aria-label={`Search ${label.toLowerCase()}s`}
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search icons…"
                        />
                    </label>
                    <div
                        className="icon-picker-grid"
                        role="group"
                        aria-label={`${label} choices`}
                    >
                        {options.map((option) => (
                            <button
                                type="button"
                                key={option.id}
                                aria-pressed={(value || "") === option.id}
                                className={`icon-choice ${(value || "") === option.id ? "selected" : ""}`}
                                onClick={() => {
                                    onChange(option.id);
                                    setOpen(false);
                                    setQuery("");
                                }}
                            >
                                <TrainingIcon
                                    icon={option.id}
                                    type={type}
                                    focus={focus}
                                    size={23}
                                />
                                <span>{option.label}</span>
                                {(value || "") === option.id && (
                                    <Check
                                        className="icon-choice-check"
                                        size={12}
                                    />
                                )}
                            </button>
                        ))}
                    </div>
                    {!options.length && (
                        <p className="field-hint">
                            No matching icons. Try “strength”, “cycling”, or
                            “rest”.
                        </p>
                    )}
                </div>
            )}
            {error && (
                <span className="field-error" role="alert">
                    {error}
                </span>
            )}
        </div>
    );
}
