#!/usr/bin/env python3
"""Optional offline measurement; the Symfony application does not require Python."""
import hashlib
import importlib.metadata
import json
from pathlib import Path
import subprocess
import sys

import tiktoken

if importlib.metadata.version("tiktoken") != "0.12.0":
    sys.exit("Use tiktoken==0.12.0 to reproduce this measurement.")
root = Path(__file__).resolve().parent.parent
report = json.loads(subprocess.check_output(["php", "bin/catalog-http-footprint"], cwd=root))
encoding = tiktoken.get_encoding("o200k_harmony")
for sample in report["samples"]:
    for kind in ("direct", "catalog", "selectedContract"):
        payload = sample[kind].pop("text")
        assert hashlib.sha256(payload.encode()).hexdigest() == sample[kind]["sha256"]
        sample[kind]["tokens"] = len(encoding.encode_ordinary(payload))
report["measurement"] = {"kind": "offline-schema-tokenization", "encoding": "o200k_harmony", "library": "tiktoken==0.12.0", "modelRequests": 0,
    "note": "Tokenizer-specific counts, not provider-reported input tokens. Actual client conversion, framing, caching and billing vary."}
print(json.dumps(report, indent=2))
