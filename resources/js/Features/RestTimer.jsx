import { useEffect, useState } from "react";
import { Timer } from "lucide-react";
import { Button } from "../Components/UI";
import { formatDuration } from "./workout";

export default function RestTimer() {
    const [preset, setPreset] = useState(60);
    const [remaining, setRemaining] = useState(60);
    const [deadline, setDeadline] = useState(null);
    useEffect(() => {
        if (!deadline) return;
        function tick() {
            const seconds = Math.max(
                0,
                Math.ceil((deadline - Date.now()) / 1000),
            );
            setRemaining(seconds);
            if (!seconds) setDeadline(null);
        }
        tick();
        const timer = setInterval(tick, 250);
        return () => clearInterval(timer);
    }, [deadline]);
    function reset(value = preset) {
        setDeadline(null);
        setRemaining(value);
    }
    return (
        <section className="panel rest-timer" aria-label="Rest timer">
            <div>
                <h2>
                    <Timer size={20} /> Rest timer
                </h2>
                <p>Optional. Your workout keeps going.</p>
            </div>
            <div className="rest-presets">
                {[60, 90, 120].map((value) => (
                    <button
                        key={value}
                        type="button"
                        aria-pressed={preset === value}
                        onClick={() => {
                            setPreset(value);
                            reset(value);
                        }}
                    >
                        {value} sec
                    </button>
                ))}
            </div>
            <strong
                className="timer-digits"
                role="timer"
                aria-label="Rest remaining"
            >
                {formatDuration(remaining)}
            </strong>
            {remaining === 0 && <span role="status">Rest complete</span>}
            <div className="rest-controls">
                <Button
                    variant="secondary"
                    onClick={() =>
                        deadline
                            ? setDeadline(null)
                            : setDeadline(
                                  Date.now() + (remaining || preset) * 1000,
                              )
                    }
                >
                    {deadline ? "Pause timer" : "Start timer"}
                </Button>
                <Button variant="secondary" onClick={() => reset()}>
                    Reset timer
                </Button>
            </div>
        </section>
    );
}
