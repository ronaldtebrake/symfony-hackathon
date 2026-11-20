"""Select public evidence from real interactive Claude sessions; never run inference.

Usage: python3 scripts/export-claude-audit.py capture-directory
The private capture directory contains one subdirectory per predeclared arm.
An iteration.json manifest declares its ID, label, order and changes before inference.
Credentials, terminal recordings, hidden reasoning and user settings stay local.
"""
import argparse
import hashlib
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument("directory", type=Path)
parser.add_argument("--field-selection", action="store_true", help="Export a separate native Claude pair")
parser.add_argument("--output", type=Path, help="A new file for a field selection pair")
args = parser.parse_args()
base = args.directory
manifest = json.loads((base / "iteration.json").read_text())
order = manifest["order"]
question = (base / "question.txt").read_text().strip()
sha = lambda value: hashlib.sha256(value).hexdigest()


def capture(arm, surface, mode):
    p = base / arm
    sid = (p / "session-id").read_text().strip()
    transcript = Path.home() / ".claude/projects/-private-tmp-symfony-claude-rehearsal" / f"{sid}.jsonl"
    rows = [json.loads(line) for line in transcript.read_text().splitlines()]
    turns, code, calls, usage, results = [], [], [], [], {}
    for row in rows:
        message = row.get("message", {})
        content = message.get("content", [])
        if row.get("type") == "user" and isinstance(content, str) and not content.startswith(("<", "/")):
            turns.append({"question": content, "askedAt": row["timestamp"], "answer": "", "commentary": []})
        if not isinstance(content, list):
            continue
        for block in content:
            if block.get("type") == "tool_result":
                results[block["tool_use_id"]] = {"result": block.get("content", ""), "isError": block.get("is_error", False)}
            if row.get("type") != "assistant":
                continue
            if block.get("type") == "text" and turns:
                if message.get("stop_reason") == "end_turn":
                    turns[-1]["answer"] += block["text"]
                    turns[-1]["answeredAt"] = row["timestamp"]
                else:
                    turns[-1]["commentary"].append(block["text"])
            if block.get("type") == "tool_use":
                item = {"id": block["id"], "messageId": message["id"], "at": row["timestamp"], "turn": len(turns) - 1}
                if block["name"] == "REPL":
                    source = block["input"]["code"]
                    code.append(item | {"code": source, "sha256": sha(source.encode()), "model": message["model"]})
                else:
                    calls.append(item | {"codeId": code[-1]["id"] if mode == "native" and code else None,
                        "name": block["name"], "arguments": block["input"], "origin": message.get("model")})
        if row.get("type") == "assistant" and message.get("model") not in [None, "<synthetic>"]:
            u = message.get("usage", {})
            usage.append({"messageId": message["id"], "requestId": row.get("requestId"), "at": row["timestamp"],
                "turn": len(turns) - 1, "model": message["model"], "effort": row.get("effort"), "sidechain": row.get("isSidechain"),
                "usage": {k: u.get(k) for k in ["input_tokens", "cache_creation_input_tokens", "cache_read_input_tokens", "output_tokens"]},
                "cacheCreation": u.get("cache_creation")})
    for item in code + calls:
        item.update(results.get(item["id"], {"result": "", "isError": True}))
    for turn in turns:
        turn["answerSha256"] = sha(turn["answer"].encode())
    host = json.loads((p / "host.json").read_text())
    terminal = re.sub(r"\x1b\[[0-?]*[ -/]*[@-~]", "", (p / "terminal.raw").read_text(errors="replace"))
    # Terminal cursor-forward sequences can replace visible spaces.
    banner = "CodeMode(experimental)ison" in re.sub(r"\s+", "", terminal)
    return {"id": sid, "arm": arm, "surface": surface, "mode": "live", "createdAt": host["startedAt"],
        "client": {"name": "Claude Code", "version": "2.1.326", "model": "claude-sonnet-5-5", "effort": "medium",
            "nativeCodeMode": mode == "native", "interactive": True, "nativeBannerObserved": banner,
            "permissionMode": "auto", "builtInTools": ["REPL", "ToolSearch"], "strictMcpConfig": True,
            "enableToolSearch": False, "alwaysLoad": True},
        "runtime": {"serverExecution": "in-process", "serverGeneratedCode": False},
        "capture": {"transport": "MCP HTTP", "transcriptSha256": sha(transcript.read_bytes()),
            "terminalSha256": sha((p / "terminal.raw").read_bytes()), "launcherSha256": sha((p / "launch.py").read_bytes())},
        "sourceSha256": host["sourceSha256"], "datasetSha256": host["datasetSha256"],
        "limits": {"executionSubmissions": 20, "underlyingCalls": 20, "perBatchCalls": 20, "wallTimeMs": 15000, "toolTimeMs": 3000},
        "identity": {"subject": "matrix-reader", "permissions": ["content.audit"], "publishedArticleIds": [1, 48], "publicMediaIds": [1, 24]},
        "turns": turns, "code": code, "agentCalls": calls, "usageRows": usage,
        "http": [json.loads(line) for line in (p / "http.jsonl").read_text().splitlines()],
        "cleanup": json.loads((p / "cleanup.json").read_text())}

def encode(report):
    text = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
    for forbidden in ["Bearer ", "Authorization", '"thinking"', '"signature"', "/Users/", "ronald@getopensocial.com"]:
        if forbidden in text:
            raise ValueError("Private data in export.")
    return text


if args.field_selection:
    reference = json.loads((root / "docs/evidence/claude-audit-matrix.json").read_text())["protocol"]
    if order != ["baseline", "offered"] or manifest["surfaces"] != ["catalog", "catalog-fields"] or question != manifest["question"] or question != reference["question"]:
        raise ValueError("Unexpected field selection comparison protocol.")
    report = {"format": "claude-field-selection/v1", "protocol": manifest,
        "attempts": [capture(arm, surface, "native") for arm, surface in zip(order, manifest["surfaces"])]}
    content = encode(report)
    with (args.output or root / "docs/evidence/claude-field-selection.json").open("x") as target:
        target.write(content)
    print("Exported both native Claude attempts for independent verification.")
    raise SystemExit(0)

if sorted(order) != sorted(["direct-regular", "catalog-native", "catalog-regular", "direct-native"]):
    raise ValueError("Expected one complete four-way iteration.")
if args.output:
    parser.error("--output requires --field-selection")
attempts = [capture(arm, *arm.split("-")) for arm in order]
report = {"format": "claude-audit-matrix/v1", "protocol": {"question": question, "order": order, "attemptsPlanned": 4,
    "model": "claude-sonnet-5-5", "effort": "medium", "clientVersion": "2.1.326",
    "scope": "Four fresh interactive Claude Code sessions. Same 48-article/24-image fixture, question, model, effort, approved readers, permissions, validators and budgets. Cross full MCP advertisement versus Symfony's two-tool JSON catalog with Claude native Code Mode off versus on. Three real application tools, no padding. No expected findings, corrective hints or answer repairs supplied to the agent.",
    "toolExposure": "ENABLE_TOOL_SEARCH=false and alwaysLoad=true for every arm. Direct exposes all three approved reader schemas. Catalog exposes search_tools and execute_tools; its search returns the same reader schemas. Claude native Code Mode still has its own runtime tool discovery and JavaScript overhead, retained as part of that treatment.",
    "security": "Both HTTP surfaces dispatch through the same BatchSession, ToolBroker and ApprovedToolbox. Identical bearer authentication, permission checks, argument schemas, 20-submission and 20-underlying-call budgets. This audit leaves permissions fixed; revocation and budget exhaustion are separate integration checks, not extra model prompts. Existing recording-app limits remain unchanged.",
    "usageMethod": "Main-conversation provider counters from the actual Claude session, deduplicated by API message ID using the final counters. Total = uncached input + cache-write input + cache-read input + output. Synthetic runtime events do not add model requests. Unreported background/classifier usage excluded. Not a bill: cache categories have different prices.",
    "cacheControl": "Runs used the same signed-in account sequentially in the declared order. Cache was neither flushed nor normalized; all four categories remain visible. One attempt per cell is an observation, not a reliable estimate of average cost or reliability.",
    "timingMethod": "User prompt to final answer, including client hooks, tool execution and network. Not a controlled latency benchmark.",
    "captureMethod": "Exact JSON-RPC request/response bodies and bounded Symfony traces from each disposable HTTP app. Public answers, generated JavaScript, nested tool events and provider usage from the same real interactive transcript. No credentials, headers, hidden reasoning or terminal recordings exported.",
    "correctness": "Offline fixture oracle plus HTTP data coverage, exact client-call binding, handler traces, persistent budget accounting and source hashes. No model-based answer grading."}, "attempts": attempts}
destination = root / "docs/evidence/claude-audit-matrix.json"
if destination.exists():
    existing = json.loads(destination.read_text())
    assert existing["protocol"]["question"] == question, "The question must remain identical."
    assert not set(a["id"] for a in existing["attempts"]) & set(a["id"] for a in attempts), "Already exported; never overwrite attempts."
    if "iterations" not in existing:
        existing["iterations"] = [{"id": "original", "label": "Original contracts", "changes": "Original four-way capture, including the incomplete catalog-only audit.",
            "order": existing["protocol"]["order"], "attemptIds": [a["id"] for a in existing["attempts"]]}]
    assert manifest["id"] not in [i["id"] for i in existing["iterations"]], "Use a unique iteration ID."
    existing["iterations"].append(manifest | {"attemptIds": [a["id"] for a in attempts]})
    existing["attempts"].extend(attempts)
    report = existing
else:
    report["iterations"] = [manifest | {"attemptIds": [a["id"] for a in attempts]}]
report["format"] = "claude-audit-matrix/v2"
text = encode(report)
destination.write_text(text)
print(f"Appended {len(attempts)} attempts; retained {len(report['attempts'])} total ({len(text):,} characters). Verification runs separately; failures are retained.")
