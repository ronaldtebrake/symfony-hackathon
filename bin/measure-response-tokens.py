#!/usr/bin/env python3
"""Optional offline measurement tool. The PHP runtime has no Python dependency."""
import hashlib
import importlib.metadata
import json
from pathlib import Path
import subprocess
import sys

import tiktoken

root = Path(__file__).resolve().parent.parent
if importlib.metadata.version("tiktoken") != "0.12.0":
    sys.exit("Use tiktoken==0.12.0 to reproduce this measurement.")
samples = json.loads(subprocess.check_output(["php", "bin/response-payloads"], cwd=root))
encoding = tiktoken.get_encoding("o200k_harmony")
for sample in samples:
    for side in ("before", "after"):
        payload = sample.pop(side)
        assert hashlib.sha256(payload.encode()).hexdigest() == sample[side + "Sha256"]
        sample[side + "Tokens"] = len(encoding.encode_ordinary(payload))
report = {
    "measurement": {
        "kind": "offline-response-payload-replay",
        "model": "openai/gpt-oss-120b",
        "encoding": "o200k_harmony",
        "library": "tiktoken==0.12.0",
        "reference": "https://github.com/openai/gpt-oss/blob/main/gpt_oss/tokenizer.py",
        "scope": "One copy of each serialized response body. Retained Symfony AI strings are exact; Codex structured results use canonical PHP JSON. Excludes discovery, prompts, PHP source, protocol envelopes, repeated context, chat formatting and completions. No inference call; not provider-billed savings or a new correctness benchmark.",
    },
    "samples": samples,
}
print(json.dumps(report, indent=2))
