import { useRef, useState } from "react";
import { createPortal } from "react-dom";
import { Maximize2 } from "lucide-react";
import { Modal } from "./UI";

const fallback = "/images/gym.jpg";

export default function PlanThumbnail({ src, name = "Training plan" }) {
    const [failed, setFailed] = useState(null);
    const [open, setOpen] = useState(false);
    const trigger = useRef(null);
    const image = src && failed !== src ? src : fallback;
    const onError = () => {
        if (image !== fallback) setFailed(src);
    };

    return (
        <>
            <button
                ref={trigger}
                type="button"
                className="plan-thumbnail-trigger"
                aria-label={`View full image for ${name}`}
                aria-haspopup="dialog"
                onClick={() => setOpen(true)}
            >
                <img
                    className="plan-thumbnail"
                    src={image}
                    alt=""
                    width="640"
                    height="360"
                    loading="lazy"
                    decoding="async"
                    onError={onError}
                />
                <span className="thumbnail-expand" aria-hidden="true">
                    <Maximize2 size={15} />
                </span>
            </button>
            {open &&
                createPortal(
                    <Modal
                        title={name}
                        description="Full image"
                        wide
                        returnFocus={trigger.current}
                        onClose={() => setOpen(false)}
                    >
                        <div className="plan-image-preview">
                            <img src={image} alt={name} onError={onError} />
                            <a
                                className="btn btn-secondary"
                                href={image}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                Open original image
                            </a>
                        </div>
                    </Modal>,
                    document.body,
                )}
        </>
    );
}
