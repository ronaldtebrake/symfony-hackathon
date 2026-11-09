/* Small display-only PHP lexer. Source is always inserted as text, never as HTML. */
(() => {
  'use strict';
  const keywords = new Set(('abstract and array as break callable case catch class clone const continue declare default die do echo else elseif empty enddeclare endfor endforeach endif endswitch endwhile enum eval exit extends final finally fn for foreach function global goto if implements include include_once instanceof insteadof interface isset list match namespace new or print private protected public readonly require require_once return static switch throw trait try unset use var while xor yield true false null self parent').split(' '));
  const identifier = /[a-zA-Z_\u0080-\uffff][a-zA-Z_0-9\u0080-\uffff]*/y;
  const variable = /\$[a-zA-Z_\u0080-\uffff][a-zA-Z_0-9\u0080-\uffff]*/y;
  const numeric = /(?:0[xX][\da-fA-F_]+|0[bB][01_]+|\d[\d_]*(?:\.[\d_]+)?(?:[eE][+-]?\d+)?)/y;
  const heredoc = /<<<[ \t]*(['"]?)([a-zA-Z_][a-zA-Z_0-9]*)\1[ \t]*\r?\n/y;
  const matchAt = (pattern, source, offset) => { pattern.lastIndex = offset; return pattern.exec(source)?.[0]; };

  function tokenize(source) {
    const tokens = [];
    let offset = 0;
    const emit = (kind, start) => {
      const text = source.slice(start, offset);
      if (tokens.at(-1)?.kind === kind) tokens.at(-1).text += text;
      else tokens.push({kind, text});
    };
    while (offset < source.length) {
      const start = offset;
      let kind = null;
      if (source.startsWith('//', offset) || (source[offset] === '#' && source[offset + 1] !== '[')) {
        kind = 'comment';
        while (offset < source.length && source[offset] !== '\n' && source[offset] !== '\r') offset++;
      } else if (source.startsWith('/*', offset)) {
        kind = 'comment';
        const end = source.indexOf('*/', offset + 2);
        offset = end === -1 ? source.length : end + 2;
      } else if (source.startsWith('<<<', offset) && matchAt(heredoc, source, offset)) {
        // A quoted or unquoted heredoc is a string, including its indented closing label.
        heredoc.lastIndex = offset;
        const opening = heredoc.exec(source);
        const closing = new RegExp('^[ \\t]*' + opening[2] + '(?=[;,\\)\\]\\r\\n]|$)', 'gm');
        closing.lastIndex = offset + opening[0].length;
        const end = closing.exec(source);
        offset = end ? end.index + end[0].length : source.length;
        kind = 'string';
      } else if (["'", '"', '`'].includes(source[offset])) {
        const quote = source[offset++];
        kind = 'string';
        while (offset < source.length) {
          if (source[offset] === '\\') { offset = Math.min(source.length, offset + 2); continue; }
          if (source[offset++] === quote) break;
        }
      } else {
        const name = matchAt(variable, source, offset);
        const word = name ? null : matchAt(identifier, source, offset);
        const number = name || word ? null : matchAt(numeric, source, offset);
        if (name) { kind = 'var'; offset += name.length; }
        else if (word) { kind = keywords.has(word.toLowerCase()) ? 'keyword' : null; offset += word.length; }
        else if (number) { kind = 'number'; offset += number.length; }
        else offset++;
      }
      emit(kind, start);
    }
    return tokens;
  }

  function appendText(target, text, kind) {
    if (!text) return;
    if (!kind) { target.append(document.createTextNode(text)); return; }
    const span = document.createElement('span');
    span.className = `syntax-${kind}`;
    span.textContent = text;
    target.append(span);
  }

  function render(target, source) {
    const fragment = document.createDocumentFragment();
    for (const token of tokenize(source)) appendText(fragment, token.text, token.kind);
    target.replaceChildren(fragment);
    target.classList.add('php-highlight');
  }

  function renderLines(target, source) {
    const fragment = document.createDocumentFragment();
    const newLine = () => { const row = document.createElement('span'); row.className = 'php-source-line'; fragment.append(row); return row; };
    let row = newLine();
    for (const token of tokenize(source)) {
      const parts = token.text.split('\n');
      parts.forEach((part, index) => {
        appendText(row, part, token.kind);
        if (index < parts.length - 1) { appendText(row, '\n', null); row = newLine(); }
      });
    }
    target.replaceChildren(fragment);
    target.classList.add('php-highlight');
  }

  globalThis.PhpSyntax = Object.freeze({tokenize, render, renderLines});
  document.querySelectorAll('[data-php]').forEach((element) => render(element, element.textContent));
})();
