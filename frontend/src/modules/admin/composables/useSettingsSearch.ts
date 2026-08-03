import { SETTINGS_SEARCH_INDEX } from '../settingsSearchIndex';

// Simple, dependency-free fuzzy matcher (design.md decision 8 explicitly
// rejects pulling in a search library for this). Accent-insensitive
// substring match wins over a subsequence match ("modelo" should rank
// above a same-length coincidental subsequence), and consecutive matched
// characters score higher than scattered ones so "moel" still beats noise.
function normalize(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();
}

function fuzzyScore(query: string, text: string): number | null {
  const q = normalize(query);
  const t = normalize(text);

  if (!q) return null;

  const substringIndex = t.indexOf(q);
  if (substringIndex !== -1) {
    // Earlier / start-of-word matches rank higher.
    const wordStart = substringIndex === 0 || t[substringIndex - 1] === ' ';
    return 1000 - substringIndex + (wordStart ? 50 : 0);
  }

  let queryIndex = 0;
  let score = 0;
  let lastMatchIndex = -1;

  for (let textIndex = 0; textIndex < t.length && queryIndex < q.length; textIndex++) {
    if (t[textIndex] === q[queryIndex]) {
      score += lastMatchIndex === textIndex - 1 ? 2 : 1;
      lastMatchIndex = textIndex;
      queryIndex++;
    }
  }

  return queryIndex === q.length ? score : null;
}

export interface SettingsSearchResult {
  id: string;
  panelPath: string;
  panelTitle: string;
  title: string;
  help?: string;
  highlightKey: string;
}

const MAX_RESULTS = 8;

/**
 * Scores every allowed index entry against `query` and returns the best
 * matches, deduped by destination (`panelPath` + `highlightKey`) so a
 * table-based panel with several aliases pointing at the same card doesn't
 * show up more than once.
 */
export function searchSettings(
  query: string,
  translate: (key: string) => string,
  isPanelAllowed: (panelPath: string) => boolean,
): SettingsSearchResult[] {
  const trimmed = query.trim();
  if (!trimmed) return [];

  const bestByTarget = new Map<string, SettingsSearchResult & { score: number }>();

  for (const entry of SETTINGS_SEARCH_INDEX) {
    if (!isPanelAllowed(entry.panelPath)) continue;

    const title = translate(entry.titleKey);
    const help = entry.helpKey ? translate(entry.helpKey) : undefined;
    const panelTitle = translate(entry.panelTitleKey);

    const titleScore = fuzzyScore(trimmed, title);
    const helpScore = help ? fuzzyScore(trimmed, help) : null;
    const panelScore = fuzzyScore(trimmed, panelTitle);

    const candidates = [
      titleScore == null ? null : titleScore + 200,
      helpScore,
      panelScore == null ? null : panelScore - 100,
    ].filter((value): value is number => value != null);

    if (candidates.length === 0) continue;

    const score = Math.max(...candidates);
    const targetKey = `${entry.panelPath}::${entry.highlightKey}`;
    const existing = bestByTarget.get(targetKey);

    if (!existing || score > existing.score) {
      bestByTarget.set(targetKey, {
        id: entry.id,
        panelPath: entry.panelPath,
        panelTitle,
        title,
        help,
        highlightKey: entry.highlightKey,
        score,
      });
    }
  }

  return Array.from(bestByTarget.values())
    .sort((a, b) => b.score - a.score)
    .slice(0, MAX_RESULTS)
    .map(({ score: _score, ...result }) => result);
}
