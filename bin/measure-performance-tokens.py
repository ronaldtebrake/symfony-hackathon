#!/usr/bin/env python3
"""Offline gpt-oss payload accounting. No inference or generated-code execution."""
import hashlib
import importlib.metadata
import json
from pathlib import Path
import subprocess
import sys

import tiktoken

root = Path(__file__).resolve().parent.parent
path = Path(sys.argv[1]).resolve() if len(sys.argv) > 1 else root / "var/demo/performance-conversation.json"
if importlib.metadata.version("tiktoken") != "0.12.0":
    sys.exit("Use tiktoken==0.12.0 to reproduce these counts.")
report = json.loads(path.read_text())
if report.get("model") != "openai/gpt-oss-120b":
    sys.exit("This measurement is defined only for the recorded gpt-oss model.")
encoding = tiktoken.get_encoding("o200k_harmony")
for index, attempt in [(str(i), a) for i, a in enumerate(report.get("previousAttempts", []))] + [(None, report)]:
    args = ["php", "bin/performance-token-inputs", str(path)]
    if index is not None:
        args.append(index)
    samples = json.loads(subprocess.check_output(args, cwd=root))
    for sample in samples:
        payload = sample.pop("text")
        assert hashlib.sha256(payload.encode()).hexdigest() == sample["sha256"]
        sample["tokens"] = len(encoding.encode_ordinary(payload))
    attempt["tokenBreakdown"] = {
        "encoding": "o200k_harmony",
        "library": "tiktoken==0.12.0",
        "scope": "One copy per captured payload: canonical entry-tool JSON, exact tool-result strings and raw generated PHP. Excludes prompts, final answers, reasoning, provider/chat framing and repeated context. These counts do not sum to provider usage and do not establish savings against direct tools.",
        "samples": samples,
    }
path.write_text(json.dumps(report, ensure_ascii=False, indent=4) + "\n")
print("Recorded", len(samples), "hash-bound payload counts. Provider usage and attempts unchanged.")
