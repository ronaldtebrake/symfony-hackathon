#!/usr/bin/env python3
"""Measure the recorded discovery results offline. This is an optional editing tool."""
import hashlib
import importlib.metadata
import json
import sys
from pathlib import Path

import tiktoken


def discovery_payloads(report, run):
    for call in run["calls"]:
        request = call["request"]
        method = request["method"]
        search = method == "tools/call" and request["params"]["name"] == "search_tools"
        if not search and method not in ("tools/list", "groups/list", "groups/get"):
            continue
        result = json.loads(report["responses"][call["responseSha256"]])["result"]
        # A host can use the structured result directly. Do not count the parallel
        # text rendering of that same result as a second copy presented to a model.
        payload = result["structuredContent"] if search else result
        yield "search_tools" if search else method, json.dumps(payload, separators=(",", ":"), ensure_ascii=False)


def measure(report, encoding):
    counts = {}
    for path in ("catalog", "catalogBrowse", "groups"):
        runs = report["paths"][path]
        payloads = list(discovery_payloads(report, runs["control"]))
        if not payloads or payloads != list(discovery_payloads(report, runs["revoked"])):
            raise ValueError(f"Discovery differs between the two {path} runs.")
        rows = []
        for method, payload in payloads:
            raw = payload.encode()
            rows.append({"method": method, "sha256": hashlib.sha256(raw).hexdigest(),
                         "bytes": len(raw), "tokens": len(encoding.encode_ordinary(payload))})
        counts[path] = {"responses": rows, "tokens": sum(row["tokens"] for row in rows)}
    return counts


if __name__ == "__main__":
    if importlib.metadata.version("tiktoken") != "0.12.0":
        sys.exit("Use tiktoken==0.12.0 to reproduce this measurement.")
    root = Path(__file__).resolve().parents[1]
    capture = Path(sys.argv[1]) if len(sys.argv) == 2 else root / "docs/evidence/editorial-groups.json"
    raw = capture.read_bytes()
    print(json.dumps({
        "format": "symfony-editorial-discovery-footprint/v1",
        "captureSha256": hashlib.sha256(raw).hexdigest(),
        "scope": "One minified JSON result per discovery response, in captured field order. Search uses "
                 "structuredContent once. Excludes negotiation, JSON-RPC framing, execution, prompts and model outputs. "
                 "An offline host-payload comparison; no live provider usage or cost was measured.",
        "encoding": "o200k_harmony", "tokenizer": "tiktoken==0.12.0",
        "paths": measure(json.loads(raw), tiktoken.get_encoding("o200k_harmony")),
    }, indent=2))
