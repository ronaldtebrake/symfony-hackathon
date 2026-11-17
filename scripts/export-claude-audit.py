"""Select public evidence from real interactive Claude sessions; never run inference.

Usage: python3 scripts/export-claude-audit.py [capture-directory]
The private capture directory contains one subdirectory per predeclared arm.
Credentials, terminal recordings, hidden reasoning and user settings stay local.
"""
import hashlib
import json
import re
import sys
from pathlib import Path

root = Path(__file__).resolve().parents[1]
base = Path(sys.argv[1]) if len(sys.argv) > 1 else root / "var/demo/claude-matrix"
order = ["direct-regular", "catalog-native", "catalog-regular", "direct-native"]
question = (base / "question.txt").read_text().strip()
sha = lambda value: hashlib.sha256(value).hexdigest()
attempts = []
for arm in order:
    p = base / arm
    sid = (p / "session-id").read_text().strip()
    transcript = Path.home() / ".claude/projects/-private-tmp-symfony-claude-rehearsal" / f"{sid}.jsonl"
    rows = [json.loads(line) for line in transcript.read_text().splitlines()]
    surface, mode = arm.split("-")
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
                item = {"id": block["id"], "at": row["timestamp"], "turn": len(turns) - 1}
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
    attempts.append({"id": sid, "arm": arm, "surface": surface, "mode": "live", "createdAt": host["startedAt"],
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
        "cleanup": json.loads((p / "cleanup.json").read_text())})

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
text = json.dumps(report, indent=2, ensure_ascii=False) + "\n"
for forbidden in ["Bearer ", "Authorization", '"thinking"', '"signature"', "/Users/", "ronald@getopensocial.com"]:
    assert forbidden not in text, forbidden
(root / "docs/evidence/claude-audit-matrix.json").write_text(text)
print(f"Exported all {len(attempts)} attempts ({len(text):,} characters). Verification runs separately; failures are retained.")
