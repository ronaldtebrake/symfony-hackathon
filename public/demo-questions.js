// Pure command construction: questions are quoted shell arguments, never shell syntax.
export function validateQuestion(text, maxBytes = 2000) {
  if (!text.trim()) throw new Error('Write a question about the published articles and their hero images.');
  if (new TextEncoder().encode(text).length > maxBytes || /[\p{Cc}\p{Cf}]/u.test(text.replace(/[\n\r\t]/g, ''))) {
    throw new Error('Use at most 2000 UTF-8 bytes without control or invisible formatting characters.');
  }
  return text.trim();
}

export function buildDemoCommand({mode, scenario, question = ''}, maxBytes = 2000) {
  if (!['reference', 'live'].includes(mode) || !['audit', 'impact', 'custom'].includes(scenario)) throw new Error('Choose a supported run and question.');
  if (mode === 'reference' && scenario !== 'audit') throw new Error('Changed questions need Live AI. The reference run only demonstrates the original audit.');
  if (mode === 'reference') return 'composer demo';
  const base = 'composer demo -- --mode=live --backend=fixture';
  if (scenario === 'custom') {
    const quoted = "'" + validateQuestion(question, maxBytes).replace(/'/g, "'\"'\"'") + "'";
    return `${base} --question=${quoted}`;
  }
  return scenario === 'impact' ? `${base} --scenario=impact` : base;
}
