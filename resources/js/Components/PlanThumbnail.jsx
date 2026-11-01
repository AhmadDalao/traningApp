import { useState } from "react";

const fallback = "/images/gym.jpg";

export default function PlanThumbnail({ src }) {
    const [failed, setFailed] = useState(null);
    const image = src && failed !== src ? src : fallback;

    return (
        <img
            className="plan-thumbnail"
            src={image}
            alt=""
            width="640"
            height="360"
            loading="lazy"
            decoding="async"
            onError={() => {
                if (image !== fallback) setFailed(src);
            }}
        />
    );
}
