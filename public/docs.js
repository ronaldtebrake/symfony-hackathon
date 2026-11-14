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
  {kind:7, start:0, end:3, time:'00:00–00:03', label:'Your app, in your agent', title:'Build a tool. Share a voter. See every decision.', source:'Silent product overview', href:'#quickstart', caption:'Symfony Code Mode connects an agent to an ordinary Symfony HTTP application. The agent supplies JSON; approved installed handlers do the work.'},
  {kind:13, start:3, end:10, time:'00:03–00:10', label:'Generate a tool', title:'One Maker command. Four files.', source:'Actual command output · separate development copy', href:'#quickstart', caption:'make:code-mode-tool InventoryLookup creates a native Symfony AI tool, a voter, explicit registration and a security test. The generated voter test passes. The scaffold stays hidden and denied until you implement access. This is a separate example from the article tool in the recording.'},
  {kind:9, start:10, end:38, time:'00:10–00:38', label:'Discover and read', title:'A real question. A real Symfony tool.', source:'Original agent recording · synthetic article 101', href:'#native-rehearsal', caption:'The user asks for the title of published article 101. The agent discovers publication_read and sends its ID as JSON. Symfony returns “A Symfony application owns its tools”. The actual tool request and response are opened on screen. Seven seconds of waiting are visibly trimmed.'},
  {kind:10, start:38, end:46, time:'00:38–00:46', label:'Share the voter', title:'One voter. Both paths.', source:'Current installed source · added after the original recording', href:'#native-rehearsal', caption:'The tool uses IsGrantedTool and the controller uses IsGranted, both with ArticleVoter::READ and the article ID. Discovery uses the same voter. Execution checks the current permission again.'},
  {kind:12, start:46, end:51, time:'00:46–00:51', label:'Revoke permission', title:'Same user. Changed permission.', source:'Actual operator change · between the two recordings', href:'#native-rehearsal', caption:'The operator revoked the reader permission between recordings. The authenticated user and application connection stay the same. The call budget was not reset. This caption explains the change; no operator-terminal footage was supplied.'},
  {kind:11, start:51, end:74, time:'00:51–01:14', label:'Deny the follow-up', title:'A known contract cannot bypass revocation.', source:'Original agent recording · identical JSON read denied', href:'#native-rehearsal', caption:'The user asks, “Can you refresh that article from the application?” The agent repeats the same read. The response is tool_denied with zero completed calls. The agent explains that the earlier title remains in chat but is not a fresh result. The full follow-up plays at original speed, with a final still hold.'},
  {kind:14, start:74, end:82, time:'01:14–01:22', label:'Inspect the profiler', title:'See the decision in Symfony.', source:'Actual profiler screenshots · separate HTTP proof, no model', href:'#quickstart', caption:'The authorized request records a successful broker call and native Symfony AI requested/succeeded events. After revocation, the identical request records tool_denied, no native tool events, and two consumed attempts. These fresh development profiles are separate from the original chat.'},
  {kind:5, start:82, end:90, time:'01:22–01:30', label:'Compare the audit', title:'Same findings. Fewer tokens.', source:'Separate historical Symfony AI + Hugging Face audit', href:'#three-way', caption:'In a separate matched 48-article audit, all paths found ten issues: direct tools used 46,438 provider tokens, JSON 29,798 and isolated PHP 12,806. JSON used 35.8% fewer tokens than direct tools in this run. Reviews are included. These are not this lookup’s usage, a new HTTP benchmark or a bill.'},
];
const videoMediaUrl = name => `${staticDocumentation ? '' : '/'}media/${name}`;
let videoProof = null;
fetch(videoMediaUrl('video-proof.json')).then(response => {
  if (!response.ok) throw new Error('Capture unavailable');
  return response.json();
}).then(report => {
  videoProof = report;
  showDemoShot();
}).catch(() => { /* The film and recording guide remain usable without the inspector. */ });

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
  byId('tour-caption').textContent = shot.caption;
  byId('tour-evidence').href = shot.href;
  byId('tour-back').disabled = demoShot === 0;
  byId('tour-next').disabled = demoShot === demoShots.length - 1;
  [...byId('tour-steps').children].forEach((button, index) => button.setAttribute('aria-pressed', String(index === demoShot)));
  if ([9, 11].includes(kind)) {
    const report = typeof httpCatalogEvidence !== 'undefined' ? httpCatalogEvidence : null;
    content.append(node('span', 'ACTUAL PROMPT IN THE RECORDING', 'tour-label'), node('blockquote', kind === 9
      ? "Look up published article 101 in our Symfony application. What's its title?"
      : 'Can you refresh that article from the application?', 'tour-question'));
    content.append(node('p', 'Below: separate operator-authored HTTP checks of the current implementation. These are not the recorded agent transcript. The post-revocation search is an additional HTTP check, not a step shown in the film.', 'measurement-note'));
    if (report?.verification?.passed !== true) { content.append(node('p', 'Verified HTTP evidence is loading or unavailable.')); return; }
    const indexes = kind === 9 ? [0, 1] : [2, 3];
    for (const index of indexes) content.append(nativeCallCard(httpMainCalls()[index], index));
  } else if (kind === 10) {
    if (typeof httpCatalogEvidence === 'undefined' || httpCatalogEvidence?.verification?.passed !== true) { content.append(node('p', 'Verified application source is loading or unavailable.')); return; }
    for (const [role, label] of [['handler','INSTALLED HANDLER'], ['policy','SHARED SYMFONY VOTER'], ['controller','NORMAL HTTP CONTROLLER']]) {
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
  } else if (kind === 13) {
    if (!videoProof) { content.append(node('p', 'Maker capture is loading or unavailable.')); return; }
    const maker = videoProof.maker;
    content.append(node('span', 'ACTUAL COMMAND · ISOLATED DEVELOPMENT APP', 'tour-label'), sourceBlock(maker.command), node('span', 'GENERATED FILES', 'tour-label'), sourceBlock(maker.created.join('\n')), node('span', 'GENERATED VOTER TEST', 'tour-label'), sourceBlock(maker.testOutput));
    content.append(node('p', 'The generated tool is hidden and denied until the developer implements its voter and handler. This InventoryLookup scaffold is separate from the existing article tool in the recording.', 'measurement-note'));
  } else if (kind === 14) {
    if (!videoProof) { content.append(node('p', 'Profiler capture is loading or unavailable.')); return; }
    content.append(node('p', 'Actual Symfony profiler screenshots and metadata from a separate authenticated MCP HTTP capture. No model was called. The denied request reuses the same discovered contract and JSON arguments.'));
    for (const label of ['authorized', 'denied']) {
      const request = videoProof.development.requests.find(row => row.label === label);
      content.append(node('h4', label === 'authorized' ? 'Authorized read' : 'After permission revocation'));
      const img = node('img'); img.src = videoMediaUrl(`profiler-${label}.png`); img.alt = label === 'authorized' ? 'Symfony Code Mode profiler: successful publication_read, one attempt, native requested and succeeded events.' : 'Symfony Code Mode profiler: tool_denied, two attempts consumed, no native toolbox events.'; img.className = 'tour-profiler'; img.loading = 'lazy';
      content.append(img);
      const detail = node('details'); detail.append(node('summary', 'Exact captured operation metadata'), sourceBlock(JSON.stringify(request.operations, null, 2))); content.append(detail);
    }
    content.append(node('p', 'The Code Mode panel omits arguments, results and identities. Other profiler panels may contain sensitive data; keep the development profiler private. Agent model tokens are not measured here.', 'measurement-note'));
  } else if (kind === 12) {
    content.append(node('span', 'RUN AS THE OPERATOR, OUTSIDE THE AGENT', 'tour-label'), sourceBlock('php examples/symfony/bin/console demo:reader-access revoke'));
    const change = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence?.capture?.permissionChange;
    if (change) content.append(node('span', 'CAPTURED COMMAND OUTPUT', 'tour-label'), sourceBlock(change.output));
    content.append(node('p', 'Do not reconnect, restart, change credentials or reset the budget. The same authenticated user loses the read role on the next HTTP request. This console command is not an MCP tool.'), node('p', 'The local fixture starts without a grant. Before a new take only, grant access and reset the budget as described in the recording guide. Production applications use their own permission store.', 'measurement-note'));

  } else {
    content.append(node('p','Symfony AI supplies the schemas and existing tool execution. The catalog exposes search and literal JSON calls, with Symfony Security, per-call authorization and persistent user budgets.'), sourceBlock("code_mode:\n    mode: catalog\n    ai_tool_services: [Adoption\\ArticleTool]\n    ai_tools: ['publication_read']\n    voter_rules:\n        publication_read:\n            discover: PUBLICATION_DISCOVER\n            execute: PUBLICATION_READ\n            subject: id\n    http:\n        enabled: true\n        permissions:\n            publication.read_published: ROLE_READER"), sourceBlock('php bin/console code-mode:doctor\nHTTP MCP endpoint: /mcp'), node('p','Composer path packages today; not yet on Packagist. JSON runs in the application process with ordinary PHP and Symfony dependencies. The historical generated-PHP path has separate gVisor requirements.', 'measurement-note'));
  }
}
const tourVideo = byId('tour-video');
let pendingVideoSeek = null;
function selectDemoShot(index) {
  demoShot = Math.max(0, Math.min(demoShots.length - 1, index));
  if (tourVideo.readyState > 0) tourVideo.currentTime = demoShots[demoShot].start;
  else pendingVideoSeek = demoShots[demoShot].start;
  showDemoShot();
}
tourVideo.addEventListener('loadedmetadata', () => {
  if (pendingVideoSeek !== null) { tourVideo.currentTime = pendingVideoSeek; pendingVideoSeek = null; }
});
tourVideo.addEventListener('timeupdate', () => {
  const index = demoShots.findIndex(shot => tourVideo.currentTime >= shot.start && tourVideo.currentTime < shot.end);
  if (index >= 0 && demoShot !== index) { demoShot = index; showDemoShot(); }
});
for (const [index, shot] of demoShots.entries()) {
  const button = node('button'); button.type = 'button';
  button.append(node('span', shot.time), node('strong', shot.label));
  button.addEventListener('click', () => selectDemoShot(index));
  byId('tour-steps').append(button);
}
byId('tour-back').addEventListener('click', () => selectDemoShot(demoShot - 1));
byId('tour-next').addEventListener('click', () => selectDemoShot(demoShot + 1));
document.addEventListener('proof-evidence-changed', () => { showCostEstimate(); showDemoShot(); });
showCostEstimate();
showDemoShot();
