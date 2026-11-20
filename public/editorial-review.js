'use strict';

// The local workbench and static Pages build read the same captured SDK exchange.
function editorialEvidence(report) {
  const findingsCall = report.calls.find(call => call.label === 'findings');
  const result = findingsCall.response.result.structuredContent;
  const ranking = report.followUp.highestImpact;
  const content = byId('editorial-evidence');
  const summary = node('p', `Symfony made ${findingsCall.trace.sourceReads.length} authorized source reads to check ${result.audited} published articles. The audit returned ${result.findings.length} findings. Each carries an article ID, issue codes and, for missing alt text, an image ID.`);
  const messages = {
    discover: ['Discover the advertised tools', 'All three contracts are visible. This user has permission to run only the findings tool.'],
    findings: ['Read the permitted findings', 'Symfony joins the articles and images before returning the allowed fields.'],
    raw_articles: ['Request article records', 'The reviewer requests the underlying article data.'],
    raw_media: ['Request image records', 'The reviewer requests the underlying media data.'],
    refresh_after_revocation: ['Refresh after revocation', 'An operator withdrew the grant. The same reviewer tries the findings tool again.'],
  };
  const list = node('ol', undefined, 'review-exchange');
  for (const call of report.calls) {
    const [label, explanation] = messages[call.label];
    const result = call.response.result;
    const article = node('li');
    const details = node('details');
    const heading = node('summary');
    const reason = result.structuredContent?.error?.reason;
    heading.append(node('strong', label), node('code', reason ?? (call.label === 'discover' ? 'tools/list' : call.request.params.name)));
    details.append(heading, node('p', explanation));
    for (const [name, payload] of [['MCP request', call.request], ['MCP response', call.response], ['Symfony trace', call.trace]]) {
      if (payload) details.append(node('h4', name), sourceBlock(JSON.stringify(payload, null, 2)));
    }
    article.append(details);
    list.append(article);
    if (call.label === 'findings') {
      const step = node('li', undefined, 'review-local-step');
      step.append(node('h4', 'Rank the images in the client'), node('p', `Images ${ranking.map(group => group.image_id).join(', ')} tie for first with ${ranking[0].article_ids.length} affected articles each. Additional MCP calls: ${report.followUp.additionalMcpCalls}.`));
      list.append(step);
    }
  }
  content.replaceChildren(summary, list);
}

async function readReviewerEvidence(url) {
  const response = await fetch(url);
  if (!response.ok) throw new Error();
  return response.json();
}

function editorialCallReceipt(call, index, title) {
  const params = call.request.params;
  const operations = params?.arguments?.calls;
  const summary = params?.name === 'execute_tools'
    ? operations.map(operation => operation.name).join(', ')
    : params?.arguments?.query ?? call.request.method;
  const receipt = node('details');
  const heading = node('summary');
  const error = call.response.result?.structuredContent?.error?.reason;
  heading.append(node('strong', title ?? `${index + 1}. ${params?.name ?? call.request.method}`));
  if (summary !== call.request.method) heading.append(' ', node('span', summary));
  if (error) heading.append(' ', node('code', error));
  receipt.append(heading);
  for (const [label, value] of [['MCP request', call.request], ['MCP response', call.response], ['Symfony trace', call.trace]]) {
    if (value) receipt.append(node('h4', label), sourceBlock(JSON.stringify(value, null, 2)));
  }
  return receipt;
}

function showEditorialReplay(run) {
  const checkpoint = run.editorEvents.find(event => event.type === 'descriptions');
  const result = run.outcome;
  const partial = result.status === 'partial';
  const nextCall = run.calls[checkpoint.afterCall];
  const response = nextCall.response.result.structuredContent;
  const cards = node('div', undefined, 'audit-results');
  const first = node('article'), next = node('article');
  first.append(node('span', 'Delivered to the editor', 'eyebrow'),
    node('h4', `${checkpoint.value.missingDescriptionArticleIds.length} articles need a description`),
    node('p', `Articles ${checkpoint.value.missingDescriptionArticleIds.join(', ')}. The workflow checked ${checkpoint.value.audited} published articles before it requested any image records.`));
  next.dataset.outcome = result.status;
  next.append(node('span', partial ? 'Permission revoked' : 'Permission retained', 'eyebrow'),
    node('h4', partial ? 'Image check stopped' : `${result.media.missingAlt.length} shared images need alt text`),
    node('p', partial
      ? `Symfony returned ${response.error.reason} on the first image read. ${response.completedCalls} operations completed in that request. The editor keeps the article list. No image result was delivered.`
      : `The editor received findings for images ${result.media.missingAlt.map(row => row.image_id).join(', ')} after the workflow checked ${result.media.checked} images. This run completed both steps.`));
  cards.append(first, next);

  const receipts = node('ol', undefined, 'review-exchange audit-evidence-list');
  run.calls.forEach((call, index) => {
    if (index === checkpoint.afterCall) {
      const event = node('li', undefined, 'review-local-step');
      event.append(node('h4', 'Description findings delivered'), node('p', partial
        ? 'The operator revoked the editor’s audit grant here, before the next MCP call.'
        : 'The editor retained access for the image check.'));
      receipts.append(event);
    }
    const item = node('li');
    item.append(editorialCallReceipt(call, index));
    receipts.append(item);
  });
  const mediaReceipt = editorialCallReceipt(nextCall, checkpoint.afterCall, `Inspect call ${checkpoint.afterCall + 1} · first image read`);
  mediaReceipt.classList.add('proof-details');
  byId('editorial-replay-result').replaceChildren(cards,
    catalogDetails('What the editor received, in order', sourceBlock(JSON.stringify(run.editorEvents, null, 2))),
    mediaReceipt,
    catalogDetails(`All ${run.calls.length} recorded MCP calls and Symfony traces`, receipts));
}

const replayMenu = byId('editorial-replay-run');
let replayCapture;
async function loadEditorialReplay() {
  if (byId('demo-tour').hidden || replayCapture) return;
  replayMenu.disabled = true;
  try {
    const report = await readReviewerEvidence(evidenceUrl('/demo/editorial-audit-replay'));
    if (report.format !== 'symfony-editorial-audit-replay/v1') throw new Error();
    byId('editorial-replay-source').replaceChildren(sourceBlock(report.source, true));
    showEditorialReplay(report.runs[replayMenu.value]);
    replayCapture = report;
    replayMenu.disabled = false;
  } catch {
    byId('editorial-replay-result').textContent = 'The recorded replay could not be loaded. The full capture is linked below.';
  }
}
replayMenu.addEventListener('change', () => showEditorialReplay(replayCapture.runs[replayMenu.value]));
window.addEventListener('hashchange', loadEditorialReplay);
loadEditorialReplay();

readReviewerEvidence(evidenceUrl('/demo/editorial-review')).then(report => {
  if (report.format !== 'symfony-editorial-review/v1' || report.verified !== true) throw new Error();
  editorialEvidence(report);
}).catch(() => {
  byId('editorial-evidence').textContent = 'The verified reviewer capture could not be loaded.';
});

readReviewerEvidence(evidenceUrl('/demo/editorial-review-agent')).then(report => {
  if (report.format !== 'symfony-editorial-review-agent-v1' || report.turns.length !== 4) throw new Error();
  const content = byId('editorial-agent-evidence');
  const list = node('ol', undefined, 'review-exchange');
  const titles = ['Ask for the publication audit', 'Ask which image to fix first', 'Try the full article and image records', 'Revoke access and ask for a fresh audit'];
  for (const [index, turn] of report.turns.entries()) {
    const row = node('li', undefined, 'review-turn');
    const heading = node('h4', titles[index]);
    const calls = node('span', `${turn.calls.length} MCP call${turn.calls.length === 1 ? '' : 's'}`, 'turn-count');
    heading.append(calls);
    // Each captured answer opens with a complete sentence suitable for an exact excerpt.
    const firstSentence = turn.answer.match(/^[^\n]+?[.!?](?=\s|$)/)?.[0] ?? turn.answer.split('\n')[0];
    const quote = node('blockquote', `“${firstSentence}”`);
    const details = node('details');
    details.append(node('summary', 'Full exchange'), node('h5', 'Reviewer'), node('p', turn.question), node('h5', 'Claude · original response'), sourceBlock(turn.answer));
    if (index === 0) details.append(node('p', 'The agent offered to retrieve titles. In turn three, Symfony denied both requests for the underlying records.', 'measurement-note'));
    if (turn.calls.length) {
      const wire = node('details', undefined, 'proof-details');
      wire.append(node('summary', 'Calls and HTTP traces'), sourceBlock(JSON.stringify({calls: turn.calls, http: turn.http}, null, 2)));
      details.append(wire);
    }
    row.append(heading, quote, details);
    list.append(row);
  }
  content.replaceChildren(list);
}).catch(() => {
  byId('editorial-agent-evidence').textContent = 'The recorded reviewer conversation could not be loaded.';
});

const groupReceipts = byId('group-receipts');
const groupRunMenu = byId('group-proof-run');
let groupReport, discoveryFootprint;

function showGroupComparison() {
  const mode = groupRunMenu.value;
  const table = node('table');
  const head = table.createTHead().insertRow();
  for (const label of ['Measurement', 'Exact searches', 'Browse once', 'Open group']) {
    const cell = node('th', label);
    cell.scope = 'col';
    head.append(cell);
  }
  const paths = ['catalog', 'catalogBrowse', 'groups'];
  const runs = paths.map(path => groupReport.paths[path][mode]);
  const body = table.createTBody();
  function addRow(label, values) {
    const row = body.insertRow();
    const cell = node('th', label);
    cell.scope = 'row';
    row.append(cell);
    for (const value of values) row.insertCell().textContent = value.toLocaleString('en-US');
  }
  for (const [label, key] of [['Negotiation requests', 'negotiationRequests'], ['Discovery requests', 'discoveryRequests'], ['Execution requests', 'executionRequests'], ['Total requests', 'totalRequests'], ['Discovery response bytes', 'discoveryResponseBytes']]) {
    addRow(label, runs.map(run => run.metrics[key]));
  }
  addRow('Discovery tokens (offline)', paths.map(path => discoveryFootprint.paths[path].tokens));
  table.createCaption().textContent = mode === 'control' ? 'Access retained' : 'Access revoked before images';

  const [catalog, browse, group] = runs;
  const summary = node('p', 'Each run used the same reviewed PHP and made zero model requests. Discovery bytes count JSON-RPC response bodies. Discovery tokens count one copy of each result with o200k_harmony. They exclude negotiation, reader results, prompts and output.');
  const content = byId('group-proof-comparison');
  const metrics = node('div', undefined, 'group-metrics');
  metrics.append(table);
  content.replaceChildren(metrics, summary);
  const readerName = request => request.params?.name === 'execute_tools'
    ? request.params.arguments.calls?.[0]?.name
    : request.params?.name;
  const selected = [
    ['List the available groups', group, row => row.request.method === 'groups/list'],
    ['Open Publishing and see the reader schemas', group, row => row.request.method === 'groups/get'],
    ['Groups · list the first article IDs', group, row => row.request.method === 'tools/call' && readerName(row.request) === 'cms_list_articles'],
    ['Groups · read those articles', group, row => row.request.method === 'tools/call' && readerName(row.request) === 'cms_get_articles'],
    ['Groups · first image read', group, row => row.request.method === 'tools/call' && readerName(row.request) === 'cms_get_media'],
    ['Catalog · the single browse request', browse, row => row.request.params?.name === 'search_tools'],
    ['Catalog · the same image read', catalog, row => row.request.method === 'tools/call' && readerName(row.request) === 'cms_get_media'],
  ];
  for (const [label, run, findExchange] of selected) {
    const row = run.calls.find(findExchange);
    const contents = node('div');
    const request = {...row.request, params: {...row.request.params, _meta: run.clientMeta}};
    const reply = JSON.parse(groupReport.responses[row.responseSha256]);
    contents.append(node('h4', 'Request'), sourceBlock(JSON.stringify(request, null, 2)), node('h4', 'Response'), sourceBlock(JSON.stringify(reply, null, 2)));
    if (row.trace) contents.append(node('h4', 'Symfony trace'), sourceBlock(JSON.stringify(row.trace, null, 2)));
    content.append(catalogDetails(`${label} · request ${request.id}`, contents));
  }
  if (mode === 'revoked') content.append(catalogDetails('When the operator revoked access on the Groups run', sourceBlock(JSON.stringify(group.operator, null, 2))));
  content.append(catalogDetails('What the editor received', sourceBlock(JSON.stringify(group.editorEvents, null, 2))));
}

let groupLoad;
async function loadGroupComparison() {
  if (!groupReceipts.open || groupLoad) return;
  try {
    groupLoad = Promise.all(['/demo/editorial-groups', '/demo/editorial-groups-footprint'].map(url => readReviewerEvidence(evidenceUrl(url))));
    [groupReport, discoveryFootprint] = await groupLoad;
    showGroupComparison();
    groupRunMenu.disabled = false;
  } catch {
    groupLoad = null;
    groupRunMenu.disabled = true;
    byId('group-proof-comparison').textContent = 'The comparison could not be loaded. Open the linked JSON capture to inspect the recorded runs.';
  }
}
groupReceipts.addEventListener('toggle', loadGroupComparison);
groupRunMenu.addEventListener('change', showGroupComparison);
loadGroupComparison();

// The workbench reads public fixtures. Opening a receipt never contacts the refund app.
function refundReceipt(exchange) {
  const method = exchange.request.method;
  const target = exchange.request.params?.name ?? exchange.request.params?.uri;
  const body = node('div');
  body.append(node('p', 'HTTP ' + exchange.httpStatus + ' · ' + exchange.user));
  for (const [label, data] of [['MCP request', exchange.request], ['MCP response', exchange.response], ['Symfony trace', exchange.trace]]) {
    if (data) body.append(node('h5', label), sourceBlock(JSON.stringify(data, null, 2)));
  }
  return catalogDetails([method, target].filter(Boolean).join(' · '), body);
}

function displayRefundProtocol(root, report) {
  const body = node('div');
  body.append(node('p', 'A fixed HTTP client recorded these application checks. Each receipt contains the request and the actual Symfony response.'));
  for (const [label, exchange] of Object.entries(report.calls)) {
    const detail = refundReceipt(exchange);
    const summary = detail.querySelector('summary');
    summary.prepend(document.createTextNode(label.replaceAll('_', ' ') + ' · '));
    body.append(detail);
  }
  root.replaceChildren(body);
}

function displayRefundStudy(root, report) {
  const names = {bare:'Group alone', inline:'Inline instructions', skill:'Linked skill'};
  const rows = node('div', undefined, 'group-metrics');
  const table = node('table');
  table.createCaption().textContent = 'Whole two-turn conversations · ' + report.protocol.model + ' · ' + report.protocol.provider;
  const header = table.createTHead().insertRow();
  for (const label of ['Guidance', 'Result', 'Provider tokens', 'Model requests', 'Host setup calls', 'Agent tool calls']) {
    const cell = node('th', label);
    cell.scope = 'col';
    header.append(cell);
  }
  const body = table.createTBody();
  const details = [];
  for (const attempt of report.attempts) {
    const turns = attempt.turns;
    const row = body.insertRow();
    const heading = node('th', names[attempt.arm]);
    heading.scope = 'row';
    row.append(heading);
    row.insertCell().textContent = attempt.status;
    const tokenCell = row.insertCell();
    const usage = turns.map(turn => turn.usage);
    if (usage.length && usage.every(turn => Number.isFinite(turn?.total))) {
      const sum = key => usage.reduce((count, turn) => count + turn[key], 0).toLocaleString('en-US');
      tokenCell.append(node('strong', sum('total')));
      if (usage.every(turn => Number.isFinite(turn.prompt) && Number.isFinite(turn.completion))) {
        tokenCell.append(node('br'), node('small', sum('prompt') + ' input · ' + sum('completion') + ' output'));
      }
    } else {
      tokenCell.textContent = 'Unavailable';
    }
    for (const value of [turns.reduce((sum, turn) => sum + turn.providerRequests, 0), attempt.hostCalls.length, turns.reduce((sum, turn) => sum + turn.calls.length, 0)]) {
      row.insertCell().textContent = value;
    }
    const exchange = node('div');
    const setup = node('div');
    for (const call of attempt.hostCalls) setup.append(refundReceipt(call));
    exchange.append(catalogDetails('Host discovery and guidance', setup));
    for (const turn of turns) {
      if (turn.stage === 'revoked' && attempt.operator) exchange.append(node('h4', 'The operator revoked the reader’s grant'));
      exchange.append(node('h5', 'Support rep'), node('p', turn.question), node('h5', 'Recorded model answer'),
        sourceBlock(turn.answer ?? turn.errorType ?? 'No final answer was captured.'));
      for (const call of turn.calls) exchange.append(refundReceipt(call));
      exchange.append(catalogDetails('Scoring and reported usage', sourceBlock(JSON.stringify({checks:turn.checks, usage:turn.usage, modelRequests:turn.providerRequests}, null, 2))));
    }
    details.push(catalogDetails(names[attempt.arm] + ' · questions, answers and calls', exchange));
  }
  rows.append(table);
  root.replaceChildren(rows, node('p', 'The inline and linked versions supplied the same checklist text to the model. In the linked version, the host first fetched the manifest and the Markdown over MCP. Those two requests appear under host setup. The token counts are the provider’s input and output across both turns.'), ...details);
}

for (const [id, path, render] of [
  ['refund-wire', '/demo/refund-protocol', displayRefundProtocol],
  ['refund-attempts', '/demo/refund-skill', displayRefundStudy],
]) {
  const section = byId(id);
  const content = byId(id + '-body');
  let pending;
  async function loadRefund() {
    if (!section.open || pending) return;
    try {
      pending = readReviewerEvidence(evidenceUrl(path));
      render(content, await pending);
    } catch {
      pending = null;
      content.textContent = 'The viewer could not load this capture. The JSON file is linked below.';
    }
  }
  section.addEventListener('toggle', loadRefund);
  loadRefund();
}
