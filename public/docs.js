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
  {kind:3, time:'00:00–00:12', label:'See where we started', title:'PHP worked. Deployment took work.', source:'Version one · exact generated PHP', href:'#version-one', narration:'We began with generated PHP for loops and joins. It worked inside gVisor, but adopting it meant operating a dedicated runtime and runner image.'},
  {kind:4, time:'00:12–00:22', label:'Move to JSON', title:'Two tools. Existing handlers.', source:'Current conversation · actual catalog calls', href:'#version-two', narration:'The application already had tools with schemas and access checks. Search finds their contracts; execute passes literal JSON to their handlers. This path needs no generated-code sandbox.'},
  {kind:0, time:'00:22–00:34', label:'Start with the task', title:'An audit, through an agent.', source:'Current JSON catalog · real Symfony AI conversation', href:'#catalog-conversation', narration:'The agent audits published descriptions using the Symfony tool catalog. Forty-eight articles checked; four descriptions are missing. These are real model responses and actual tool calls.'},
  {kind:1, time:'00:34–00:44', label:'Reshape the question', title:'Same session. A useful follow-up.', source:'Same catalog conversation · follow-up', href:'#catalog-conversation', narration:'The user asks for those findings by language. The agent keeps its conversation history and the same permissions. The answer is independently checked against the fixture.'},
  {kind:5, time:'00:44–00:57', label:'Measure all three', title:'Correctness comes before savings.', source:'Matched Symfony AI + Hugging Face audit', href:'#three-way', narration:'A separate matched audit compares direct tools, JSON and sandboxed PHP. All three find the same ten issues. JSON is easier to deploy; PHP uses fewer tokens in this example. The totals include answer review.'},
  {kind:2, time:'00:57–01:07', label:'Recover from a boundary', title:'Denied, then useful again.', source:'Separate live catalog recovery conversation', href:'#catalog-recovery', narration:'In a separate conversation, the user asks to test a restricted article. The host denies the read. The next user turn selects the permitted published-content audit.'},
  {kind:6, time:'01:07–01:22', label:'Inspect the boundaries', title:'The host must still say no.', source:'Current JSON controls and live injection failure', href:'#catalog-security', narration:'The probes show allowed and denied reads, recovery and worker termination. In the live injection test, the agent tried an unwanted restricted read. The host blocked it. JSON does not remove the need for access checks.'},
  {kind:7, time:'01:22–01:30', label:'Adopt in Symfony', title:'Own tools. Own policy. No Docker.', source:'Independent Composer app · stock MCP server', href:'#catalog-adoption', narration:'A fresh Symfony app runs the catalog with its own tool and policy, without Docker or the main demo. Fifteen checks pass. Unix PHP CLI is still required. Drupal is an optional reuse example.'},
];
function latestVerifiedCatalog() {
  return [...threeWayAttempts].reverse().find(attempt => /^audit-catalog-v/.test(attempt.protocol ?? '') && attempt.verification?.passed === true);
}
let demoShot = 0;
function proofLink(href, text) { const link = node('a', text); link.href = href; return link; }
function sourceBlock(text, php = false) { const pre = node('pre', text, 'tour-code'); pre.tabIndex = 0; if (php) PhpSyntax.render(pre, text); return pre; }
function captureTurn(index) {
  if (!agentCapture || agentCapture.verification?.[index === 0 ? 'auditMatchesIndependentOracle' : 'impactMatchesIndependentOracle'] !== true) return null;
  const turn = agentCapture.turns[index];
  const execution = turn.items.find((item) => item.type === 'mcpToolCall' && item.tool === 'php_execute');
  return {turn, execution, result:execution.result.structuredContent};
}
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
  if (kind <= 2 || kind === 4) {
    const run = typeof catalogRun === 'function' ? catalogRun(kind === 2 ? 'boundary' : 'follow_up') : null;
    if (!run?.verification?.passed) { content.append(node('p', 'Verified catalog evidence is loading or unavailable. Inspect the full capture.')); return; }
    if (kind === 4) {
      const search = run.trace.agentCalls.find(call => call.name === 'search_tools');
      const execute = run.trace.agentCalls.find(call => call.name === 'execute_tools');
      content.append(node('span', 'ACTUAL SEARCH', 'tour-label'), sourceBlock(JSON.stringify(search.arguments, null, 2)), node('span', 'ACTUAL JSON SUBMISSION', 'tour-label'), sourceBlock(JSON.stringify(execute.arguments, null, 2)), proofLink('#catalog-conversation', 'Inspect all calls and responses →'));
    } else {
      const turn = run.turns[kind === 1 ? 1 : 0];
      content.append(node('span', 'USER → AGENT', 'tour-label'), node('blockquote', turn.question, 'tour-question'), node('span', 'RECORDED FINAL ANSWER', 'tour-label'), sourceBlock(JSON.stringify(JSON.parse(turn.answer), null, 2)));
      if (kind === 2) content.append(node('p', 'Next user turn: '+run.turns[1].question), sourceBlock(JSON.stringify(JSON.parse(run.turns[1].answer), null, 2)));
      content.append(node('p', 'Real Symfony AI generation. Drafts, text-only reviews, provider usage and actual calls are retained.', 'measurement-note'));
    }
  } else if (kind === 3) {
    const capture = captureTurn(0);
    if (!capture) { content.append(node('p', 'Historical PHP evidence unavailable.')); return; }
    content.append(node('p', 'Exact generated PHP from the original audit. It reads approved tools, joins the results and returns findings inside gVisor. Adoption requires a dedicated runtime and runner image.', 'measurement-note'), sourceBlock(capture.execution.arguments.code, true));
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
    content.append(scroll, node('p', `Captured ${new Date(attempt.createdAt).toLocaleDateString()}. Same 48 published articles, model, original question and permissions. One matched triplet; the archive retains earlier attempts.`, 'measurement-note'), node('p', 'JSON reduces deployment requirements. PHP can reduce intermediate data returned to the model. Actual charges depend on provider input/output pricing.', 'measurement-note'));
  } else if (kind === 6) {
    const controls = typeof catalogLatest === 'function' ? catalogLatest()?.controls : null;
    if (!controls?.verification?.passed) { content.append(node('p', 'Current control evidence unavailable.')); return; }
    const list = node('div', undefined, 'tour-security');
    for (const key of ['authorized', 'restricted', 'recovery']) {
      const probe = controls.cases.find(item => item.case === key), item = node('article');
      item.append(node('h4', key), node('strong', probe.response.error?.reason ?? 'succeeded')); list.append(item);
    }
    content.append(list, node('p', `Blocking handler terminated after ${Math.round(controls.timeout.elapsedMs)} ms; worker reaped: ${controls.timeout.workerReaped}.`), node('p', 'Live injection test: an unwanted restricted read was attempted and denied. Correct final answers alone do not establish correct agent behavior.', 'measurement-note'));

  } else {
    content.append(node('p','Symfony AI supplies the schemas and existing tool execution. The catalog exposes search and literal JSON calls, with host authorization and supervised handlers.'), sourceBlock("code_mode:\n    mode: catalog\n    ai_toolbox: app.toolbox\n    ai_tools: ['publication_read']\n    ai_authorization: Adoption\\ReadPolicy\n    identity_provider: Adoption\\LocalReader"), sourceBlock('php bin/console code-mode:doctor\nphp bin/console code-mode:serve --no-debug'), node('p','Composer path packages today; not yet on Packagist. JSON requires Unix PHP CLI, pcntl and posix. The sandboxed PHP path remains available separately; it requires gVisor.', 'measurement-note'));
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
