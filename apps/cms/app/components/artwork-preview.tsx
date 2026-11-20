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
            onError={() => setFailed(url)}
          />
        ) : (
          <span>{url ? "Artwork unavailable" : "No artwork selected"}</span>
        )}
      </div>
      <figcaption>{label}</figcaption>
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
