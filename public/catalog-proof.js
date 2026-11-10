'use strict';
let catalogEvidence = null;
const catalogLatest = () => [...(catalogEvidence?.attempts ?? [])].reverse().find(attempt => attempt.mode === 'live');
const catalogScenario = scenario => ({pagination:'Pagination', join:'Article + image audit', follow_up:'Audit + follow-up', boundary:'Denied read + recovery', injection:'Injected instruction'})[scenario] ?? scenario;
const catalogRun = scenario => catalogLatest()?.runs?.find(run => run.scenario === scenario);
const catalogJson = value => sourceBlock(typeof value === 'string' ? value : JSON.stringify(value, null, 2));
function catalogDetails(title, content) {
  const details = node('details', undefined, 'proof-details');
  details.append(node('summary', title), content); return details;
}
function catalogMetrics(run) {
  const group = node('div', undefined, 'hf-metrics');
  for (const [label, value] of [['Provider tokens', run.trace?.providerTokens?.total], ['Model requests', run.trace?.modelRequests], ['Actual calls', run.trace?.brokerCalls?.length]]) {
    const card = node('div'); card.append(node('span', label), node('strong', number(value))); group.append(card);
  }
  return group;
}
function catalogCalls(calls) {
  const group = node('div');
  calls.forEach((call, index) => {
    let result; try { result = JSON.parse(call.result); } catch { result = call.result; }
    const body = node('div'); body.append(node('span', 'SUBMITTED JSON', 'eyebrow'), catalogJson(call.arguments), node('span', 'ACTUAL RESPONSE', 'eyebrow'), catalogJson(result));
    group.append(catalogDetails(`${String(index + 1).padStart(2, '0')} · ${call.name}${result?.error ? ` · ${result.error.reason}` : ''}`, body));
  });
  return group;
}
function catalogLedger(calls) {
  const table = node('table'), head = node('thead'), header = node('tr'), body = node('tbody');
  ['#', 'Tool', 'Status', 'Result bytes', 'Time'].forEach(label => header.append(node('th', label))); head.append(header);
  renderCallRows(body, calls); table.append(head, body);
  const scroll = node('div', undefined, 'table-scroll'); scroll.append(table); return scroll;
}
function catalogConversation(target, run) {
  const container = byId(target); container.replaceChildren();
  if (!run?.trace) { container.append(node('p', 'No completed capture is available. Inspect all attempts.')); return; }
  container.append(node('p', `${run.verification?.passed ? 'VERIFIED' : 'REVIEW REQUIRED'} · ${run.mode === 'live' ? 'Symfony AI + Hugging Face' : 'Reference algorithms · no model'} · ${new Date(run.createdAt).toLocaleDateString()}`, 'mode-note'), catalogMetrics(run));
  for (const [index, turn] of run.turns.entries()) {
    const article = node('article', undefined, 'catalog-turn');
    article.append(node('span', `TURN ${index + 1} · USER → AGENT`, 'eyebrow'), node('blockquote', turn.question, 'tour-question'));
    let answer; try { answer = JSON.parse(turn.answer); } catch { answer = turn.answer ?? 'No answer completed.'; }
    article.append(node('span', 'RECORDED FINAL ANSWER', 'eyebrow'), catalogJson(answer));
    const executions = run.trace.executions.slice(turn.executionStart, turn.executionEnd);
    article.append(catalogDetails('Actual underlying calls', catalogLedger(executions.flatMap(execution => execution.toolCalls))));
    article.append(catalogDetails('Exact agent JSON calls and returned results', catalogCalls(run.trace.agentCalls.slice(turn.agentCallStart, turn.agentCallEnd))));
    if (turn.review) article.append(catalogDetails('Draft and model review · included in token totals', catalogJson({draft: turn.draft, review: turn.review})));
    container.append(article);
  }
  container.append(catalogDetails('Independent verification and recorded limits', catalogJson({verification: run.verification, manifest: run.manifest})));
}
function renderCatalogAttempt() {
  const attempt = catalogEvidence?.attempts?.[Number(byId('catalog-attempt').value)];
  const container = byId('catalog-evidence-body'); container.replaceChildren();
  if (!attempt) return;
  container.append(node('p', `${attempt.mode.toUpperCase()} · ${attempt.status} · ${attempt.createdAt}`, 'mode-note'));
  if (attempt.reviewNote) container.append(node('p', attempt.reviewNote, 'measurement-note'));
  const table = node('table'), head = node('thead'), header = node('tr'), body = node('tbody');
  ['Scenario', 'Answers', 'Agent behavior', 'Host boundary', 'Tokens', 'Requests'].forEach(label => header.append(node('th', label))); head.append(header);
  for (const run of attempt.runs) {
    const checks = run.verification?.checks ?? {}, row = node('tr');
    const text = key => checks[key] === true ? 'Passed' : checks[key] === false ? 'Failed' : 'Not captured';
    row.append(node('td', catalogScenario(run.scenario)), node('td', text('answersCorrect')), node('td', text('agentRespectedTaskBoundary')), node('td', text('restrictedDataNotReturned')), node('td', number(run.trace?.providerTokens?.total)), node('td', number(run.trace?.modelRequests))); body.append(row);
  }
  table.append(head, body); const scroll = node('div', undefined, 'table-scroll'); scroll.append(table); container.append(scroll);
  const total = attempt.runs.reduce((n, run) => n + (run.verification?.knownProviderTokens?.total ?? 0), 0);
  const missing = attempt.runs.reduce((n, run) => n + (run.verification?.unreportedRequests ?? 0), 0);
  container.append(node('p', `${number(total)} known provider tokens across this attempt${missing ? `; ${missing} requests have unreported usage` : ''}. ${attempt.mode === 'reference' ? 'No model was called; token costs are not measured.' : 'Includes drafts and text-only reviews. One run per scenario; no reliability percentage or billing claim.'}`, 'measurement-note'));
  for (const run of attempt.runs) {
    const content = node('div');
    if (!run.trace) content.append(node('p', 'This planned scenario was not started.'));
    else {
      content.append(catalogMetrics(run));
      for (const turn of run.turns) content.append(node('blockquote', turn.question, 'tour-question'), catalogJson({answer: turn.answer, draft: turn.draft, review: turn.review}));
      content.append(catalogDetails('Exact calls and responses', catalogCalls(run.trace.agentCalls)), catalogDetails('Host call ledger', catalogLedger(run.trace.brokerCalls)), catalogDetails('Full captured run', catalogJson(run)));
    }
    container.append(catalogDetails(`${catalogScenario(run.scenario)} · ${run.status}`, content));
  }
}
function renderCatalogControls() {
  const report = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence?.capture;
  const container = byId('catalog-controls-body'); container.replaceChildren();
  if (!report) { container.append(node('p', 'Current HTTP control evidence is loading.')); return; }
  const cards = node('div', undefined, 'catalog-controls');
  for (const [label,title] of [['authorized','Approved read'],['restricted','Restricted read'],['after_restart','Budget survives restart'],['call_budget','Call budget']]) {
    const call = report.calls.find(call => call.label === label), card = node('article');
    card.append(node('span', 'ACTUAL HTTP EXECUTION', 'eyebrow'), node('h3', title), node('strong', call.response.result.structuredContent.error?.reason ?? 'succeeded'), catalogDetails('Actual JSON-RPC', catalogJson(call))); cards.append(card);
  }
  container.append(cards, node('p', 'Authentication, permissions and budgets are enforced by Symfony. A blocked handler is not forcibly terminated: hosting and downstream clients must enforce hard timeouts.', 'measurement-note'), catalogDetails('Authentication, origin and per-user checks', catalogJson(report.httpChecks)));
  const historical = catalogLatest()?.controls;
  if (historical) container.append(catalogDetails('Historical worker controls · not the current HTTP runtime', catalogJson(historical)));
}
function renderCatalogAdoption() {
  const report = typeof httpCatalogEvidence === 'undefined' ? null : httpCatalogEvidence?.capture;
  const container = byId('catalog-adoption-body'); container.replaceChildren();
  if (!report) { container.append(node('p', 'Fresh HTTP proof is loading.')); return; }
  container.append(node('p', `${report.status.toUpperCase()} · ${Object.values(report.checks).filter(Boolean).length} checks · detached Composer install · authenticated MCP HTTP`, 'mode-note'));
  for (const [name, passed] of Object.entries(report.checks)) container.append(node('p', `${passed ? '✓' : '✗'} ${name.replaceAll('_', ' ')}`));
  container.append(catalogDetails('Actual HTTP calls, doctor and installation', catalogJson(report)));
}
function renderCatalogFootprint() {
  const report = catalogEvidence.discoveryFootprint, container = byId('catalog-footprint-body'); container.replaceChildren();
  if (!report) { container.append(node('p', 'Discovery measurement unavailable.')); return; }
  const table = node('table'), head = node('thead'), row = node('tr'), body = node('tbody');
  ['Approved tools', 'Direct advertised tokens', 'Catalog advertised tokens'].forEach(label => row.append(node('th', label))); head.append(row);
  for (const size of [...new Set(report.samples.map(sample => sample.catalogTools))]) {
    const direct = report.samples.find(sample => sample.catalogTools === size && sample.mode === 'direct');
    const catalog = report.samples.find(sample => sample.catalogTools === size && sample.mode === 'catalog');
    const row = node('tr'); row.append(node('td', size), node('td', number(direct.tokens)), node('td', number(catalog.tokens))); body.append(row);
  }
  table.append(head, body); const scroll = node('div', undefined, 'table-scroll'); scroll.append(table);
  container.append(scroll, node('p', `Offline ${report.encoding} tokenization, ${report.library}. Synthetic four-field tools; one serialized SDK tools/list result. No model, framing, search results, task data or history. Separate from provider usage and billing.`, 'measurement-note'));
}
async function loadCatalogEvidence() {
  try {
    const response = await fetch(evidenceUrl('/demo/catalog-proof'), {cache: 'no-store'});
    if (!response.ok) throw new Error('Catalog evidence unavailable');
    catalogEvidence = await response.json();
    const selector = byId('catalog-attempt'); selector.replaceChildren();
    catalogEvidence.attempts.forEach((attempt, index) => {
      const option = node('option', `${index + 1} · ${attempt.mode} · ${attempt.status} · ${new Date(attempt.createdAt).toLocaleString()}`); option.value = index; selector.append(option);
    });
    selector.value = String(Math.max(0, catalogEvidence.attempts.length - 1));
    renderCatalogAttempt(); renderCatalogControls(); renderCatalogAdoption(); renderCatalogFootprint();
    catalogConversation('catalog-conversation-body', catalogRun('follow_up'));
    catalogConversation('catalog-recovery-body', catalogRun('boundary'));
    evidenceChanged();
  } catch {
    for (const id of ['catalog-evidence-body', 'catalog-conversation-body', 'catalog-recovery-body', 'catalog-controls-body', 'catalog-adoption-body']) byId(id).textContent = 'Catalog evidence could not be loaded. Open the retained JSON report or run the capture command locally.';
  }
}
byId('catalog-attempt').addEventListener('change', renderCatalogAttempt);
loadCatalogEvidence();

// Reviewed HTTP messages from a detached install, separate from native chat and model benchmarks.
let httpCatalogEvidence = null;
function httpMainCalls() {
  return ['discovery','authorized','restricted'].map(label => {
    const row = httpCatalogEvidence.capture.calls.find(call => call.label === label);
    return {tool:row.request.params.name, arguments:row.request.params.arguments, response:row.response.result};
  });
}
function nativeCallCard(call, index) {
  const card = node('article', undefined, 'catalog-turn');
  const heading = node('div', undefined, 'native-call-heading');
  heading.append(node('h3', `${index + 1}. ${['Discover the approved tool', 'Read published article 101', 'Request restricted article 202'][index]}`), node('span', call.response.isError ? 'DENIED' : 'SUCCEEDED', 'status-pill'));
  const columns = node('div', undefined, 'native-call-grid');
  const request = node('div'), response = node('div');
  request.append(node('span', `ACTUAL REQUEST · ${call.tool}`, 'tour-label'), catalogJson(call.arguments));
  response.append(node('span', 'ACTUAL RESPONSE · structuredContent', 'tour-label'));
  if (index === 0) {
    response.append(node('p', 'publication_read · required integer id'), catalogDetails('Full discovered contract', catalogJson(call.response.structuredContent)));
  } else response.append(catalogJson(call.response.structuredContent));
  columns.append(request, response); card.append(heading, columns);
  card.append(catalogDetails('Complete MCP response envelope', catalogJson(call.response)));
  return card;
}
function nativeSourceExcerpt(role) {
  const source = httpCatalogEvidence.implementation[role].source;
  const start = source.indexOf(role === 'handler' ? "#[AsTool('publication_read'" : 'final readonly class ReadPolicy');
  return start < 0 ? source : source.slice(start);
}
function renderNativeRehearsal() {
  const report = httpCatalogEvidence, body = byId('native-rehearsal-body'), implementation = byId('native-implementation-body');
  body.replaceChildren(); implementation.replaceChildren();
  if (report?.verification?.passed !== true) { body.append(node('p', 'The HTTP proof did not pass verification. Inspect the raw report before using it as evidence.')); return; }
  body.append(node('p', `Verified HTTP requests · ${new Date(report.capture.capturedAt).toLocaleDateString()} · fresh Symfony app · process creation disabled`, 'mode-note'));
  httpMainCalls().forEach((call, index) => body.append(nativeCallCard(call, index)));
  body.append(node('p', 'The next execution is blocked, even after restarting the server and reconnecting. Budgets belong to the authenticated user.', 'doc-callout'));
  for (const label of ['exhausted', 'after_restart', 'call_budget']) body.append(catalogDetails(label.replaceAll('_', ' '), catalogJson(report.capture.calls.find(call => call.label === label))));
  body.append(node('p', 'The restricted request returned tool_denied and no article data. A permitted alternative is article 101, already retrieved.', 'measurement-note'), catalogDetails('Provenance and message verification', catalogJson({scope:report.capture.scope, captureSha256:report.captureSha256, verification:report.verification, installation:report.capture.installation, httpChecks:report.capture.httpChecks})));
  const columns = node('div', undefined, 'native-call-grid native-implementation');
  for (const [role, title] of [['handler','The registered handler'], ['policy','The application policy']]) {
    const source = report.implementation[role], card = node('article');
    card.append(node('h3', title), node('span', `${source.path} · excerpt`, 'tour-label'), sourceBlock(nativeSourceExcerpt(role), true), proofLink(`https://github.com/ronaldtebrake/symfony-hackathon/blob/main/${source.path}`, 'Open source ↗'), catalogDetails('Full PHP source', sourceBlock(source.source, true)));
    columns.append(card);
  }
  implementation.append(columns);
}
async function loadNativeRehearsal() {
  try {
    const response = await fetch(evidenceUrl('/demo/http-catalog'), {cache:'no-store'});
    if (!response.ok) throw new Error('HTTP proof unavailable');
    httpCatalogEvidence = await response.json();
    renderNativeRehearsal(); renderCatalogAdoption(); renderCatalogControls(); evidenceChanged();
  } catch {
    byId('native-rehearsal-body').textContent = 'HTTP MCP evidence could not be loaded. Open the retained JSON report.';
    byId('native-implementation-body').textContent = 'Application source unavailable.';
  }
}
loadNativeRehearsal();
