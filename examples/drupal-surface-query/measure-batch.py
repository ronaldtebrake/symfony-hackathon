#!/usr/bin/env python3
"""Offline payload counts only. Optional analysis dependency, never part of execution."""
import hashlib
import importlib.metadata
import json
from pathlib import Path
import sys

import tiktoken

if len(sys.argv) != 2 or importlib.metadata.version('tiktoken') != '0.12.0':
    sys.exit('Usage: python measure-batch.py REPORT.json (requires tiktoken==0.12.0)')
source = Path(sys.argv[1]).read_bytes()
report = json.loads(source)
if report.get('status') != 'complete' or report.get('mode') != 'paired' or report.get('modelCalled') is not False:
    sys.exit('Use a complete paired reference capture; this is not provider usage.')
encoding = tiktoken.get_encoding('o200k_harmony')


def canonical(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':'))


samples = {}
for mode in ('batch', 'php'):
    run = report['runs'][mode]['composition']
    discovery = report['discovery'][mode]
    parts = {
        'toolDefinitions': discovery['tools'],
        'searchRequest': {'query': 'search'},
        'searchResponse': discovery['search'],
        'describeRequest': {'names': ['drupal.search']},
        'describeResponse': discovery['describe'],
        'executionRequest': run['request'],
        'executionResponse': run['response'],
    }
    samples[mode] = {}
    for name, value in parts.items():
        text = canonical(value)
        digest = hashlib.sha256(text.encode()).hexdigest()
        if name.startswith('execution'):
            key = 'request' if name.endswith('Request') else 'response'
            if digest != run[key + 'Sha256']:
                sys.exit('Canonical payload does not match the captured hash.')
        samples[mode][name] = {'tokens': len(encoding.encode_ordinary(text)), 'bytes': len(text.encode()), 'sha256': digest}
    samples[mode]['sumOfSingleCopies'] = sum(part['tokens'] for part in samples[mode].values())

print(json.dumps({
    'kind': 'offline-data-surface-payload-counts', 'library': 'tiktoken==0.12.0', 'encoding': 'o200k_harmony',
    'sourceSha256': hashlib.sha256(source).hexdigest(),
    'scope': 'One canonical copy of tool definitions, search/describe requests and responses, and the composition request/response. No inference. Excludes system/user prompts, repeated conversation history, chat/protocol framing, final answer generation, failures and security probes. Not billed tokens, request counts, cost savings or model correctness. Batch returns separate lists; PHP returns deduplicated output.',
    'samples': samples,
}, indent=2))
