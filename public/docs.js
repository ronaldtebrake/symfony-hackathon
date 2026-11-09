/* Hash navigation works on Pages project subpaths and in the local Symfony viewer. */
const chapters = [...document.querySelectorAll('#documentation > section')];
const chapterLinks = [...document.querySelectorAll('.docs-nav a')];
function openChapter() {
  const requested = decodeURIComponent(location.hash.slice(1));
  const id = requested === 'codex-run' ? 'agent-run' : requested || 'overview';
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
  {time:'00:00–00:10', label:'Ask for an audit', title:'Start with a real task.', source:'Recorded MCP client conversation', href:'#agent-run', narration:'Symfony Code Mode lets an agent use your approved tools through PHP. Here, I ask it to audit the published articles for missing descriptions and image alt text.'},
  {time:'00:10–00:20', label:'Reshape the question', title:'Turn findings into a repair plan.', source:'Same recorded conversation · second execution', href:'#agent-run', narration:'Now I ask which shared image fixes help the most articles. The agent writes a new program and checks the data again, with the same permissions.'},
  {time:'00:20–00:35', label:'Inspect generated PHP', title:'PHP does the intermediate work.', source:'Exact PHP submitted in the follow-up', href:'#agent-run', narration:'This is the PHP it actually submitted. It fetches approved data, groups shared images and ranks the fixes. That work happens inside the runner, before the result goes back to the model.'},
  {time:'00:35–00:48', label:'Check actual tool calls', title:'Every tool call crosses the broker.', source:'Host-recorded follow-up ledger', href:'#agent-run', narration:'These are the actual calls, recorded by the host. The broker validates the arguments and checks authorization on every invocation. Generated PHP cannot grant itself more access.'},
  {time:'00:48–01:00', label:'Compare tokens', title:'Measure the model work.', source:'Separate matched Symfony AI + Hugging Face pair', href:'#live-comparison', narration:'In a separate matched Symfony AI run, both paths find all ten issues. Code Mode uses 12,597 tokens instead of 43,979: 71.4 percent fewer in this recorded pair.'},
  {time:'01:00–01:22', label:'Prove the boundaries', title:'Useful access. Enforced limits.', source:'Separate fixed PHP probes · no model called', href:'#security-proof', narration:'These separate security probes run real PHP. An authorized read succeeds. An unauthorized tool call is denied. An infinite loop is terminated, and container removal is checked. The model controls the program; the host controls its permissions and execution budget.'},
  {time:'01:22–01:30', label:'Use it in Symfony', title:'Bring your existing Symfony tools.', source:'Reusable Composer integration', href:'#quickstart', narration:'Register your Symfony AI tools and policy, check prerequisites, and connect your agent. The packages and reproducible evidence are in the repository.'},
];
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
    if (!judgeComparison) { content.append(node('p', 'No verified token comparison is available.')); return; }
    const direct = judgeComparison.direct.result; const code = judgeComparison.code.result;
    const reduction = 100 * (direct.providerTokens.total - code.providerTokens.total) / direct.providerTokens.total;
    content.append(node('div', `${number(direct.providerTokens.total)} → ${number(code.providerTokens.total)}`, 'tour-token-total'), node('p', `${reduction.toFixed(1)}% fewer provider tokens · ${direct.modelRequests} → ${code.modelRequests} model requests`, 'tour-token-label'));
    const list = node('dl', undefined, 'tour-token-detail');
    for (const [label, field] of [['Input tokens','prompt'], ['Output tokens','completion']]) { const item = node('div'); item.append(node('dt',label),node('dd',`${number(direct.providerTokens[field])} → ${number(code.providerTokens[field])}`)); list.append(item); }
    content.append(list, node('p','Both paths find all 10 issues in 48 articles. Same model, question, data and permissions. This is one matched pair, including each invocation’s failed tool attempts. Earlier runs remain inspectable.','measurement-note'), node('p','Tokens affect inference cost. The actual charge depends on input/output rates and provider billing; use the estimator in the full comparison.','measurement-note'));
  } else if (demoShot === 5) {
    if (!judgeSecurity?.cases?.length) { content.append(node('p','Security evidence is not available.')); return; }
    const list = node('div', undefined, 'tour-security');
    for (const probe of judgeSecurity.cases) {
      const item = node('article');
      item.append(node('span', probe.passed === true ? '✓ Check passed' : 'Check failed', 'status-pill'), node('h4', probe.title), sourceBlock(probe.code, true), node('p', `Observed: ${probe.reason ?? 'succeeded'}`), node('p', probe.containerRemoved === true ? 'Container removed · checked by host' : 'Container removal not confirmed', 'measurement-note'));
      const details = node('details', undefined, 'proof-details'); details.append(node('summary', 'Actual execution trace'), sourceBlock(JSON.stringify(probe, null, 2))); item.append(details); list.append(item);
    }
    content.append(list, node('p','Fixed probes use a 2-second wall-clock budget and 128 MiB. Timing includes setup and cleanup. These checks demonstrate specific controls; they are not an independent security audit.', 'measurement-note'));
  } else {
    content.append(node('p','Symfony AI supplies the schemas and existing tool execution. Code Mode adds PHP composition, host authorization and a bounded runner.'), sourceBlock("code_mode:\n    ai_toolbox: app.toolbox\n    ai_tools: ['publication_read']\n    ai_authorization: Adoption\\ReadPolicy\n    identity_provider: Adoption\\LocalReader"), sourceBlock('php bin/console code-mode:doctor\nphp bin/console code-mode:serve --no-debug'), node('p','Composer path packages today; not yet on Packagist. Secure execution requires the configured gVisor runner. The independent Symfony example includes the full configuration.', 'measurement-note'));
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
