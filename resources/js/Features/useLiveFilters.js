import { useEffect, useRef, useState } from "react";
import { router } from "@inertiajs/react";

// Inertia owns requests and cancellation. Typing replaces history; selects update now.
export default function useLiveFilters(
    path,
    initial,
    only,
    valid = () => true,
) {
    const [data, setData] = useState(initial);
    const [processing, setProcessing] = useState(false);
    const pending = useRef(null),
        timer = useRef(null),
        current = useRef(initial);
    const revision = useRef(0);
    const serialized = JSON.stringify(initial);
    useEffect(() => {
        current.current = initial;
        setData(initial);
    }, [serialized]);
    useEffect(
        () => () => {
            clearTimeout(timer.current);
            pending.current?.cancel();
        },
        [],
    );
    function change(name, value) {
        const next =
            typeof name === "object"
                ? name
                : { ...current.current, [name]: value };
        current.current = next;
        setData(next);
        clearTimeout(timer.current);
        const id = ++revision.current;
        pending.current?.cancel();
        pending.current = null;
        setProcessing(false);
        if (!valid(next)) return;
        const visit = () => {
            setProcessing(true);
            router.get(
                path,
                Object.fromEntries(
                    Object.entries(next).filter(
                        ([k, v]) =>
                            k !== "page" &&
                            v !== "" &&
                            v != null &&
                            (next.period !== undefined
                                ? next.period === "custom" ||
                                  !["from", "to"].includes(k)
                                : true),
                    ),
                ),
                {
                    only,
                    preserveState: true,
                    preserveScroll: true,
                    replace: true,
                    onCancelToken: (token) => {
                        pending.current = token;
                    },
                    onFinish: () => {
                        if (id === revision.current) {
                            pending.current = null;
                            setProcessing(false);
                        }
                    },
                },
            );
        };
        if (["search", "query"].includes(name))
            timer.current = setTimeout(visit, 300);
        else visit();
    }
    return { data, setData: change, processing };
}
