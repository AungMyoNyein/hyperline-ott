"use client";
import { useEffect, useRef } from "react";
export default function Confirmation({
  title,
  description,
  onClose,
}: {
  title: string;
  description: string;
  onClose: (confirmed: boolean) => void;
}) {
  const ref = useRef<HTMLDialogElement>(null);
  useEffect(() => {
    const dialog = ref.current;
    const previous = document.activeElement as HTMLElement | null;
    dialog?.showModal();
    return () => {
      dialog?.close();
      previous?.focus();
    };
  }, []);
  return (
    <dialog
      ref={ref}
      className="confirmation"
      aria-labelledby="confirmation-title"
      aria-describedby="confirmation-description"
      onCancel={(e) => {
        e.preventDefault();
        onClose(false);
      }}
    >
      <p className="eyebrow">PLEASE CONFIRM</p>
      <h2 id="confirmation-title">{title}</h2>
      <p id="confirmation-description" className="muted">
        {description}
      </p>
      <div className="dialog-actions">
        <button className="secondary" autoFocus onClick={() => onClose(false)}>
          Cancel
        </button>
        <button onClick={() => onClose(true)}>Delete movie</button>
      </div>
    </dialog>
  );
}
