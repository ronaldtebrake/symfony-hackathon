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
  for (const [label,title] of [['authorized','Approved read'],['restricted','Restricted read'],['after_revocation','Permission revoked'],['regrant_exhausted','Regrant preserves budget'],['after_restart','Budget survives restart'],['call_budget','Call budget']]) {
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
  ['Historical STDIO fixture tools', 'Direct advertised tokens', 'STDIO catalog tokens'].forEach(label => row.append(node('th', label))); head.append(row);
  for (const size of [...new Set(report.samples.map(sample => sample.catalogTools))]) {
    const direct = report.samples.find(sample => sample.catalogTools === size && sample.mode === 'direct');
    const catalog = report.samples.find(sample => sample.catalogTools === size && sample.mode === 'catalog');
    const row = node('tr'); row.append(node('td', size), node('td', number(direct.tokens)), node('td', number(catalog.tokens))); body.append(row);
  }
  table.append(head, body); const scroll = node('div', undefined, 'table-scroll'); scroll.append(table);
  container.append(scroll, node('p', `Offline ${report.encoding} tokenization, ${report.library}. Synthetic four-field tools; one serialized SDK tools/list result from the retained STDIO catalog. HTTP entry-tool descriptions differ; these are not HTTP schema counts. No model, framing, search results, task data or history. Separate from provider usage and billing.`, 'measurement-note'));
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
  return ['revocation_discovery','before_revocation','after_revocation','revoked_discovery'].map(label => {
    const row = httpCatalogEvidence.capture.calls.find(call => call.label === label);
    return {tool:row.request.params.name, arguments:row.request.params.arguments, response:row.response.result};
  });
}
function nativeCallCard(call, index) {
  const card = node('article', undefined, 'catalog-turn');
  const heading = node('div', undefined, 'native-call-heading');
  heading.append(node('h3', `${index + 1}. ${call.title ?? ['Discover with permission', 'Read published article 101', 'Repeat article 101 after revocation', 'Search again: no visible contract'][index]}`), node('span', call.response.isError ? 'DENIED' : 'SUCCEEDED', 'status-pill'));
  const columns = node('div', undefined, 'native-call-grid');
  const request = node('div'), response = node('div');
  request.append(node('span', `ACTUAL REQUEST · ${call.tool}`, 'tour-label'), catalogJson(call.arguments));
  response.append(node('span', 'ACTUAL RESPONSE · structuredContent', 'tour-label'));
  if (index === 0) {
    response.append(node('p', (call.response.structuredContent?.tools ?? []).map(tool => tool.name).join(', ') || 'No visible contract'), catalogDetails('Full discovered contract', catalogJson(call.response.structuredContent)));
  } else response.append(catalogJson(call.response.structuredContent));
  columns.append(request, response); card.append(heading, columns);
  card.append(catalogDetails('Complete MCP response envelope', catalogJson(call.response)));
  return card;
}
function nativeSourceExcerpt(role) {
  const source = httpCatalogEvidence.implementation[role].source;
  const start = source.indexOf(role === 'handler' ? "#[AsTool('publication_read'" : role === 'controller' ? 'final readonly class ArticleController' : 'final class ArticleVoter');
  return start < 0 ? source : source.slice(start);
}
function renderNativeRehearsal() {
  const report = httpCatalogEvidence, body = byId('native-rehearsal-body'), implementation = byId('native-implementation-body');
  body.replaceChildren(); implementation.replaceChildren();
  if (report?.verification?.passed !== true) { body.append(node('p', 'The HTTP proof did not pass verification. Inspect the raw report before using it as evidence.')); return; }
  body.append(node('p', `Verified HTTP requests · ${new Date(report.capture.capturedAt).toLocaleDateString()} · fresh Symfony app · process creation disabled`, 'mode-note'));
  httpMainCalls().forEach((call, index) => {
    if (index === 2) body.append(node('span', 'OPERATOR REVOKES PERMISSION · SAME AUTHENTICATED USER', 'tour-label'), catalogJson(report.capture.permissionChange), node('p', 'No server restart, reconnect or budget reset between these reads. The next request reloads roles from the application’s local permission fixture.', 'measurement-note'));
    body.append(nativeCallCard(call, index));
  });
  body.append(node('p', 'The next execution is blocked, even after restarting the server and reconnecting. Budgets belong to the authenticated user.', 'doc-callout'));
  for (const label of ['restricted', 'exhausted', 'after_restart', 'call_budget', 'regrant_exhausted']) body.append(catalogDetails(label.replaceAll('_', ' '), catalogJson(report.capture.calls.find(call => call.label === label))));
  body.append(node('p', 'Revocation blocks new reads. It cannot erase the article already returned to the agent. An answer from conversation history is not a fresh authorized result.', 'measurement-note'), catalogDetails('Provenance and message verification', catalogJson({scope:report.capture.scope, captureSha256:report.captureSha256, verification:report.verification, installation:report.capture.installation, httpChecks:report.capture.httpChecks})));
  const columns = node('div', undefined, 'native-call-grid native-implementation');
  for (const [role, title] of [['handler','The registered handler'], ['policy','One voter for discovery and reads'], ['controller','The same voter in a controller']]) {
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
    renderNativeRehearsal(); renderHttpConversationPicker(); renderCatalogAdoption(); renderCatalogControls(); evidenceChanged();
  } catch {
    byId('http-conversation-body').textContent = 'The reviewed model conversation could not be loaded.';
    byId('native-rehearsal-body').textContent = 'HTTP MCP evidence could not be loaded. Open the retained JSON report.';
    byId('native-implementation-body').textContent = 'Application source unavailable.';
  }
}
loadNativeRehearsal();

function httpConversationAttempts() {
  const client = byId('http-conversation-client').value;
  return (client === 'claude-comparison' ? httpCatalogEvidence?.claudeComparison : client === 'claude' ? httpCatalogEvidence?.nativeCodeMode : httpCatalogEvidence?.conversation)?.attempts ?? [];
}
function renderHttpConversationPicker() {
  const attempts = httpConversationAttempts();
  const select = byId('http-conversation-attempt'); select.replaceChildren();
  attempts.forEach((run, index) => { const option = node('option', `${index + 1} · ${run.path ? `${run.path === 'native' ? 'Native Code Mode' : 'Regular MCP'} · ` : ''}${run.verification?.passed ? 'Verified' : 'Review required'} · ${new Date(run.createdAt).toLocaleString()}`); option.value = String(index); select.append(option); });
  if (attempts.length) select.value = String(attempts.length - 1);
  renderHttpConversation();
}
function renderHttpConversation() {
  if (byId('http-conversation-client').value === 'claude-comparison') { renderClaudeComparison(); return; }
  if (byId('http-conversation-client').value === 'claude') { renderClaudeCodeMode(); return; }
  const attempts = httpConversationAttempts();
  const run = attempts[Number(byId('http-conversation-attempt').value)];
  const body = byId('http-conversation-body'); body.replaceChildren();
  if (!run) { body.append(node('p', 'No reviewed live HTTP conversation is available. The operator proof remains below.')); return; }
  body.append(node('p', `${run.verification?.passed ? 'VERIFIED CONVERSATION' : 'REVIEW REQUIRED'} · Symfony AI + Hugging Face · ${run.model}`, 'mode-note'));
  if (run.reviewNote) body.append(run.verification?.passed ? catalogDetails('Capture notes · earlier attempt retained', node('p', run.reviewNote)) : node('p', run.reviewNote, 'doc-callout'));
  const metrics = node('div', undefined, 'hf-metrics');
  const calls = (run.http ?? []).filter(row => row.request.method === 'tools/call');
  for (const [label,value] of [['Provider tokens',run.trace?.providerTokens?.total],['Model requests',run.trace?.modelRequests],['Actual MCP calls',calls.length]]) {
    const card = node('div'); card.append(node('span',label),node('strong',number(value))); metrics.append(card);
  }
  body.append(metrics);
  run.turns.forEach((turn,index) => {
    if (index === 1 && run.permissionChange) body.append(node('span','OPERATOR REVOKES PERMISSION','eyebrow'),catalogJson({command:run.permissionChange.command,output:run.permissionChange.output}),node('p','Same authenticated user and connection. No budget reset.','measurement-note'));
    body.append(node('span',`TURN ${index + 1} · USER → SYMFONY AI AGENT`,'eyebrow'),node('blockquote',turn.question,'tour-question'));
    calls.forEach((row,callIndex) => {
      if (row.phase !== (index === 0 ? 'lookup' : 'revoked')) return;
      const card = nativeCallCard({tool:row.request.params.name,arguments:row.request.params.arguments,response:row.response.result,title:row.request.params.name === 'search_tools' ? (row.phase === 'lookup' ? 'Discover an approved article reader' : 'Check current tool visibility') : (row.phase === 'lookup' ? 'Read published article 101' : 'Repeat the read after revocation')},callIndex);
      const operations = row.profiler?.operations ?? [];
      for (const operation of operations) {
        const events = operation.nativeEvents.map(event => event.phase).join(' → ');
        const budget = operation.budget.at(-1);
        card.append(node('p',`Symfony trace: ${operation.status}${operation.reason ? ` · ${operation.reason}` : ''}. ${events ? `Handler: ${events}.` : 'No native handler events.'} ${budget ? `${budget.attempts} / 2 execution attempts used.` : ''}`,'mode-note'));
      }
      card.append(catalogDetails('Actual JSON-RPC exchange and bounded profiler trace',catalogJson(row))); body.append(card);
    });
    let answer; try { answer = JSON.parse(turn.answer); } catch { answer = null; }
    body.append(node('span','RECORDED MODEL ANSWER','eyebrow'));
    if (answer?.explanation) body.append(node('p',answer.explanation));
    if (answer?.status === 'read' && answer.title) body.append(node('strong',answer.title));
    body.append(catalogDetails('Exact structured answer',catalogJson(answer ?? turn.answer ?? 'No answer completed.')));
  });
  const total = attempts.reduce((sum,attempt) => sum + (attempt.trace?.providerTokens?.total ?? 0),0);
  body.append(node('p',`${attempts.length} retained live attempt${attempts.length === 1 ? '' : 's'} · ${number(total)} reported tokens across all attempts. Provider-reported input + output; no baseline, savings percentage or billing claim. No answer review or rewrite was applied.`,'measurement-note'));
  body.append(catalogDetails('Model usage, history digests and verification',catalogJson({verification:run.verification,currentSourceMatches:run.currentSourceMatches,trace:run.trace,systemPrompt:run.systemPrompt,settings:run.settings,permissionChange:run.permissionChange,sourceSha256:run.sourceSha256,scope:run.scope})));
  if (!run.currentSourceMatches) body.append(node('p','The captured application source differs from the current checkout.','doc-callout'));
}
byId('http-conversation-attempt').addEventListener('change',renderHttpConversation);
byId('http-conversation-client').addEventListener('change',renderHttpConversationPicker);
const requestedHttpClient = new URLSearchParams(window.location.search).get('client');
if (['claude', 'claude-comparison'].includes(requestedHttpClient)) byId('http-conversation-client').value = requestedHttpClient;

function renderClaudeCodeMode() {
  const run = httpConversationAttempts()[Number(byId('http-conversation-attempt').value)];
  const body = byId('http-conversation-body'); body.replaceChildren();
  if (!run) { body.append(node('p', 'No reviewed native Code Mode capture is available.')); return; }
  renderClaudeCapture(run, body);
}

function renderClaudeCapture(run, body) {
  const calls = run.http.filter(row => row.request.method === 'tools/call');
  body.append(node('p', `${run.verification.passed ? 'VERIFIED CONVERSATION' : 'REVIEW REQUIRED'} · ${run.client.name} ${run.client.version} · ${run.client.model}`, 'mode-note'));
  body.append(node('p', run.client.nativeCodeMode ? 'Claude writes the JavaScript. Symfony keeps the permissions.' : 'Claude calls MCP tools directly. Symfony keeps the same permissions.', 'lede'));
  const metrics = node('div', undefined, 'hf-metrics');
  for (const [label, value] of [['Generated JavaScript blocks', run.code.length], ['Actual MCP calls', calls.length], ['Execution attempts, including denial', calls.filter(row => row.request.params.name === 'execute_tools').length]]) {
    const card = node('div'); card.append(node('span', label), node('strong', number(value))); metrics.append(card);
  }
  body.append(metrics);
  let callIndex = 0;
  const appendCall = row => {
    const card = nativeCallCard({tool:row.request.params.name, arguments:row.request.params.arguments, response:row.response.result}, callIndex++);
    const operation = row.profiler?.operations?.[0];
    if (operation) card.append(node('p', operation.nativeEvents.length ? 'Symfony handler: requested → succeeded.' : `${operation.reason === 'tool_denied' ? 'Permission denied. ' : ''}No article handler invocation.`, 'mode-note'));
    card.append(catalogDetails('Actual HTTP exchange and Symfony profiler trace', catalogJson(row)));
    body.append(card);
  };
  run.turns.forEach((turn, index) => {
    if (index === 1 && run.permissionChange) body.append(node('span', 'OPERATOR REVOKES PERMISSION', 'eyebrow'), catalogJson({command: run.permissionChange.command, output: run.permissionChange.output}), node('p', 'Same authenticated user and Claude session. No budget reset.', 'measurement-note'));
    body.append(node('span', `TURN ${index + 1} · USER → CLAUDE`, 'eyebrow'), node('blockquote', turn.question, 'tour-question'));
    if (!run.client.nativeCodeMode) {
      const discoveryCalls = run.agentCalls.filter(call => call.turn === index && !call.name.startsWith('mcp__symfony_catalog__'));
      if (discoveryCalls.length) body.append(catalogDetails('Actual client discovery', catalogJson(discoveryCalls)));
      calls.filter(row => row.phase === (index === 0 ? 'lookup' : 'revoked')).forEach(appendCall);
    }
    const discovery = node('div');
    let discoveryShown = false;
    for (const block of run.code.filter(item => item.turn === index)) {
      const nested = run.agentCalls.filter(call => call.codeId === block.id && call.name.startsWith('mcp__symfony_catalog__'));
      if (!nested.length) {
        discovery.append(sourceBlock(block.code), catalogDetails('Actual Code Mode result', sourceBlock(block.result)));
        continue;
      }
      if (discovery.childNodes.length && !discoveryShown) {
        const details = catalogDetails('Client discovery · every step retained', discovery);
        body.append(details);
        discoveryShown = true;
      }
      const source = node('article', undefined, 'catalog-turn');
      source.append(node('span', 'GENERATED JAVASCRIPT · EXECUTED BY CLAUDE', 'eyebrow'), sourceBlock(block.code));
      body.append(source);
      for (const call of nested) {
        const row = calls[callIndex];
        if (row) appendCall(row);
      }
      source.append(catalogDetails('Actual Code Mode result', sourceBlock(block.result)));
    }
    body.append(catalogDetails('Exact recorded model answer', node('p', turn.answer, 'recorded-agent-answer')));
  });
  if (!run.path) body.append(node('p', 'Original native rehearsal. Includes an initial client-discovery miss. Its prompt explicitly requested Code Mode, so it is not part of the matched comparison.', 'measurement-note'));
  if (run.usage) body.append(catalogDetails('Token accounting · unique responses and duplicate rows', catalogJson({usage:run.usage, usageRows:run.usageRows, answerReview:run.answerReview})));
  body.append(catalogDetails('Verification, source binding and capture method', catalogJson({verification:run.verification, currentSourceMatches:run.currentSourceMatches, client:run.client, capture:run.capture, sourceSha256:run.sourceSha256, installation:run.installation, cleanup:run.cleanup, scope:run.scope})));
  if (!run.currentSourceMatches) body.append(node('p', 'The captured application source differs from the current checkout.', 'doc-callout'));
}

function renderClaudeComparison() {
  const report = httpCatalogEvidence?.claudeComparison;
  const body = byId('http-conversation-body'); body.replaceChildren();
  if (!report?.attempts?.length) { body.append(node('p', 'No matched Claude attempts are available.')); return; }
  body.append(node('h3', 'Native Code Mode or regular MCP?'), node('p', 'Same model, questions and Symfony permissions. One article lookup, then a revoked read.', 'lede'));
  for (const pair of report.pairs) {
    const runs = pair.attemptIds.map(id => report.attempts.find(run => run.id === id));
    body.append(node('p', `${pair.id} · ${pair.matched ? 'Matched configuration' : 'Not a matched pair'} · ${pair.bothCorrect ? 'Both answers correct; access checks passed' : 'Inspect incomplete or failed checks below'}`, 'mode-note'));
    const wrap = node('div', undefined, 'table-scroll claude-comparison-table');
    const table = node('table', undefined, 'live-table');
    const head = node('thead'); const header = node('tr'); header.append(node('th', 'Measurement'));
    runs.forEach(run => header.append(node('th', run.path === 'native' ? 'Native Code Mode' : 'Regular MCP'))); head.append(header); table.append(head);
    const rows = node('tbody');
    for (const [label, value] of [
      ['All input + output tokens', run => number(run.usage.total)],
      ['Uncached input', run => number(run.usage.uncachedInput)],
      ['Cache write input', run => number(run.usage.cacheWrite)],
      ['Cache read input', run => number(run.usage.cacheRead)],
      ['Output', run => number(run.usage.output)],
      ['Model requests', run => number(run.usage.requests)],
      ['First request input footprint', run => { const u = run.usage.responses[0]?.usage; return u ? number(u.input_tokens + u.cache_creation_input_tokens + u.cache_read_input_tokens) : 'Unavailable'; }],
      ['Actual MCP calls', run => number(run.http.filter(row => row.request.method === 'tools/call').length)],
      ['Prompt-to-answer time¹', run => `${run.activeDurationSeconds.toFixed(1)} s`],
      ['Correct answers + security checks', run => run.verification.passed ? 'Passed' : 'Review required'],
    ]) {
      const row = node('tr', undefined, label === 'All input + output tokens' ? 'live-primary' : ''); const labelCell = node('th', label); labelCell.scope = 'row'; row.append(labelCell);
      runs.forEach(run => row.append(node('td', run.usage.complete || !label.includes('token') ? value(run) : 'Incomplete usage'))); rows.append(row);
    }
    table.append(rows); wrap.append(table); body.append(wrap);
    const regular = runs.find(run => run.path === 'regular'); const native = runs.find(run => run.path === 'native');
    if (pair.bothCorrect && regular?.usage.complete && native?.usage.complete) {
      const delta = native.usage.total - regular.usage.total;
      body.append(node('p', `In this pair, native Code Mode used ${number(Math.abs(delta))} ${delta > 0 ? 'more' : 'fewer'} tokens (${Math.abs(delta / regular.usage.total * 100).toFixed(1)}%) and ${native.usage.requests} model requests versus ${regular.usage.requests}.`, 'doc-callout'));
    }
  }
  body.append(node('p', 'Total includes cached input. These are main-conversation API counters, not a bill; cache reads and writes have different prices. One short lookup cannot establish general savings or reliability.', 'measurement-note'));
  body.append(node('p', 'The native follow-up groups the denied read and discovery check in one JavaScript block. Its larger input footprint offsets the saved request here. Both paths expose the same two Symfony catalog tools; this does not compare a catalog against a full tool list.'));
  body.append(node('p', '¹ Sum of the two prompt-to-answer intervals. Excludes the operator pause; includes hooks, tools and network time. Cache state was not controlled. Internal classifier and background usage is not included.', 'measurement-note'));
  body.append(catalogDetails('Fairness, cache behavior and counting method', catalogJson(report.protocol)));
  body.append(node('p', `${report.attempts.length} attempts retained. No retries, answer repairs or failed attempts were removed. Correctness combines exact tool outcomes with a review of the unedited answers, bound to their hashes.`, 'measurement-note'));
  const run = report.attempts[Number(byId('http-conversation-attempt').value)];
  if (run) { body.append(node('h3', `Inspect attempt ${Number(byId('http-conversation-attempt').value) + 1} · ${run.path === 'native' ? 'Native Code Mode' : 'Regular MCP'}`)); renderClaudeCapture(run, body); }
}
