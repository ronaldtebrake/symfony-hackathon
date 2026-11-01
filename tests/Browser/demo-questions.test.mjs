import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync, existsSync} from 'node:fs';
import {execFileSync} from 'node:child_process';

const source = readFileSync(new URL('../../public/demo-questions.js', import.meta.url));
const {buildDemoCommand, validateQuestion} = await import(`data:text/javascript;base64,${source.toString('base64')}`);

test('custom text remains exactly one literal argument in sh and zsh', () => {
  const question = 'What is "alt"? It\'s $(printf INJECTED) `printf INJECTED`; \\ $HOME\n--backend=drupal <script>alert(1)</script>';
  const command = buildDemoCommand({mode: 'live', scenario: 'custom', question});
  // Replace only the fixed executable prefix. printf receives arguments without running their contents.
  const script = command.replace('composer demo -- ', 'printf "%s\\0" ');
  for (const shell of ['/bin/sh', '/bin/zsh'].filter(existsSync)) {
    const args = execFileSync(shell, ['-c', script], {encoding: 'utf8'}).split('\0').slice(0, -1);
    assert.deepEqual(args, ['--mode=live', '--backend=fixture', `--question=${question}`]);
  }
});

test('changed questions cannot generate a reference command', () => {
  assert.throws(() => buildDemoCommand({mode: 'reference', scenario: 'impact'}));
  assert.throws(() => buildDemoCommand({mode: 'live', scenario: 'custom', question: ' '}));
  assert.match(buildDemoCommand({mode: 'live', scenario: 'impact'}), /--scenario=impact$/);
  assert.equal(buildDemoCommand({mode: 'reference', scenario: 'audit'}), 'composer demo');
});

test('question limits count UTF-8 bytes and reject terminal controls', () => {
  assert.equal(validateQuestion('é'.repeat(1000)), 'é'.repeat(1000));
  for (const value of ['é'.repeat(1001), 'Hello\x1b[31m', 'Hello\u202e', 'Hello\0']) assert.throws(() => validateQuestion(value));
});
