"""Render the silent judge film using Pillow, ffmpeg and three retained captures.

Run from any directory with Python 3.10+. This is an editorial tool. Rendering
makes no model requests and requires no access to the original recording machine.
"""
from collections import Counter
from functools import lru_cache
from hashlib import sha256
from pathlib import Path
import json
import re
import subprocess
import tempfile

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
MEDIA = ROOT / "public/media"
PREFIX = "server-code-mode-"
WIDTH, HEIGHT, FPS = 1920, 1080, 30
BG, CARD, INK, MUTED = "#fbf9fd", "#ffffff", "#21162b", "#5f526d"
PLUM, VIOLET, BORDER = "#36144a", "#7513a1", "#dfd5e7"
NIGHT, WHITE, LILAC, PINK, MINT = "#201229", "#fffafd", "#d7baf3", "#ff94ce", "#adedd0"
TRANSCRIPT = ROOT / "docs/evidence/codex-conversation-2026-11-01.json"
PAIR = ROOT / "docs/evidence/huggingface-pair.json"
SECURITY = ROOT / "docs/evidence/security-proof-2026-11-01.json"
CHAPTERS = [
    (0, 10, "Ask a question across tools", "Overview of server-side Code Mode and the synthetic audit question"),
    (10, 25, "Submit a PHP program", "First agent turn, verbatim excerpts from submitted PHP"),
    (25, 39, "Follow the broker calls", "First execution, grouped host-recorded tool calls"),
    (39, 48, "Read the findings", "First PHP return value, selected fields and records"),
    (48, 63, "Change the question", "Second turn, new submitted PHP and returned image ranking"),
    (63, 75, "Compare the measured audit", "Separate matched Symfony AI and Hugging Face pair"),
    (75, 85, "Test the execution boundary", "Three separate fixed PHP probes through gVisor"),
    (85, 90, "Run it yourself", "Workbench replay and configured runner command"),
]
STAGES = [[0, 5], [0, 7], [0, 3, 6], [0], [0, 5, 9], [0, 4], [0, 3, 6], [0]]


def digest(path):
    return sha256(path.read_bytes()).hexdigest()


def evidence():
    transcript = json.loads(TRANSCRIPT.read_text())
    pair = json.loads(PAIR.read_text())
    probes = json.loads(SECURITY.read_text())
    verified = transcript["verification"]
    if not all(verified[key] for key in ["differentPrograms", "auditMatchesIndependentOracle", "impactMatchesIndependentOracle"]):
        raise ValueError("Both agent programs must be independently verified.")
    execution = [next(call for call in turn["items"] if call.get("tool") == "php_execute") for turn in transcript["turns"]]
    for call in execution:
        ledger = call["result"]["structuredContent"]["toolCalls"]
        if len(ledger) != 10 or any(tool["status"] != "succeeded" for tool in ledger):
            raise ValueError("The edit expects ten successful read calls per execution.")
    for key in ["direct", "code"]:
        if not pair[key]["verification"]["passed"] or pair[key]["model"] != pair["code"]["model"] or pair[key]["task"] != pair["code"]["task"]:
            raise ValueError("Comparison requires a matched, verified model and task.")
    if (pair["code"]["runtime"] != "runsc" or probes["runtime"] != "runsc"
            or [p["id"] for p in probes["cases"]] != ["authorized_read", "unauthorized_call", "infinite_loop"]
            or not all(p["passed"] and p["containerRemoved"] for p in probes["cases"])):
        raise ValueError("Expected all three controls and verified sandbox cleanup.")
    return execution, pair, probes["cases"]


@lru_cache(maxsize=None)
def font(size, family="sans"):
    paths = {
        "sans": [("/System/Library/Fonts/HelveticaNeue.ttc", 0), ("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf", 0)],
        "bold": [("/System/Library/Fonts/HelveticaNeue.ttc", 1), ("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf", 0)],
        "mono": [("/System/Library/Fonts/Menlo.ttc", 0), ("/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf", 0)],
    }
    for path, index in paths[family]:
        if Path(path).exists():
            return ImageFont.truetype(path, size, index)
    raise RuntimeError("Install DejaVu Sans and DejaVu Sans Mono to render.")


def write(draw, x, y, value, size, color=INK, family="sans", maximum=None):
    value = str(value)
    face = font(size, family)
    if draw.textlength(value, font=face) > (WIDTH - x - 70 if maximum is None else maximum):
        raise ValueError(f"Line too wide for edit: {value}")
    draw.text((x, y), value, fill=color, font=face, anchor="lt")


def card(d, x, y, right, bottom, fill=CARD, edge=BORDER):
    d.rounded_rectangle((x, y, right, bottom), radius=25, fill=fill, outline=edge, width=2)


def tag(d, x, y, label, dark=False):
    write(d, x, y, label, 28, LILAC if dark else VIOLET, "mono")


@lru_cache(maxsize=2)
def background(dark):
    image = Image.new("RGB", (WIDTH, HEIGHT), BG)
    if dark:
        d = ImageDraw.Draw(image)
        for x in range(WIDTH):
            r = x / WIDTH
            d.line((x, 0, x, HEIGHT), fill=(int(36+37*r), int(15+3*r), int(48+42*r)))
    return image


def canvas(index):
    dark = index in {0, 7}
    image = background(dark).copy()
    d = ImageDraw.Draw(image)
    card(d, 90, 56, 162, 128, VIOLET, VIOLET)
    write(d, 99, 76, "{ }", 31, WHITE, "mono")
    write(d, 185, 75, "Symfony Code Mode", 39, WHITE if dark else INK, "bold")
    top = ["Symfony AI   ×   PHP MCP SDK", "Recorded MCP conversation", "Recorded MCP conversation",
           "Recorded MCP conversation", "Recorded MCP conversation", "Symfony AI   ×   Hugging Face",
           "gVisor  /  fixed control probes", "Symfony AI   ×   PHP MCP SDK"][index]
    d.text((1820, 81), top, font=font(28, "mono"), anchor="rt", fill=LILAC if dark else MUTED)
    d.line((96, 159, 1822, 159), fill="#694479" if dark else BORDER, width=2)
    for i, (start, end, _, _) in enumerate(CHAPTERS):
        a = 96 + start * 19
        d.rounded_rectangle((a, 1008, a+(end-start)*19-5, 1014), 2,
                            fill=(PINK if dark else VIOLET) if i == index else ("#6b4b7a" if dark else BORDER))
    return image, d


def heading(d, title, subtitle):
    write(d, 97, 218, title, 77, INK, "bold")
    write(d, 102, 336, subtitle, 42, MUTED)


def code(d, lines, x, y, size, spacing):
    pattern = re.compile(r'("[^"]*"|\$[A-Za-z_]\w*|\b(?:foreach|as|if|return|continue|null|count|while)\b)')
    for number, line in lines:
        write(d, x, y+2, f"{number:2}", size-2, "#aa93b5", "mono")
        xx = x + 92
        for part in pattern.split(line):
            if not part:
                continue
            tone = MINT if part.startswith('"') else (LILAC if part.startswith("$") else (PINK if pattern.fullmatch(part) else WHITE))
            write(d, xx, y, part, size, tone, "mono")
            xx += d.textlength(part, font=font(size, "mono"))
        y += spacing


def snippet(d, submitted, first, last, y=449, bottom=916, size=33):
    src = submitted.splitlines()[first-1:last]
    card(d, 99, y, 1819, bottom, NIGHT, "#5e3b73")
    tag(d, 137, y+36, "php_execute  /  submitted PHP excerpt", True)
    d.line((138, y+97, 1776, y+97), fill="#614c6c", width=2)
    # The frame crops common indentation as a normal editor viewport would.
    indent = min(len(s)-len(s.lstrip()) for s in src if s.strip())
    top = y + 135
    spacing = min(size+22, (bottom-top-size-24) // max(1, len(src)-1))
    if spacing < size+4:
        raise ValueError("The excerpt needs a larger viewport.")
    code(d, [(first+i, row[indent:]) for i, row in enumerate(src)], 138, top, size, spacing)


def opening(d, stage):
    tag(d, 104, 228, "SERVER-SIDE CODE MODE", True)
    write(d, 97, 314, "Let an agent write PHP", 101, WHITE, "bold")
    write(d, 97, 433, "for the question at hand.", 101, PINK, "bold")
    write(d, 103, 606, "Run it in isolation. Give it approved tools to read from Symfony.", 45, WHITE)
    card(d, 101, 739, 1816, 932, "#351948", "#86559b")
    tag(d, 139, 777, "THE EDITOR’S QUESTION", True)
    if stage:
        write(d, 141, 843, "Which articles lack a description or hero-image alt text?", 53, WHITE, "bold")
    else:
        write(d, 141, 843, "The answer lives across article and image records.", 50, WHITE, "bold")


def program(d, stage, execution):
    if stage == 0:
        heading(d, "The agent sends a PHP program.", "It discovers three readers, then submits one program over MCP.")
        snippet(d, execution["arguments"]["code"], 40, 46, size=33)
    else:
        heading(d, "PHP joins the records in gVisor.", "The program finds the linked image and checks its alt text.")
        snippet(d, execution["arguments"]["code"], 65, 67, bottom=830, size=32)


def trace(d, stage, result):
    heading(d, "The host checks each call.", "These are the reads the broker recorded during the first program.")
    ledger = result["toolCalls"]
    grouped = Counter(c["name"] for c in ledger)
    for i, (tool, count) in enumerate(grouped.items()):
        x = 101 + 581*i
        card(d, x, 469, x+552, 836, NIGHT if stage >= i else CARD, "#69447b" if stage >= i else BORDER)
        tone = WHITE if stage >= i else MUTED
        write(d, x+33, 524, tool, 35, tone, "mono", 486)
        if stage >= i:
            write(d, x+32, 627, str(count), 105, WHITE, "bold")
            write(d, x+121, 684, "authorized reads", 34, LILAC, maximum=396)
            for n in range(count):
                d.ellipse((x+35+n*29, 776, x+48+n*29, 789), fill=MINT)
    if stage == 2:
        write(d, 106, 892, f"1 PHP execution     /     {len(ledger)} authorized calls", 38, VIOLET, "mono")


def result_page(d, result):
    heading(d, "The agent receives the findings.", "The rows PHP used along the way stay out of the conversation.")
    card(d, 101, 464, 846, 909, PLUM, PLUM)
    write(d, 143, 518, str(result["total_audited"]), 98, WHITE, "bold")
    write(d, 333, 558, "articles checked", 40, LILAC)
    write(d, 143, 720, str(result["affected_count"]), 98, PINK, "bold")
    write(d, 333, 759, "need attention", 40, LILAC)
    card(d, 880, 464, 1819, 909)
    tag(d, 922, 510, "RETURNED RECORDS  /  EXCERPTS")
    for i, item in enumerate(result["affected_articles"][:3]):
        y = 612 + i*83
        write(d, 923, y, f"#{item['id']}", 37, INK, "bold")
        write(d, 1047, y+4, item["issues"][0], 36, MUTED, "mono", 728)


def follow(d, stage, execution, result):
    heading(d, "Now change the question.", "Which shared image fixes help the most articles?")
    if stage < 2:
        start, end = (73, 78) if stage == 0 else (82, 88)
        snippet(d, execution["arguments"]["code"], start, end, size=32)
        write(d, 106, 943, "Second program in the same conversation", 32, VIOLET, "mono")
    else:
        for i, item in enumerate(result["hero_image_alt_fixes_ranked_by_impact"]):
            x = 101 + 579*i
            card(d, x, 474, x+550, 808, PLUM, PLUM)
            tag(d, x+34, 520, f"IMAGE {item['image_id']}", True)
            write(d, x+32, 620, " + ".join(f"#{n}" for n in item["affected_article_ids"]), 59, WHITE, "bold", 484)
            write(d, x+35, 726, f"{item['affected_article_count']} articles helped", 33, LILAC)
        write(d, 105, 886, "Three fixes tie. The second PHP program recalculated the impact.", 42, MUTED)


def comparison(d, stage, pair):
    heading(d, "What did the model process?", "A separate matched Symfony AI audit found the same ten articles on both paths.")
    a, b = (pair[k]["result"] for k in ("direct", "code"))
    counts = [a["providerTokens"]["total"], b["providerTokens"]["total"]]
    for i, (result, title, value) in enumerate(zip([a,b], ["Direct tool calls", "Sandboxed PHP"], counts)):
        y = 480+i*177
        write(d, 104, y, title, 41, INK, "bold")
        write(d, 109, y+69, f"{result['modelRequests']} model requests", 30, MUTED, "mono")
        card(d, 548, y+12, 1818, y+83, "#ece5f1", "#ece5f1")
        right = 548+int(1269*value/counts[0])
        card(d, 548, y+12, right, y+83, "#826099" if i == 0 else VIOLET, "#826099" if i == 0 else VIOLET)
        write(d, 573, y+28, f"{value:,} tokens", 38, WHITE, "bold")
    if stage:
        diff = 100*(1-counts[1]/counts[0])
        write(d, 105, 873, f"{diff:.1f}% fewer provider tokens in this pair.", 46, VIOLET, "bold")


def controls(d, stage, probes):
    heading(d, "What if the program oversteps?", "Three fixed programs exercised the same sandbox and broker.")
    for i, (probe, title) in enumerate(zip(probes, ["Read the list", "Delete an article", "Loop forever"])):
        y = 457+i*130
        card(d, 103, y, 1817, y+104)
        write(d, 139, y+33, title, 39, INK, "bold")
        arg = probe["toolCalls"][0]["name"] if i < 2 else probe["code"].splitlines()[-1]
        write(d, 604, y+37, arg, 32, MUTED, "mono")
        if i <= stage:
            decision = probe["reason"] or f"{probe['value']} published"
            write(d, 1370, y+32, decision, 38, VIOLET, "bold")
    if stage == 2:
        write(d, 106, 893, f"{probes[2]['wallTimeMs']//1000}-second wall limit. All three containers removed.", 42, MUTED)


def closing(d):
    tag(d, 104, 242, "EXPLORE THE EXECUTION", True)
    write(d, 98, 335, "Open the workbench.", 98, WHITE, "bold")
    write(d, 98, 470, "Inspect both programs and every call.", 64, PINK, "bold")
    card(d, 102, 675, 1817, 905, "#351948", "#86559b")
    tag(d, 142, 722, "REPLAY LOCALLY  /  CONFIGURED GVISOR RUNNER", True)
    write(d, 140, 804, "composer demo:conversation", 52, WHITE, "mono")


def frame(chapter, stage, data):
    executions, pair, probes = data
    first, second = [c["result"]["structuredContent"] for c in executions]
    image, d = canvas(chapter)
    if chapter == 0:
        opening(d, stage)
    elif chapter == 1:
        program(d, stage, executions[0])
    elif chapter == 2:
        trace(d, stage, first)
    elif chapter == 3:
        result_page(d, first["value"])
    elif chapter == 4:
        follow(d, stage, executions[1], second["value"])
    elif chapter == 5:
        comparison(d, stage, pair)
    elif chapter == 6:
        controls(d, stage, probes)
    else:
        closing(d)
    return image


def build():
    data = evidence()
    with tempfile.TemporaryDirectory() as folder:
        directory = Path(folder)
        playlist = ["ffconcat version 1.0"]

        def add(image, frames):
            name = f"stage-{len(playlist):04}.png"
            image.save(directory / name, compress_level=1)
            playlist.extend([f"file '{name}'", f"option framerate {FPS}", f"duration {frames/FPS:.8f}"])

        for i, (start, end, _, _) in enumerate(CHAPTERS):
            previous = None
            for stage, at in enumerate(STAGES[i]):
                image = frame(i, stage, data)
                finish = (STAGES[i] + [end-start])[stage+1]
                hold = (finish-at)*FPS
                if previous is not None:
                    for opacity in (0.2, 0.5, 0.8):
                        add(Image.blend(previous, image, opacity), 2)
                    hold -= 6
                add(image, hold)
                if i == 0 and stage == 1:
                    image.save(directory / "poster.jpg", quality=94)
                previous = image
        playlist.extend([playlist[-3], f"option framerate {FPS}"])
        frames = directory / "frames.txt"
        frames.write_text("\n".join(playlist)+"\n")
        output = directory / "90s.mp4"
        subprocess.run(["ffmpeg", "-v", "error", "-y", "-f", "concat", "-safe", "0", "-i", str(frames),
                        "-vf", f"fps={FPS}", "-an", "-c:v", "libx264", "-preset", "fast", "-crf", "19",
                        "-pix_fmt", "yuv420p", "-frames:v", str(90*FPS), "-movflags", "+faststart", str(output)], check=True)
        streams = json.loads(subprocess.check_output(["ffprobe", "-v", "error", "-show_streams", "-of", "json", str(output)]))["streams"]
        if len(streams) != 1 or streams[0]["codec_name"] != "h264" or (int(streams[0]["nb_frames"]), float(streams[0]["duration"])) != (2700, 90):
            raise ValueError("Expected one silent 90-second H.264 video.")
        film, poster = [MEDIA / (PREFIX + suffix) for suffix in ("90s.mp4", "poster.jpg")]
        film.write_bytes(output.read_bytes())
        poster.write_bytes((directory / "poster.jpg").read_bytes())
    manifest = {
        "format": "symfony-server-code-mode-film/v1", "durationSeconds": 90, "fps": FPS, "frames": 2700,
        "resolution": [WIDTH, HEIGHT], "audio": "none",
        "scope": "Edited replay of two real server-side PHP executions from one agent conversation. Text and selected results are typeset from the retained transcript. The separately labeled provider comparison and fixed sandbox probes are independent captures. No new live run or screen footage is implied.",
        "evidence": [{"file": str(p.relative_to(ROOT)), "sha256": digest(p)} for p in (TRANSCRIPT, PAIR, SECURITY)],
        "chapters": [dict(start=s, end=e, title=t, source=p) for s,e,t,p in CHAPTERS],
        "assets": {p.name: digest(p) for p in (film, poster)},
    }
    (MEDIA / (PREFIX + "film.json")).write_text(json.dumps(manifest, ensure_ascii=False, indent=2)+"\n")


if __name__ == "__main__":
    build()
