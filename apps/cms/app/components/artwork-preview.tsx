"use client";
import { useState } from "react";
function Preview({
  url,
  label,
  shape,
}: {
  url: string;
  label: string;
  shape: string;
}) {
  const [failed, setFailed] = useState("");
  const [dimensions, setDimensions] = useState<{
    url: string;
    width: number;
    height: number;
  } | null>(null);
  const safe = /^https:\/\//.test(url);
  return (
    <figure>
      <div className={`artwork-preview ${shape}`}>
        {safe && failed !== url ? (
          <img
            src={url}
            alt={`${label} preview`}
            loading="lazy"
            referrerPolicy="no-referrer"
            style={
              /^https:\/\/i\.ytimg\.com\//.test(url)
                ? { objectFit: "contain" }
                : undefined
            }
            onLoad={(e) =>
              setDimensions({
                url,
                width: e.currentTarget.naturalWidth,
                height: e.currentTarget.naturalHeight,
              })
            }
            onError={() => setFailed(url)}
          />
        ) : (
          <span>{url ? "Artwork unavailable" : "No artwork selected"}</span>
        )}
      </div>
      <figcaption>
        {label}
        {dimensions?.url === url && (
          <small>
            {" "}
            · {dimensions.width} × {dimensions.height}
            {Math.abs(
              dimensions.width / dimensions.height -
                (shape === "portrait" ? 2 / 3 : 16 / 9),
            ) > 0.12
              ? " · Different aspect ratio; preview preserves original"
              : ""}
            {dimensions.width < (shape === "portrait" ? 600 : 1280)
              ? " · Below recommended resolution"
              : ""}
          </small>
        )}
      </figcaption>
    </figure>
  );
}
export default function ArtworkPreview({
  poster,
  backdrop,
}: {
  poster: string;
  backdrop: string;
}) {
  return (
    <div className="artwork-previews">
      <Preview url={poster} label="Poster · 2:3" shape="portrait" />
      <Preview url={backdrop} label="Backdrop · 16:9" shape="landscape" />
    </div>
  );
}
