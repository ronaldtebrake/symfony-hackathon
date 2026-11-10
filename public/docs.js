/* Hash navigation works on Pages project subpaths and in the local Symfony viewer. */
const chapters = [...document.querySelectorAll('#documentation > section')];
const chapterLinks = [...document.querySelectorAll('.docs-nav a')];
function openChapter() {
  const requested = decodeURIComponent(location.hash.slice(1));
  const aliases = {'codex-run': 'agent-run', journey: 'version-two'};
  const id = aliases[requested] ?? (requested || 'overview');
  const target = document.getElementById(id);
  const chapter = chapters.find((item) => item === target || item.contains(target)) ?? chapters[0];
  chapters.forEach((item) => { item.hidden = item !== chapter; });
  chapterLinks.forEach((link) => {
    if (link.hash === `#${chapter.id}`) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  document.title = `${chapter.querySelector('h1,h2')?.innerText.replace(/\s+/g, ' ').trim()} · Symfony Code Mode`;
  window.scrollTo({top: 0, behavior: 'instant'});
}
window.addEventListener('hashchange', openChapter);
openChapter();
if (document.documentElement.dataset.static === 'true') {
  document.querySelectorAll('a[href^="/demo/"]').forEach((link) => { link.href = evidenceUrl(link.getAttribute('href')); });
}

// This guide consumes the same reviewed evidence as the detailed inspectors.
// It never invokes a model, MCP endpoint or PHP executor.
const demoShots = [
  {kind:8, time:'00:00–00:12', label:'Ask in your agent', title:'A real Symfony tool, from your chat.', source:'Authenticated HTTP proof · published article lookup', href:'#native-rehearsal', narration:"This is Symfony Code Mode. In Codex, I ask a separate Symfony application for a published article. The application exposes its approved tools through MCP."},
  {kind:9, time:'00:12–00:23', label:'Open the JSON call', title:'Search. Send arguments. Get a result.', source:'Authenticated HTTP proof · actual JSON calls', href:'#native-rehearsal', narration:"The agent discovers the contract, then sends a tool name and JSON arguments. Symfony runs the installed handler and returns the article."},
  {kind:10, time:'00:23–00:37', label:'Show the code', title:'Your Symfony service. Your policy.', source:'Installed Symfony source · handler and policy', href:'#native-rehearsal', narration:"Here is that handler, registered with Symfony AI. The application owns the permission check and publication rule. The catalog reuses its schemas and existing toolbox."},
  {kind:11, time:'00:37–00:50', label:'Test the boundary', title:'The follow-up cannot grant access.', source:'Authenticated HTTP proof · same connection, denied read', href:'#native-rehearsal', narration:"Now I ask for a restricted article. The host denies it before the handler runs. The agent can offer the published article we already retrieved."},
  {kind:6, time:'00:50–01:01', label:'Inspect the controls', title:'The host enforces execution limits.', source:'Fresh HTTP proof · access checks and persistent budgets', href:'#catalog-security', narration:"Execution has limits too: the call budget survives reconnects and server restarts. JSON does not prevent prompt injection; access checks remain essential."},
  {kind:5, time:'01:01–01:17', label:'Compare tokens', title:'Measure the whole task.', source:'Separate matched Symfony AI + Hugging Face audit', href:'#three-way', narration:"In a separate matched audit, all three paths found the same ten issues. JSON used thirty-six percent fewer tokens than direct tools. Isolated PHP saved more."},
  {kind:7, time:'01:17–01:30', label:'Use it in Symfony', title:'Choose the catalog. Keep your application.', source:'Configuration · independent Composer application', href:'#catalog-adoption', narration:"Choose catalog mode, register approved tools and a policy, then connect your agent. JSON needs no gVisor. The catalog runs as ordinary Symfony services over authenticated HTTP."},
];
function latestVerifiedCatalog() {
  return [...threeWayAttempts].reverse().find(attempt => /^audit-catalog-v/.test(attempt.protocol ?? '') && attempt.verification?.passed === true);
}
let demoShot = 0;
function proofLink(href, text) { const link = node('a', text); link.href = href; return link; }
function sourceBlock(text, php = false) { const pre = node('pre', text, 'tour-code'); pre.tabIndex = 0; if (php) PhpSyntax.render(pre, text); return pre; }
function showCostEstimate() {
  const output = byId('cost-estimate');
  const input = byId('cost-input-rate'); const completion = byId('cost-output-rate');
  if (!judgeComparison) { output.textContent = 'A verified pair with complete provider usage is required for an estimate.'; return; }
  const rates = [input.valueAsNumber, completion.valueAsNumber];
  if (rates.some((rate) => !Number.isFinite(rate) || rate < 0)) { output.textContent = 'Enter both non-negative rates to estimate the token charge for this pair.'; return; }
  const estimate = (run) => (run.providerTokens.prompt * rates[0] + run.providerTokens.completion * rates[1]) / 1000000;
  const direct = estimate(judgeComparison.direct.result); const code = estimate(judgeComparison.code.result);
  if (![direct, code].every(Number.isFinite)) { output.textContent = 'The rates are too large to calculate a finite estimate.'; return; }
  const format = (value) => new Intl.NumberFormat('en-US', {style:'currency', currency:'USD', minimumFractionDigits:6, maximumFractionDigits:6}).format(value);
  output.textContent = `At your rates, estimated token charge per audit: direct ${format(direct)} → Code Mode ${format(code)}. Difference: ${format(direct - code)}${direct > 0 ? ` (${Math.abs(100 * (direct - code) / direct).toFixed(1)}% ${direct >= code ? 'lower' : 'higher'})` : ''}. Estimates, not recorded charges.`;
}
byId('cost-input-rate').addEventListener('input', showCostEstimate);
byId('cost-output-rate').addEventListener('input', showCostEstimate);
function showDemoShot() {
  const shot = demoShots[demoShot]; const kind = shot.kind; const content = byId('tour-content'); content.replaceChildren();
  byId('tour-time').textContent = shot.time;
  byId('tour-source').textContent = shot.source;
  byId('tour-title').textContent = shot.title;
  byId('tour-narration').textContent = shot.narration;
  byId('tour-evidence').href = shot.href;
  byId('tour-back').disabled = demoShot === 0;
  byId('tour-next').disabled = demoShot === demoShots.length - 1;
  [...byId('tour-steps').children].forEach((button, index) => button.setAttribute('aria-pressed', String(index === demoShot)));
  if ([8, 9, 11].includes(kind)) {
    const report = typeof httpCatalogEvidence !== 'undefined' ? httpCatalogEvidence : null;
    if (kind === 8 || kind === 11) {
      content.append(node('span', 'PROMPT TO RECORD IN YOUR CLIENT', 'tour-label'), node('blockquote', kind === 8
        ? 'Use only the symfony_catalog MCP server to read published article 101 and tell me its title. Discover its contract first. Do not read workspace files or use shell commands.'
        : 'Now try article 202 once through the same tool. If access is denied, explain that and suggest a permitted alternative from what we already retrieved. Do not retry or use another access path.', 'tour-question'));
    }
    content.append(node('p', 'Actual operator-authored HTTP requests from a detached Symfony install. Prompts above are recording cues; record the real conversation in your client.', 'measurement-note'));
    if (report?.verification?.passed !== true) { content.append(node('p', 'Verified HTTP evidence is loading or unavailable.')); return; }
    const indexes = kind === 9 ? [0, 1] : [kind === 8 ? 1 : 2];
    for (const index of indexes) content.append(nativeCallCard(httpMainCalls()[index], index));
  } else if (kind === 10) {
    if (typeof httpCatalogEvidence === 'undefined' || httpCatalogEvidence?.verification?.passed !== true) { content.append(node('p', 'Verified application source is loading or unavailable.')); return; }
    for (const [role, label] of [['handler','INSTALLED HANDLER'], ['policy','HOST POLICY']]) {
      content.append(node('span', label, 'tour-label'), sourceBlock(nativeSourceExcerpt(role), true));
    }
    content.append(proofLink('#native-rehearsal', 'Inspect full source and HTTP calls →'), node('p', 'Source from this documentation build. Symfony AI registers publication_read through AsTool; the explicit allowlist selects it. The agent supplies JSON arguments.', 'measurement-note'));
  } else if (kind === 5) {
    const attempt = latestVerifiedCatalog();
    if (!attempt) { content.append(node('p', 'No fully verified catalog triplet is available. Failed attempts remain inspectable.')); return; }
    const table = node('table'); table.append(node('caption', 'Provider tokens · complete conversations, including review'));
    const head = node('thead'), header = node('tr'); ['Path', 'Tokens', 'Requests', 'Correct'].forEach(label => header.append(node('th', label))); head.append(header);
    const body = node('tbody');
    for (const [key, label] of [['direct','Direct tools'], ['batch','JSON catalog'], ['code','Sandboxed PHP']]) {
      const run = attempt.runs[key], row = node('tr');
      row.append(node('td', label), node('td', number(run.result.providerTokens.total)), node('td', number(run.result.modelRequests)), node('td', '10 / 10 findings, exact titles')); body.append(row);
    }
    table.append(head, body); const scroll = node('div', undefined, 'table-scroll'); scroll.append(table);
    content.append(scroll, node('p', `Captured ${new Date(attempt.createdAt).toLocaleDateString()}. Same 48 published articles, model, original question and permissions. Historical supervised-runtime capture; the HTTP runtime has not been re-benchmarked. All attempts remain retained.`, 'measurement-note'), node('p', 'JSON reduces deployment requirements. PHP can reduce intermediate data returned to the model. Actual charges depend on provider input/output pricing.', 'measurement-note'));
  } else if (kind === 6) {
    const report = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence?.capture;
    if (!report) { content.append(node('p', 'Current HTTP evidence is loading.')); return; }
    const list = node('div', undefined, 'tour-security');
    for (const label of ['restricted', 'exhausted', 'after_restart']) {
      const call = report.calls.find(row => row.label === label), item = node('article');
      item.append(node('h4', label.replaceAll('_', ' ')), node('strong', call.response.result.structuredContent.error.reason)); list.append(item);
    }
    content.append(list, node('p', 'Failures consume attempts. The server persists reservations before calling a handler. New HTTP requests, MCP sessions and server restarts do not reset the budget.'), node('p', 'Hard timeouts belong to the hosting and downstream services. JSON does not prevent prompt injection.', 'measurement-note'));

  } else {
    content.append(node('p','Symfony AI supplies the schemas and existing tool execution. The catalog exposes search and literal JSON calls, with Symfony Security, per-call authorization and persistent user budgets.'), sourceBlock("code_mode:\n    mode: catalog\n    ai_toolbox: app.toolbox\n    ai_tools: ['publication_read']\n    ai_authorization: Adoption\\ReadPolicy\n    http:\n        enabled: true\n        permissions:\n            publication.read_published: ROLE_READER"), sourceBlock('php bin/console code-mode:doctor\nHTTP MCP endpoint: /mcp'), node('p','Composer path packages today; not yet on Packagist. JSON runs in the application process with ordinary PHP and Symfony dependencies. The historical generated-PHP path has separate gVisor requirements.', 'measurement-note'));
  }
}
for (const [index, shot] of demoShots.entries()) {
  const button = node('button'); button.type = 'button';
  button.append(node('span', shot.time), node('strong', shot.label));
  button.addEventListener('click', () => { demoShot = index; showDemoShot(); byId('demo-tour').scrollIntoView({block:'start', behavior:'instant'}); });
  byId('tour-steps').append(button);
}
byId('tour-back').addEventListener('click', () => { demoShot = Math.max(0, demoShot - 1); showDemoShot(); byId('demo-tour').scrollIntoView({block:'start', behavior:'instant'}); });
byId('tour-next').addEventListener('click', () => { demoShot = Math.min(demoShots.length - 1, demoShot + 1); showDemoShot(); byId('demo-tour').scrollIntoView({block:'start', behavior:'instant'}); });
document.addEventListener('proof-evidence-changed', () => { showCostEstimate(); showDemoShot(); });
showCostEstimate();
showDemoShot();
