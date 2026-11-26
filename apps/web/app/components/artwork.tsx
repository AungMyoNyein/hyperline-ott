"use client";
import Image from "next/image";
import { useState } from "react";

/** External artwork stays in the browser; never proxy arbitrary image hosts. */
export default function Artwork({
  src,
  title,
  shape = "poster",
  priority = false,
}: {
  src?: string | null;
  title: string;
  shape?: "poster" | "landscape" | "vertical";
  priority?: boolean;
}) {
  const [failed, setFailed] = useState("");
  const [landscape, setLandscape] = useState("");
  const safe = !!src && /^(https?:\/\/|\/(?!\/))/.test(src);
  const landscapePreview =
    shape === "poster" &&
    (landscape === src || (!!src && /^https:\/\/i\.ytimg\.com\//.test(src)));
  return (
    <div
      className={`artwork artwork-${shape}`}
      data-landscape-preview={landscapePreview ? "true" : undefined}
      data-youtube-thumbnail={
        !!src && /^https:\/\/i\.ytimg\.com\//.test(src) ? "true" : undefined
      }
    >
      {safe && failed !== src ? (
        <Image
          key={src}
          src={src!}
          alt=""
          fill
          unoptimized
          sizes={
            shape === "landscape"
              ? "100vw"
              : "(max-width: 600px) 45vw, (max-width: 1000px) 25vw, 220px"
          }
          priority={priority}
          onLoad={(event) => {
            if (
              event.currentTarget.naturalWidth >
              event.currentTarget.naturalHeight
            )
              setLandscape(src!);
          }}
          onError={() => setFailed(src!)}
        />
      ) : (
        <div className="artwork-fallback" aria-hidden="true">
          <span className="brand-monogram">
            H<span>│</span>L
          </span>
          <span>{title}</span>
        </div>
      )}
      {landscapePreview && failed !== src && (
        <div className="artwork-preview-caption" aria-hidden="true">
          <span>Landscape preview</span>
          <strong>{title}</strong>
        </div>
      )}
    </div>
  );
}
