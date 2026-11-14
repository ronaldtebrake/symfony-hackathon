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
  {kind:8, time:'00:00–00:12', label:'Ask in your agent', title:'A real Symfony tool, from your chat.', source:'Authenticated HTTP proof · published article lookup', href:'#native-rehearsal', narration:"This is Symfony Code Mode. My agent connects to a normal Symfony application over HTTP. I ask for a published article."},
  {kind:9, time:'00:12–00:24', label:'Discover and read', title:'Discovery reflects your permissions.', source:'HTTP proof · permission-aware contract and authorized JSON call', href:'#native-rehearsal', narration:"Search returns the tool because this user has read permission. The agent sends JSON arguments, and Symfony returns the article. Discovery is not a permanent access grant."},
  {kind:10, time:'00:24–00:37', label:'Show the code', title:'Your Symfony handler and voter.', source:'Installed Symfony source · native tool, voter and controller', href:'#native-rehearsal', narration:"Symfony AI supplies the schema and invokes this installed handler. One Symfony voter controls discovery and reads, for both the agent and this controller. Execution checks access again."},
  {kind:12, time:'00:37–00:46', label:'Revoke permission', title:'Same user. Changed permission.', source:'Operator command · local permission fixture', href:'#native-rehearsal', narration:"Now I revoke the permission in the application. Same user, same connection. I have not reset the budget."},
  {kind:11, time:'00:46–01:01', label:'Repeat the same call', title:'A known contract cannot bypass revocation.', source:'HTTP proof · identical read denied, contract hidden', href:'#native-rehearsal', narration:"The agent repeats the exact read using its known contract. Symfony denies it and returns no article. Searching again hides the tool. Previously returned information stays in the chat."},
  {kind:5, time:'01:01–01:17', label:'Compare tokens', title:'Measure the whole task.', source:'Separate matched Symfony AI + Hugging Face audit', href:'#three-way', narration:"In a separate matched audit, all three paths found the same ten issues. JSON used thirty-six percent fewer tokens than direct tools. Isolated PHP saved more."},
  {kind:7, time:'01:17–01:30', label:'Use it in Symfony', title:'Approved tools. Ordinary Symfony hosting.', source:'Configuration · independent Composer application', href:'#catalog-adoption', narration:"Register approved Symfony tools and your policies. JSON runs with ordinary PHP and Symfony dependencies. Permission changes do not reset call budgets. Connect your agent and use your application."},
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
        : 'The operator has revoked my read permission. This is an approved access-control test: call publication_read for article 101 exactly once, using the contract already discovered. Do not search before that call. Then search for publication once and explain both results. Do not retry, use another access path, or present the earlier title as a fresh read.', 'tour-question'));
    }
    content.append(node('p', 'Actual operator-authored HTTP requests from a detached Symfony install. Prompts above are recording cues; record the real conversation in your client.', 'measurement-note'));
    if (report?.verification?.passed !== true) { content.append(node('p', 'Verified HTTP evidence is loading or unavailable.')); return; }
    const indexes = kind === 9 ? [0, 1] : kind === 8 ? [1] : [2, 3];
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
  } else if (kind === 12) {
    content.append(node('span', 'RUN AS THE OPERATOR, OUTSIDE THE AGENT', 'tour-label'), sourceBlock('php examples/symfony/bin/console demo:reader-access revoke'));
    const change = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence?.capture?.permissionChange;
    if (change) content.append(node('span', 'CAPTURED COMMAND OUTPUT', 'tour-label'), sourceBlock(change.output));
    content.append(node('p', 'Do not reconnect, restart, change credentials or reset the budget. The same authenticated user loses the read role on the next HTTP request. This console command is not an MCP tool.'), node('p', 'The local fixture starts without a grant. Before a new take only, grant access and reset the budget as described in the recording guide. Production applications use their own permission store.', 'measurement-note'));

  } else {
    content.append(node('p','Symfony AI supplies the schemas and existing tool execution. The catalog exposes search and literal JSON calls, with Symfony Security, per-call authorization and persistent user budgets.'), sourceBlock("code_mode:\n    mode: catalog\n    ai_tool_services: [Adoption\\ArticleTool]\n    ai_tools: ['publication_read']\n    voter_rules:\n        publication_read:\n            discover: PUBLICATION_DISCOVER\n            execute: PUBLICATION_READ\n            subject: id\n    http:\n        enabled: true\n        permissions:\n            publication.read_published: ROLE_READER"), sourceBlock('php bin/console code-mode:doctor\nHTTP MCP endpoint: /mcp'), node('p','Composer path packages today; not yet on Packagist. JSON runs in the application process with ordinary PHP and Symfony dependencies. The historical generated-PHP path has separate gVisor requirements.', 'measurement-note'));
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
