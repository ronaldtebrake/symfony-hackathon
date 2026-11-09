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
  {time:'00:00–00:10', label:'Start with the task', title:'An audit, through an agent.', source:'Version one · recorded PHP conversation', href:'#agent-run', narration:'We began with an agent auditing published articles for missing descriptions and image alt text. It wrote PHP against approved tools, and the sandbox ran it.'},
  {time:'00:10–00:20', label:'Reshape the question', title:'Turn findings into a repair plan.', source:'Same recorded conversation · follow-up', href:'#agent-run', narration:'The follow-up asks which shared image fixes help the most articles. The agent composes another program, with the same permissions. The conversation stays in the client.'},
  {time:'00:20–00:30', label:'Inspect the PHP', title:'The computation is real.', source:'Exact generated follow-up source', href:'#version-one', narration:'This is the submitted PHP. It reads approved data, groups shared images and ranks fixes. That saves model-side work, but it requires a dedicated sandbox runtime.'},
  {time:'00:30–00:40', label:'Keep access checks', title:'The host owns the boundary.', source:'Host-recorded follow-up ledger', href:'#agent-run', narration:'Every actual call crosses the broker for validation and authorization. Those checks are the part we keep when we change the execution interface.'},
  {time:'00:40–00:55', label:'Move to JSON', title:'Two tools. Existing handlers.', source:'Version two · actual catalog calls', href:'#version-two', narration:'Our Rosetta experiment made JSON Schema contracts concrete. Then we found Pushpak Chhajed’s Laravel post. We brought its search-and-execute approach to Symfony: approved JSON calls, no generated program and no gVisor.'},
  {time:'00:55–01:07', label:'Measure all three', title:'Correctness comes before savings.', source:'Matched live Symfony AI + Hugging Face triplet', href:'#three-way', narration:'We compare direct tools, the JSON catalog and sandboxed PHP on the same model, question, data and permissions. Review costs count. Earlier failures stay visible; these are measured runs, not a promise that one path always wins.'},
  {time:'01:07–01:23', label:'Inspect the boundaries', title:'The original security evidence.', source:'Version one · fixed sandbox probes', href:'#security-proof', narration:'The retained sandbox probes show an allowed read, a denied call and an infinite loop being terminated. JSON removes arbitrary generated code from its path; approved handlers still need access checks and execution budgets.'},
  {time:'01:23–01:30', label:'Adopt in Symfony', title:'Register tools. Supply your policy.', source:'Symfony AI schemas + PHP MCP SDK', href:'#quickstart', narration:'Reuse your Symfony AI tools and policy. Enable the catalog, check prerequisites and connect your agent. Drupal is an optional reuse example.'},
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
  const shot = demoShots[demoShot]; const content = byId('tour-content'); content.replaceChildren();
  byId('tour-time').textContent = shot.time;
  byId('tour-source').textContent = shot.source;
  byId('tour-title').textContent = shot.title;
  byId('tour-narration').textContent = shot.narration;
  byId('tour-evidence').href = shot.href;
  byId('tour-back').disabled = demoShot === 0;
  byId('tour-next').disabled = demoShot === demoShots.length - 1;
  [...byId('tour-steps').children].forEach((button, index) => button.setAttribute('aria-pressed', String(index === demoShot)));
  if (demoShot <= 3) {
    const capture = captureTurn(demoShot === 0 ? 0 : 1);
    if (!capture) { content.append(node('p', 'Verified conversation evidence is not available. Open the full capture to inspect its status.')); return; }
    const {turn, execution, result} = capture; const value = result.value;
    if (demoShot <= 1) {
      // Verbatim excerpt, with the full operator prompt immediately available.
      const prompt = turn.prompt.split(/(?<=\.)\s/).slice(0, demoShot === 0 ? 1 : 2).join(' ');
      content.append(node('span', 'YOU → AGENT', 'tour-label'), node('blockquote', prompt, 'tour-question'));
      const details = node('details', undefined, 'proof-details'); details.append(node('summary', 'Full recorded prompt'), node('p', turn.prompt)); content.append(details);
      const outcome = node('div', undefined, 'tour-outcome');
      outcome.append(node('strong', demoShot === 0 ? `${value.total_audited} articles. ${value.affected_count} findings.` : `${value.shared_hero_image_fix_count} shared image fixes. ${value.hero_image_alt_affected_article_count} articles helped.`));
      const list = node('ul');
      if (demoShot === 0) {
        list.append(node('li', `Affected article IDs: ${value.affected_articles.map((article) => article.id).join(', ')}.`));
      } else {
        value.hero_image_alt_fixes_ranked_by_impact.forEach((fix) => list.append(node('li', `Image ${fix.image_id} → articles ${fix.affected_article_ids.join(' and ')}`)));
        list.append(node('li', `${value.meta_description_fix_count} separate description fixes.`));
      }
      outcome.append(list, node('span', `Verified PHP result · ${result.toolCalls.length} host-recorded calls · read-only`, 'measurement-note')); content.append(outcome);
    } else if (demoShot === 2) {
      content.append(node('p', 'Exact follow-up source, generated by the agent. Scroll to inspect the full program. Open the conversation’s “Present PHP” view for the recording.', 'measurement-note'), sourceBlock(execution.arguments.code, true));
    } else {
      const table = node('table'); table.append(node('caption', 'Actual authorized calls from the follow-up'));
      const head = node('thead'); const row = node('tr'); ['#', 'Tool', 'Status', 'Payload', 'Time'].forEach((label) => row.append(node('th', label))); head.append(row);
      const body = node('tbody'); renderCallRows(body, result.toolCalls); table.append(head, body);
      const scroll = node('div', undefined, 'table-scroll'); scroll.append(table); content.append(scroll, node('p', 'Payload bytes are diagnostic measurements, not token counts. The full capture includes the exact outer MCP requests and returned results.', 'measurement-note'));
    }
  } else if (demoShot === 4) {
    const attempt = latestVerifiedCatalog();
    if (!attempt) { content.append(node('p', 'A verified catalog comparison is not yet available. Inspect all attempts in the comparison chapter.')); return; }
    const calls = attempt.runs.batch.result.agentCalls;
    const search = calls.find(call => call.name === 'search_tools');
    const execute = calls.find(call => call.name === 'execute_tools');
    const submitted = execute?.arguments?.calls ?? [];
    const full = node('details', undefined, 'proof-details');
    full.append(node('summary', `Full recorded submission · ${submitted.length} calls`), sourceBlock(JSON.stringify(execute?.arguments, null, 2)));
    content.append(node('span', 'SEARCH_TOOLS · ACTUAL REQUEST', 'tour-label'), sourceBlock(JSON.stringify(search?.arguments, null, 2)), node('span', `EXECUTE_TOOLS · FIRST CALL EXCERPT OF ${submitted.length}`, 'tour-label'), sourceBlock(JSON.stringify({calls: submitted.slice(0, 1)}, null, 2)), full, node('p', 'Literal arguments go to existing approved handlers. Independent calls can share a submission; dependent calls wait for their inputs. The full capture includes seven extra empty-page reads and their costs.', 'measurement-note'), proofLink('#three-way', 'Inspect every call and result →'));
  } else if (demoShot === 5) {
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
    content.append(scroll, node('p', `Captured ${new Date(attempt.createdAt).toLocaleDateString()} · ${attempt.protocol}. Same 48 published articles, model, original question and permissions. One successful triplet; all unsuccessful attempts remain visible.`, 'measurement-note'), node('p', 'JSON reduces deployment requirements. PHP can reduce intermediate data returned to the model. Actual charges depend on provider input/output pricing.', 'measurement-note'));
  } else if (demoShot === 6) {
    if (!judgeSecurity?.cases?.length) { content.append(node('p','Security evidence is not available.')); return; }
    const list = node('div', undefined, 'tour-security');
    for (const probe of judgeSecurity.cases) {
      const item = node('article');
      item.append(node('span', probe.passed === true ? '✓ Check passed' : 'Check failed', 'status-pill'), node('h4', probe.title), sourceBlock(probe.code, true), node('p', `Observed: ${probe.reason ?? 'succeeded'}`), node('p', probe.containerRemoved === true ? 'Container removed · checked by host' : 'Container removal not confirmed', 'measurement-note'));
      const details = node('details', undefined, 'proof-details'); details.append(node('summary', 'Actual execution trace'), sourceBlock(JSON.stringify(probe, null, 2))); item.append(details); list.append(item);
    }
    content.append(list, node('p','Fixed probes use a 2-second wall-clock budget and 128 MiB. Timing includes setup and cleanup. These checks demonstrate specific controls; they are not an independent security audit.', 'measurement-note'));
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
