export function formatBytes(value: number | null | undefined): string {
  if (!value) {
    return '0 B';
  }

  if (value < 1024) {
    return `${value} B`;
  }

  if (value < 1024 * 1024) {
    return `${(value / 1024).toFixed(1)} KB`;
  }

  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}

export function eventPreview(payload: Record<string, unknown>): string {
  const text = JSON.stringify(payload);
  return text.length > 220 ? `${text.slice(0, 220)}…` : text;
}
