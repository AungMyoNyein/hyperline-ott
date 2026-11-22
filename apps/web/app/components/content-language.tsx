// Preserve the English interface language while labelling Myanmar content.
export function contentLang(text: unknown) {
  return typeof text === "string" &&
    /[\u1000-\u109f\uaa60-\uaa7f\ua9e0-\ua9ff]/u.test(text)
    ? "my"
    : undefined;
}
