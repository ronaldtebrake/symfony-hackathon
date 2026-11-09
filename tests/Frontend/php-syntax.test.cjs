const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync, readdirSync} = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

// A deliberately small DOM: any HTML insertion fails this test immediately.
class Text {
  constructor(text) { this.textContent = text; }
}
class Element {
  constructor(tag = 'code') { this.tag = tag; this.children = []; this.classList = {add() {}}; }
  append(...children) { for (const child of children) this.children.push(...(child.tag === 'fragment' ? child.children : [child])); }
  replaceChildren(...children) { this.children = []; this.append(...children); }
  set textContent(text) { this.children = [new Text(text)]; }
  get textContent() { return this.children.map(child => child.textContent).join(''); }
  set innerHTML(_) { throw new Error('Source must never be parsed as HTML.'); }
}
const context = vm.createContext({document: {
  createTextNode: text => new Text(text),
  createElement: tag => { assert.equal(tag, 'span'); return new Element(tag); },
  createDocumentFragment: () => new Element('fragment'),
  querySelectorAll: () => [],
}});
vm.runInContext(readFileSync(path.join(__dirname, '../../public/php-syntax.js'), 'utf8'), context);
const syntax = context.PhpSyntax;

function assertSafeTree(element) {
  if (element instanceof Text) return;
  assert.ok(['code', 'span'].includes(element.tag));
  if (element.className) assert.match(element.className, /^(php-source-line|syntax-(comment|string|var|keyword|number))$/);
  element.children.forEach(assertSafeTree);
}
function assertExact(source) {
  assert.equal(syntax.tokenize(source).map(token => token.text).join(''), source);
  for (const render of [syntax.render, syntax.renderLines]) {
    const target = new Element();
    render(target, source);
    assert.equal(target.textContent, source, 'Highlighting and line numbers must preserve exact copyable PHP.');
    assertSafeTree(target);
    render(target, source);
    assert.equal(target.textContent, source, 'A refresh must not duplicate or rewrite source.');
  }
}

test('markup, comments and malformed PHP remain inert text', () => {
  for (const source of [
    '$x = "</code><script>alert(1)</script><img src=x onerror=alert(2)>"; return $x;',
    '/* </span><svg onload=alert(1)> */ return "&amp; &#x3c; < >";',
    '# comment with $tools and "strings"\r\nreturn $café + 12.5e-2;',
    '$x = "unterminated \\',
    '/* unterminated comment',
    '',
    '\n\r\nreturn true;\n',
  ]) assertExact(source);
});

test('multiline strings and comments keep their style across numbered lines', () => {
  const source = "<?php\n$value = <<<'HTML'\n<img onerror='alert(1)'>\n$not_a_variable\nHTML;\n/* line one\n line two */\nreturn $value;\n";
  assertExact(source);
  const tokens = syntax.tokenize(source);
  assert.ok(tokens.some(token => token.kind === 'string' && token.text.includes('$not_a_variable')));
  assert.ok(tokens.some(token => token.kind === 'comment' && token.text.includes('line two')));
  const target = new Element(); syntax.renderLines(target, source);
  assert.equal(target.children.length, source.split('\n').length);
});

test('every reviewed PHP program remains byte-for-byte copyable', () => {
  let checked = 0;
  function visit(value, key) {
    if (typeof value === 'string' && ['code', 'php'].includes(key)) { assertExact(value); checked++; }
    else if (Array.isArray(value) && key === 'programs') {
      value.filter(item => typeof item === 'string').forEach(source => { assertExact(source); checked++; });
    } else if (value && typeof value === 'object') Object.entries(value).forEach(([childKey, child]) => visit(child, childKey));
  }
  const directory = path.join(__dirname, '../../docs/evidence');
  for (const file of readdirSync(directory).filter(file => file.endsWith('.json'))) visit(JSON.parse(readFileSync(path.join(directory, file), 'utf8')));
  assert.ok(checked > 50, `Expected the actual evidence corpus, checked ${checked} programs.`);
});
