/* Hash navigation works on Pages project subpaths and in the local Symfony viewer. */
const chapters = [...document.querySelectorAll('#documentation > section')];
const chapterLinks = [...document.querySelectorAll('.docs-nav a')];
function openChapter() {
  const requested = decodeURIComponent(location.hash.slice(1));
  const aliases = {'catalog-tour': 'article-tour', 'editorial-review': 'demo-tour', 'codex-run': 'agent-run', 'version-one': 'journey', 'version-two': 'journey'};
  const id = aliases[requested] ?? (requested || 'overview');
  const target = document.getElementById(id);
  const chapter = chapters.find((item) => item === target || item.contains(target)) ?? chapters[0];
  chapters.forEach((item) => { item.hidden = item !== chapter; });
  document.querySelectorAll('.doc-page[hidden] video').forEach(film => {
    if (!film.paused) film.pause();
  });
  chapter.parentElement.dataset.ready = 'true';
  chapterLinks.forEach((link) => {
    if (link.hash === `#${chapter.id}`) link.setAttribute('aria-current', 'page');
    else link.removeAttribute('aria-current');
  });
  document.title = `${chapter.querySelector('h1,h2')?.innerText.replace(/\s+/g, ' ').trim()} · Symfony Code Mode`;
  if (target && target !== chapter && chapter.contains(target)) {
    for (let group = target.closest('details'); group; group = group.parentElement.closest('details')) {
      group.open = true;
    }
    target.scrollIntoView({behavior: 'instant'});
  } else window.scrollTo({top: 0, behavior: 'instant'});
}
window.addEventListener('hashchange', openChapter);
openChapter();
for (const link of document.querySelectorAll('[data-play-demo]')) {
  link.addEventListener('click', event => {
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    location.hash = link.hash;
    openChapter();
    const film = document.getElementById('reviewer-film');
    film.focus();
    film.play().catch(() => { /* The viewer can still use the native play control. */ });
  });
}
if (document.documentElement.dataset.static === 'true') {
  document.querySelectorAll('a[href^="/demo/"]').forEach((link) => { link.href = evidenceUrl(link.getAttribute('href')); });
}

// Chapter controls follow the cuts in each published film manifest.
for (const manifest of document.querySelectorAll('[data-film-chapters]')) {
  const section = manifest.closest('section');
  const menu = manifest.closest('.video-links').querySelector('select');
  const film = section.querySelector('video');
  fetch(manifest.href).then(response => {
    if (!response.ok) throw new Error('Film chapters unavailable');
    return response.json();
  }).then(({chapters}) => {
    for (const {start, title} of chapters) {
      const time = `${Math.floor(start / 60)}:${String(start % 60).padStart(2, '0')}`;
      menu.add(new Option(`${time} · ${title}`, String(start)));
    }
    menu.addEventListener('change', () => { film.currentTime = Number(menu.value); });
    film.addEventListener('timeupdate', () => {
      const chapter = chapters.findLast(({start}) => start <= film.currentTime);
      if (chapter) menu.value = String(chapter.start);
    });
    menu.closest('label').hidden = false;
  }).catch(() => { /* Native controls still allow seeking without chapter metadata. */ });
}

// This guide consumes the same reviewed evidence as the detailed inspectors.
// It never invokes a model, MCP endpoint or PHP executor.
const demoShots = [
  {kind:19, start:0, end:12, time:'00:00–00:12', label:'Find an article', title:'Find an article with two MCP tools', source:'Illustrated lookup · separate schema measurement', href:'?client=symfony-ai#native-rehearsal', caption:'A reader wants the title of article 101. The agent searches for a tool the reader may use, then asks Symfony to execute the read. In a separate fixture with 10 and 100 approved tools, the two advertised tool definitions used 239 tokens in both cases.'},
  {kind:17, start:12, end:16, time:'00:12–00:16', label:'From PHP to JSON', title:'Easier to adopt in Symfony.', source:'Design journey · existing implementations', href:'#journey', caption:'Sandboxed PHP in gVisor enabled computation near the tools. JSON calls to installed handlers made adoption possible over ordinary Symfony HTTP. Discovery now builds on a proposed Symfony AI BM25 component, through a pinned, attributed adapter. Approved tools and application permissions remain central.'},
  {kind:13, start:16, end:20, time:'00:16–00:20', label:'Generate a tool', title:'One Maker command. Four files.', source:'Actual command output · separate development copy', href:'#quickstart', caption:'make:code-mode-tool InventoryLookup creates a native Symfony AI tool, a voter, explicit registration and a security test. The generated voter test passes. The scaffold stays hidden and denied until you implement access. This is a separate example from the article tool in the recording.'},
  {kind:9, start:20, end:48, time:'00:20–00:48', label:'Discover and read', title:'A real question. A real Symfony tool.', source:'Original agent recording · synthetic article 101', href:'#native-rehearsal', caption:'The user asks for the title of published article 101. The agent discovers publication_read and sends its ID as JSON. Symfony returns “A Symfony application owns its tools”. The actual tool request and response are opened on screen. Seven seconds of waiting are visibly trimmed.'},
  {kind:10, start:48, end:52, time:'00:48–00:52', label:'Share the voter', title:'One voter. Both paths.', source:'Current installed source · added after the original recording', href:'#native-rehearsal', caption:'The tool uses IsGrantedTool and the controller uses IsGranted, both with ArticleVoter::READ and the article ID. Discovery uses the same voter. Execution checks the current permission again.'},
  {kind:12, start:52, end:56, time:'00:52–00:56', label:'Revoke permission', title:'Same user. Changed permission.', source:'Actual operator change · between the two recordings', href:'#native-rehearsal', caption:'The operator revoked the reader permission between recordings. The authenticated user and application connection stay the same. The call budget was not reset. This caption explains the change; no operator-terminal footage was supplied.'},
  {kind:11, start:56, end:79, time:'00:56–01:19', label:'Deny the follow-up', title:'A known contract cannot bypass revocation.', source:'Original agent recording · identical JSON read denied', href:'#native-rehearsal', caption:'The user asks, “Can you refresh that article from the application?” The agent repeats the same read. The response is tool_denied with zero completed calls. The agent explains that the earlier title remains in chat but is not a fresh result. The full follow-up plays at original speed, with a final still hold.'},
  {kind:16, start:79, end:87, time:'01:19–01:27', label:'Symfony AI + Hugging Face', title:'The same boundary, through Symfony AI.', source:'Separate recorded model conversation · authenticated HTTP + BM25', href:'#native-rehearsal', caption:'A separate real Symfony AI + Hugging Face conversation receives a denial after operator revocation, then discovers no visible contract. The film shows those two actual JSON excerpts and the denied call’s profiler outcome. The earlier successful lookup, measured usage and both retained attempts remain in the full evidence. This is not a new screen recording or real-time playback.'},
  {kind:18, start:87, end:90, time:'01:27–01:30', label:'Run it in Symfony', title:'Clone. Run the proof. Connect your agent.', source:'Runnable judge quickstart · PHP and Composer', href:'#quickstart', caption:'The public repository includes an independent Symfony HTTP application. Install its Composer dependencies, run the access-control proof, then connect your agent. The current JSON path needs no gVisor runtime, Docker or model key for the operator proof.'},
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
    content.append(node('p', 'Below: separate operator-authored HTTP checks of the current implementation. These are not the recorded agent transcript. The desktop recording does not contain a post-revocation search; the later, separate Symfony AI + Hugging Face segment does.', 'measurement-note'));
    if (report?.verification?.passed !== true) { content.append(node('p', 'Verified HTTP evidence is loading or unavailable.')); return; }
    const indexes = kind === 9 ? [0, 1] : [2, 3];
    for (const index of indexes) content.append(nativeCallCard(httpMainCalls()[index], index));
  } else if (kind === 10) {
    if (typeof httpCatalogEvidence === 'undefined' || httpCatalogEvidence?.verification?.passed !== true) { content.append(node('p', 'Verified application source is loading or unavailable.')); return; }
    for (const [role, label] of [['handler','INSTALLED HANDLER'], ['policy','SHARED SYMFONY VOTER'], ['controller','NORMAL HTTP CONTROLLER']]) {
      content.append(node('span', label, 'tour-label'), sourceBlock(nativeSourceExcerpt(role), true));
    }
    content.append(proofLink('#native-rehearsal', 'Inspect full source and HTTP calls →'), node('p', 'Source from this documentation build. Symfony AI registers publication_read through AsTool; the explicit allowlist selects it. The agent supplies JSON arguments.', 'measurement-note'));
  } else if ([15, 19].includes(kind)) {
    if (kind === 19) content.append(node('p', 'For article 101, search_tools returns the publication_read contract. The agent sends the article ID to execute_tools. Symfony checks the reader’s permission before invoking the handler.'), node('p', 'The chart shows what the agent sees at discovery time as a separate synthetic catalog grows. It still has to retrieve the contract it needs.'));
    if (typeof catalogScalingEvidence === 'undefined' || !catalogScalingEvidence) { content.append(node('p', 'Current schema measurement is loading.')); return; }
    content.append(scalingTable(catalogScalingEvidence), node('p', 'Offline o200k_harmony counts of one tools/list JSON result. The two contracts match the actual HTTP capture. Synthetic four-field tools; no model or handler runs. The retrieved contract is an additional search response, not included in the advertised-list count.', 'measurement-note'), proofLink('#catalog-cost', 'Explore the scaling visual →'), node('p', 'Total conversation cost still includes retrieved contracts, task data, history and output. Client conversion and caching affect billing.'), proofLink('#three-way', 'Same findings. Fewer tokens: the separate live audit →'));
  } else if (kind === 17) {
    content.append(node('p', 'Sandboxed PHP lets an agent join and filter results near approved tools. That path requires a separate gVisor runtime.'), node('p', 'The JSON catalog invokes installed Symfony handlers over ordinary authenticated HTTP. Existing application permissions and budgets still apply.'), node('p', 'BM25 discovery builds on a proposed Symfony AI search component through a pinned, attributed adapter. It adds no search service or Composer dependency.'), proofLink('#journey', 'Follow the implementation and evidence →'));
  } else if (kind === 18) {
    content.append(sourceBlock('git clone https://github.com/ronaldtebrake/symfony-hackathon.git\ncd symfony-hackathon\ncomposer install --working-dir=examples/symfony\nphp examples/symfony/bin/console code-mode:doctor\ncomposer proof:http --working-dir=examples/symfony'), node('p', 'Expected: ready, then 31 passing HTTP checks. No model key or container runtime is required for this proof.'), proofLink('#quickstart', 'Connect your agent and repeat the permission change →'));
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
  } else if (kind === 16) {
    const evidence = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence;
    const binding = videoProof?.liveHttpConversation;
    const run = evidence?.conversation?.attempts?.find(attempt => attempt.id === binding?.attemptId);
    if (!run?.verification?.passed) { content.append(node('p', 'The verified model conversation is loading or unavailable.')); return; }
    content.append(node('p', 'Separate live model capture, reformatted in the film. Symfony AI uses Hugging Face to choose these actual HTTP calls; the application uses BM25 discovery and its existing access checks. The film focuses on the denied follow-up and hidden contract. The complete sequence is below.'));
    const calls = run.http.filter(row => row.request.method === 'tools/call');
    for (const [index,row] of calls.entries()) {
      if (index === 2) content.append(node('span', 'OPERATOR REVOKES PERMISSION · SAME USER AND CONNECTION', 'tour-label'), sourceBlock(run.permissionChange.command));
      const card = nativeCallCard({tool:row.request.params.name,arguments:row.request.params.arguments,response:row.response.result}, index);
      card.append(catalogDetails('Profiler trace for this model-generated call', sourceBlock(JSON.stringify(row.profiler, null, 2))));
      content.append(card);
    }
    content.append(proofLink('#native-rehearsal', 'Both attempts, measured usage, model answers and full traces →'), node('p', 'The initial failed attempt and provider-reported usage remain in the detailed evidence. The opening chapter separately measures advertised schemas.', 'measurement-note'));
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

const workflowReceipts = byId('workflow-receipts');
workflowReceipts.addEventListener('toggle', async () => {
  if (!workflowReceipts.open || workflowReceipts.dataset.loaded) return;
  const output = byId('workflow-evidence');
  workflowReceipts.dataset.loaded = 'true';
  output.textContent = 'Loading the recorded calls…';
  try {
    const response = await fetch(evidenceUrl('/demo/skills-proof'));
    if (!response.ok) throw new Error('Evidence unavailable');
    const {agent, security} = await response.json();
    const trial = agent.attempts.find(attempt => attempt.protocol.providerRoute === 'novita');
    const arm = trial.arms.find(run => run.arm === 'skill');
    const denied = security.httpIdentityAndRevocation.sameCallAfterRevoke;
    output.replaceChildren(
      node('h3', 'The agent answered'),
      node('blockquote', arm.answer, 'tour-question'),
      node('p', `Symfony AI · Hugging Face / ${trial.protocol.providerRoute} · ${arm.requests.provider} model requests · ${number(arm.tokens.total)} provider tokens`),
      catalogDetails('Text skill received from Symfony', sourceBlock(arm.hostCalls.find(call => call.method === 'resources/read').reply.result.contents[0].text)),
      catalogDetails('Agent-selected tool calls and results', sourceBlock(JSON.stringify(arm.calls, null, 2))),
      catalogDetails('Separate HTTP check: the same read after revocation', sourceBlock(JSON.stringify(denied, null, 2)))
    );
  } catch {
    delete workflowReceipts.dataset.loaded;
    output.textContent = 'The recorded calls could not be loaded. Reopen to retry or download the evidence below.';
  }
});
