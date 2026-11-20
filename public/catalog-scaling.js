'use strict';
let catalogScalingEvidence = null;
function scalingTable(report) {
  const table = node('table'), head = node('thead'), row = node('tr'), body = node('tbody');
  ['Available tools', 'Advertised directly', 'Two-tool catalog', 'One retrieved contract*'].forEach(label => row.append(node('th', label)));
  head.append(row);
  for (const sample of report.samples) {
    const tr = node('tr');
    [sample.availableTools, sample.direct.tokens, sample.catalog.tokens, sample.selectedContract.tokens].forEach(value => tr.append(node('td', number(value))));
    body.append(tr);
  }
  table.append(head, body); const wrapper = node('div', undefined, 'table-scroll'); wrapper.append(table); return wrapper;
}
function renderScalingSelection() {
  if (!catalogScalingEvidence) return;
  const sample = catalogScalingEvidence.samples.find(row => row.availableTools === Number(byId('scaling-size').value));
  byId('scaling-direct').textContent = number(sample.direct.tokens);
  byId('scaling-catalog').textContent = number(sample.catalog.tokens);
  byId('scaling-direct-count').textContent = `${sample.direct.advertisedTools} advertised tools`;
  byId('scaling-summary').textContent = `${sample.availableTools} available tools. Still two advertised contracts. An exact-name search returns one contract (${sample.selectedContract.tokens} additional tokens in this fixture).`;
}
function renderScalingChart(report) {
  const ns = 'http://www.w3.org/2000/svg';
  const svg = document.createElementNS(ns, 'svg'); svg.setAttribute('viewBox', '0 0 960 320'); svg.setAttribute('role', 'img'); svg.setAttribute('aria-label', 'Offline tools/list tokens: direct schemas grow with the catalog; the two HTTP entry contracts remain constant. Exact counts are in the table below.');
  const add = (tag, attributes, text) => { const e = document.createElementNS(ns, tag); Object.entries(attributes).forEach(([key, value]) => e.setAttribute(key, value)); if (text) e.textContent = text; svg.append(e); return e; };
  const max = report.samples.at(-1).direct.tokens, x = n => 90 + (n / 128) * 820, y = n => 253 - (n / max) * 210;
  for (const tick of [0, 5000, 10000]) {
    add('line', {x1:90, x2:910, y1:y(tick), y2:y(tick), class:'scaling-grid'});
    add('text', {x:75, y:y(tick)+5, 'text-anchor':'end', class:'scaling-axis'}, number(tick));
  }
  for (const tick of [3, 40, 100, 128]) add('text', {x:x(tick), y:285, 'text-anchor':'middle', class:'scaling-axis'}, String(tick));
  add('text', {x:500, y:313, 'text-anchor':'middle', class:'scaling-axis'}, 'Available tools in the application');
  for (const kind of ['direct', 'catalog']) {
    add('polyline', {points:report.samples.map(s => `${x(s.availableTools)},${y(s[kind].tokens)}`).join(' '), class:`scaling-line scaling-${kind}`});
    for (const sample of report.samples) add('circle', {cx:x(sample.availableTools), cy:y(sample[kind].tokens), r:5, class:`scaling-dot scaling-${kind}`});
  }
  byId('scaling-chart').replaceChildren(svg);
}
fetch(evidenceUrl('/demo/catalog-scaling'), {cache:'no-store'}).then(response => {
  if (!response.ok) throw new Error('Schema measurement unavailable');
  return response.json();
}).then(report => {
  catalogScalingEvidence = report;
  const size = byId('scaling-size'); size.replaceChildren();
  for (const sample of report.samples) { const option = node('option', String(sample.availableTools)); option.value = sample.availableTools; size.append(option); }
  size.value = '100'; size.disabled = false;
  size.addEventListener('change', renderScalingSelection);
  renderScalingChart(report); renderScalingSelection();
  byId('scaling-table').replaceChildren(scalingTable(report));
  byId('scaling-state').textContent = 'Current HTTP contracts · offline measurement';
  if (typeof showDemoShot === 'function') showDemoShot();
}).catch(() => { byId('scaling-state').textContent = 'Measurement unavailable — inspect the retained evidence'; });
