"""Render the silent judge demo from recorded MCP exchanges and model answers.

Editing needs Pillow, ffmpeg, ffprobe and sans/monospace fonts. The film and the
Symfony quickstart do not require them at playback time.
"""
import hashlib
import json
import os
import subprocess
import tempfile
from functools import lru_cache
from math import cos, pi
from pathlib import Path

from PIL import Image, ImageDraw, ImageFont

ROOT = Path(__file__).resolve().parents[1]
MEDIA = ROOT / 'public/media'
PROTOCOL = ROOT / 'docs/evidence/refund-protocol.json'
STUDY = ROOT / 'docs/evidence/refund-skill.json'
AUDIT = ROOT / 'docs/evidence/huggingface-pair.json'
CATALOG = ROOT / 'docs/evidence/http-catalog-proof.json'
SCALING = ROOT / 'docs/evidence/catalog-scaling.json'
CATALOG_FILM = MEDIA / 'symfony-native-tools-demo-90s.mp4'
CATALOG_FILM_PROVENANCE = MEDIA / 'video-proof.json'
SOURCES = {'catalogFilm': CATALOG_FILM, 'catalogFilmProvenance': CATALOG_FILM_PROVENANCE, 'sandboxAudit': AUDIT, 'httpCatalog': CATALOG, 'catalogScaling': SCALING, 'refundProtocol': PROTOCOL, 'refundStudy': STUDY}
W, H, FPS = 1920, 1080, 30
PAPER, WHITE, INK, MUTED = '#faf8fd', '#ffffff', '#24152f', '#63576d'
PURPLE, BERRY, DARK = '#582d9a', '#aa2378', '#251336'
LILAC, LINE, SOFT, MINT = '#d4c0f1', '#ded5e7', '#f0eafa', '#a4eed2'
PINK, DIM = '#ff9acb', '#5b436c'
LEFT = 94
# Timings index the evidence behind the continuous edit. There are no chapter cards.
SEQUENCES = [
    (0, 9, 'Which tools does this job need?', 'First user question from the recorded refund conversation'),
    (9, 20, 'Use the two-tool catalog', 'Screen recording of an agent using the independent Symfony HTTP catalog'),
    (20, 30, 'Measure the opening tool list', 'Offline schema token counts at 10 and 100 synthetic tools'),
    (30, 42, 'Open the refund group', 'Host-selected group, two reader schemas and a verified resource in the linked arm'),
    (42, 54, 'Pass the policy ID to the next read', 'Actual model-selected tools/call arguments and returned facts'),
    (54, 63, 'Read the model’s decision', 'Verbatim first-turn model explanation'),
    (63, 72, 'Revoke permission and ask again', 'Second user question and the known policy reader'),
    (72, 82, 'Receive the denial', 'Zero completed reads and the real follow-up answer'),
    (82, 90, 'Take the revocation case upstream', 'The PHP reference fixture and an open question about client selection'),
]
STATES = [0, 9, 20, 30, 42, 47, 54, 63, 72, 82]


def binding(path):
    return {'file': str(path.relative_to(ROOT)), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest()}


@lru_cache(maxsize=None)
def font(size, style):
    mac = Path('/System/Library/Fonts')
    linux = Path('/usr/share/fonts/truetype/dejavu')
    candidates = {
        'book': [(mac/'HelveticaNeue.ttc', 0), (linux/'DejaVuSans.ttf', 0)],
        'bold': [(mac/'HelveticaNeue.ttc', 1), (linux/'DejaVuSans-Bold.ttf', 0)],
        'mono': [(mac/'Menlo.ttc', 0), (linux/'DejaVuSansMono.ttf', 0)],
    }
    for path, face in candidates[style]:
        if path.exists():
            return ImageFont.truetype(str(path), size=size, index=face)
    raise RuntimeError(f'Install Helvetica Neue/Menlo or DejaVu Sans/Mono to render {style}.')


def put(d, x, y, value, size, color=INK, style='book', width=None):
    face = font(size, style)
    if d.textlength(str(value), font=face) > (width if width is not None else W - 96 - x):
        raise ValueError(f'Line extends outside the film layout: {value}')
    d.text((x, y), str(value), font=face, fill=color, anchor='lt')


def lines(d, x, y, values, size, leading, color=INK, style='book', width=None):
    for i, value in enumerate(values):
        put(d, x, y + i * leading, value, size, color, style, width)


def box(d, xy, fill, border=None, radius=24):
    d.rounded_rectangle(xy, radius, fill=fill, outline=border, width=2)


def rule(d, x1, y, x2, color=LINE):
    d.line((x1, y, x2, y), fill=color, width=2)


@lru_cache(maxsize=1)
def canvas():
    image = Image.new('RGB', (W, H), PAPER)
    d = ImageDraw.Draw(image)
    # A restrained Symfony-violet wash keeps the report visible through every cut.
    for y in range(H):
        t = y / H
        d.line((0, y, W, y), fill=(round(253-7*t), round(252-10*t), round(255-4*t)))
    box(d, (LEFT, 62, LEFT+58, 120), PURPLE, radius=15)
    put(d, LEFT+7, 76, '{ }', 27, WHITE, 'mono')
    put(d, LEFT+78, 76, 'Symfony Code Mode', 37, INK, 'bold')
    d.text((1825, 83), 'Symfony AI  ×  PHP MCP SDK', font=font(29, 'book'), fill=MUTED, anchor='rt')
    rule(d, LEFT, 148, 1825)
    return image


def title(d, first, second, caption):
    put(d, LEFT, 208, first, 79, INK, 'bold')
    put(d, LEFT, 300, second, 79, PURPLE, 'bold')
    put(d, LEFT+2, 429, caption, 38, MUTED)


def wrapped(d, x, y, value, size, leading, width, color=WHITE, style='book', max_lines=4):
    """Break original words to fit the panel without abbreviating a captured answer."""
    rows = []
    line = ''
    for word in value.split():
        candidate = f'{line} {word}'.strip()
        if line and d.textlength(candidate, font=font(size, style)) > width:
            rows.append(line)
            line = word
        else:
            line = candidate
    if line:
        rows.append(line)
    if len(rows) > max_lines:
        raise ValueError(f'Too much text for the panel: {value}')
    lines(d, x, y, rows, size, leading, color, style, width)


def pane(d, x1, x2, label, dark=False, top=550, bottom=930):
    box(d, (x1,top,x2,bottom), DARK if dark else WHITE, None if dark else LINE)
    put(d, x1+37, top+34, label, 28, LILAC if dark else PURPLE, 'mono', x2-x1-74)
    rule(d, x1+37, top+92, x2-37, DIM if dark else LINE)


def reference(d, text):
    put(d, LEFT, 975, text, 29, MUTED)


def opening(d, info):
    title(d, 'Which Symfony tools does', 'this job need?',
          'We built a searchable MCP catalog and a way to open related tools together.')
    pane(d, LEFT, 1825, 'USER   /   RECORDED REFUND REVIEW', True)
    wrapped(d, 134, 689, '“'+info['question']+'”', 52, 68, 1630, WHITE, max_lines=3)


def catalog(d, info):
    lines(d, LEFT, 225, ['Two tools', 'open the', 'catalog.'], 74, 82, INK, 'bold', 473)
    for y, name, explanation in ((535, info['catalog_search'], 'Find an approved reader.'),
                                 (698, info['catalog_execute'], 'Read with current access.')):
        put(d, LEFT, y, name, 42, PURPLE, 'mono', 466)
        wrapped(d, LEFT, y+62, explanation, 35, 45, 440, MUTED, max_lines=2)
    box(d, (574, 175, 1826, 962), WHITE, LINE)
    put(d, 609, 203, 'RECORDED CATALOG SESSION   /   ARTICLE LOOKUP', 28, PURPLE, 'mono')
    reference(d, 'We began with PHP in gVisor. The PHP MCP SDK now serves installed tools over Symfony HTTP.')



def scaling(d, info):
    title(d, 'Ten tools or a hundred?', 'The opening catalog stays small.',
          'We measured the initial MCP tool list at two catalog sizes.')
    pane(d, LEFT, 1825, 'INITIAL SCHEMA TOKENS   /   OFFLINE MEASUREMENT', True)
    x10, x100 = 1240, 1640
    for x, sample in zip((x10, x100), info['samples']):
        d.text((x, 682), f"{sample['availableTools']} tools", font=font(37, 'bold'), fill=LILAC, anchor='rt')
    for i, (key, caption) in enumerate((('direct', 'Advertise every tool'), ('catalog', 'Advertise search + execute'))):
        y = 759 + i*83
        put(d, 134, y, caption, 42, WHITE, 'bold')
        for x, sample in zip((x10, x100), info['samples']):
            d.text((x, y), f"{sample[key]['tokens']:,}", font=font(46, 'mono'), fill=MINT if i else WHITE, anchor='rt')
    reference(d, 'Synthetic tool schemas. Retrieving a match and completing the conversation cost additional tokens.')


def group(d, info):
    title(d, 'The app knows which readers', 'belong together for a refund.',
          'Our PHP prototype implements tool discovery from the MCP working group’s Groups draft.')
    pane(d, LEFT, 925, 'HOST   /   groups/get')
    put(d, 132, 689, info['group_name'], 53, PURPLE, 'mono', 747)
    wrapped(d, 132, 792, 'The host opens this group for the support rep.', 38, 52, 745, MUTED)
    pane(d, 966, 1825, 'TOOLS IN THE GROUP', True)
    for i, tool in enumerate(info['tool_names']):
        put(d, 1005, 689+i*67, tool, 42, WHITE, 'mono', 748)
    put(d, 1005, 846, 'Linked guide   SKILL.md', 34, LILAC, 'mono', 747)
    reference(d, 'In this recorded run the host verified the guide before giving the tools to the model.')


def facts(d, has_policy, info):
    title(d, 'The model reads the order,', 'then uses its policy ID.',
          'Two calls chosen by the model in the recorded Symfony AI + Hugging Face conversation.')
    pane(d, LEFT, 922, info['tool_names'][0])
    put(d, 132, 680, info['order_args'], 39, PURPLE, 'mono', 747)
    rule(d, 132, 752, 877)
    put(d, 132, 790, f"{info['amount']}  ·  {info['condition']}  ·  day {info['age']}", 46, INK, 'bold', 747)
    put(d, 132, 861, 'policyId    '+info['policy'], 34, PURPLE, 'mono', 747)
    pane(d, 967, 1825, info['tool_names'][1], True)
    if has_policy:
        put(d, 1005, 680, info['policy_args'], 39, MINT, 'mono', 765)
        rule(d, 1005, 752, 1785, DIM)
        put(d, 1005, 790, f"{info['window']}-day return window", 46, WHITE, 'bold', 770)
        put(d, 1005, 861, 'Manual review above '+info['threshold'], 36, LILAC, width=775)
    else:
        wrapped(d, 1005, 715, 'The order supplies the ID for the next MCP call.', 46, 64, 744)


def answer(d, first, info):
    if first:
        title(d, 'The order is within the window.', 'Its amount needs a human review.',
              'The agent answers the support rep with the disposition and its reason.')
    else:
        title(d, 'Symfony denies the known reader.', 'The agent reports the missing evidence.',
              'Permissions changed after discovery. The call stopped before the policy handler ran.')
    pane(d, LEFT, 718, 'AGENT DISPOSITION' if first else 'MCP TOOL RESULT')
    put(d, 130, 707, info['disposition'] if first else info['denied_reason'], 49, PURPLE, 'mono', 550)
    put(d, 130, 808, 'refundIssued  false' if first else f"{info['completed']} completed reads", 36, MUTED, 'mono', 552)
    pane(d, 767, 1825, 'AGENT   /   RECORDED ANSWER', True)
    wrapped(d, 806, 692, '“'+(info['answer'] if first else info['follow_answer'])+'”', 44, 57, 974)
    if not first:
        reference(d, 'Follow-up disposition   ·   '+info['follow_disposition'])


def revoke(d, info):
    title(d, 'Revoke the rep’s permission.', 'Ask for the same policy again.',
          'The support rep continues the same conversation. The operator changes only the grant.')
    pane(d, LEFT, 1825, 'USER   /   RECORDED FOLLOW-UP', True)
    wrapped(d, 132, 676, '“'+info['follow_question']+'”', 45, 60, 1620, WHITE, max_lines=3)
    put(d, 132, 864, info['tool_names'][1]+'   '+info['policy_args'], 34, MINT, 'mono')


def closing(d):
    title(d, 'What can we contribute?', 'A permission-change test for grouped tools.',
          'The PHP fixture lets SDK and working-group maintainers replay both reads and the denial.')
    pane(d, LEFT, 1825, 'NEXT EXPERIMENT', True)
    wrapped(d, 134, 687, 'Can the agent choose the right group when several could fit the job?',
            57, 77, 1550, WHITE, 'bold', 2)
    reference(d, 'Watch, inspect the calls and reproduce them   ·   ronaldtebrake.github.io/symfony-hackathon')


def render_state(t, info):
    image = canvas().copy()
    d = ImageDraw.Draw(image)
    if t < 9:
        opening(d, info)
    elif t < 20:
        catalog(d, info)
    elif t < 30:
        scaling(d, info)
    elif t < 42:
        group(d, info)
    elif t < 54:
        facts(d, t >= 47, info)
    elif t < 63:
        answer(d, True, info)
    elif t < 72:
        revoke(d, info)
    elif t < 82:
        answer(d, False, info)
    else:
        closing(d)
    return image


def encode(data, directory):
    playlist = ['ffconcat version 1.0']
    poster = directory/'editorial-review-poster.jpg'
    frame_total = 0

    def add(image, frames):
        nonlocal frame_total
        name = f's{len(playlist):04d}.png'
        image.save(directory/name, compress_level=1)
        playlist.extend([f"file '{name}'", f'option framerate {FPS}', f'duration {frames/FPS:.8f}'])
        frame_total += frames

    previous = None
    for i, start in enumerate(STATES):
        end = STATES[i+1] if i+1 < len(STATES) else 90
        base = render_state(start, data)
        if not i:
            base.save(poster, quality=94)
        offset = 0
        if previous is not None:
            # Only changed elements dissolve; shared page chrome and report stay still.
            for step in range(8):
                mix = (1 - cos(pi*(step+1)/8))/2
                add(Image.blend(previous, base, mix), 2)
            offset = 16
        add(base, (end-start)*FPS-offset)
        previous = base
    if frame_total != 90*FPS:
        raise ValueError('The edit must total exactly 90 seconds.')
    playlist.extend([playlist[-3], f'option framerate {FPS}'])
    listing = directory/'frames.txt'
    listing.write_text('\n'.join(playlist)+'\n')
    film = directory/'editorial-review-90s.mp4'
    # Reuse the original screen recording as moving footage, cropping out the previous edit’s captions.
    overlay = (f'[0:v]fps={FPS}[slides];'
               '[1:v]crop=1260:760:558:195,scale=1184:714,setpts=PTS-STARTPTS+9/TB[agent];'
               "[slides][agent]overlay=x=608:y=244:enable='gte(t,9)*lt(t,20)':eof_action=pass[out]")
    subprocess.run(['ffmpeg', '-v', 'error', '-y', '-f', 'concat', '-safe', '0', '-i', str(listing),
                    '-ss', '37', '-t', '11', '-i', str(CATALOG_FILM), '-filter_complex', overlay, '-map', '[out]',
                    '-an', '-c:v', 'libx264', '-preset', 'fast', '-crf', '19', '-pix_fmt', 'yuv420p',
                    '-frames:v', str(frame_total), '-movflags', '+faststart', str(film)], check=True)
    streams = json.loads(subprocess.check_output(['ffprobe', '-v', 'error', '-show_streams', '-of', 'json', str(film)]))['streams']
    if len(streams) != 1 or streams[0]['codec_type'] != 'video' or (int(streams[0]['nb_frames']), float(streams[0]['duration'])) != (frame_total, 90.0):
        raise ValueError('Encoded film must contain 2,700 silent video frames.')
    return film, poster


def result(call):
    return call['response']['result']


def facts_returned(call):
    return result(call)['structuredContent']


def euros(minor):
    return f'€{minor // 100}' if minor % 100 == 0 else f'€{minor / 100:.2f}'


def read_captures():
    subprocess.run(['php', 'scripts/check-refund-protocol.php', '--verify'], cwd=ROOT, check=True)
    subprocess.run(['php', 'scripts/compare-refund-skill.php', '--verify'], cwd=ROOT, check=True)
    clip = json.loads(CATALOG_FILM_PROVENANCE.read_bytes())
    if clip['assets'][CATALOG_FILM.name] != binding(CATALOG_FILM)['sha256']:
        raise ValueError('Catalog screen recording differs from its verified master.')
    study = json.loads(STUDY.read_bytes())
    protocol = json.loads(PROTOCOL.read_bytes())['calls']
    linked = next(trial for trial in study['attempts'] if trial['arm'] == 'skill')
    if linked['source'] != 'live' or linked['status'] != 'passed' or len(linked['turns']) != 2:
        raise ValueError('The film requires a verified two-turn linked-skill conversation.')
    host = {c['request']['method']: result(c) for c in linked['hostCalls']}
    group = host['groups/list']['groups'][0]
    opened = host['groups/get']
    manifest = host['skills/get']['skill']['resources'][0]
    markdown = host['resources/read']['contents'][0]['text']
    encoded = markdown.encode()
    if (manifest['uri'] != opened['resources'][0]['uri'] or manifest['digest'] != 'sha256:' + hashlib.sha256(encoded).hexdigest() or len(encoded) != manifest['size']
            or manifest != result(protocol['manifest'])['skill']['resources'][0]):
        raise ValueError('The linked skill and the independently captured manifest differ.')
    review, refresh = linked['turns']
    order_call, policy_call = review['calls']
    [denied_call] = refresh['calls']
    order, policy, denial = (facts_returned(c) for c in [order_call, policy_call, denied_call])
    if (facts_returned(protocol['order']) != order or facts_returned(protocol['policy']) != policy
            or order['currency'] != 'EUR' or policy['currency'] != 'EUR'
            or result(protocol['hidden_group'])['groups'] or 'error' not in protocol['denied_guide']['response']):
        raise ValueError('The security check and the study must support the same case.')
    first = json.loads(review['answer'])
    second = json.loads(refresh['answer'])
    audit = json.loads(AUDIT.read_bytes())
    direct, php = audit['direct'], audit['code']
    if (any(not item['verification']['passed'] for item in (direct, php))
            or any(direct[key] != php[key] for key in ('task', 'model', 'datasetSha256', 'sharedPromptSha256'))
            or direct['result']['report'] != php['result']['report'] or php['runtime'] != 'runsc'):
        raise ValueError('Audit figures require verified matching reports and a real gVisor run.')
    catalog = json.loads(CATALOG.read_bytes())
    if (not all(catalog['checks'].values()) or catalog['execution']['mode'] != 'in-process'
            or 'child-process functions disabled' not in catalog['scope']):
        raise ValueError('Catalog scene requires the independent plain PHP HTTP proof.')
    calls = {c['label']: c for c in catalog['calls']}
    search, execute = (calls[key]['request']['params'] for key in ('discovery', 'authorized'))
    scaling = json.loads(SCALING.read_bytes())
    if (scaling['httpCaptureSha256'] != binding(CATALOG)['sha256']
            or scaling['measurement']['kind'] != 'offline-schema-tokenization' or scaling['measurement']['modelRequests'] != 0):
        raise ValueError('Scaling evidence must match the recorded HTTP catalog.')
    sizes = {s['availableTools']: s for s in scaling['samples']}
    samples = [sizes[10], sizes[100]]
    if any(s['catalog']['advertisedTools'] != 2 for s in samples) or samples[0]['catalog']['sha256'] != samples[1]['catalog']['sha256']:
        raise ValueError('The opening catalog must advertise the same two definitions at both sizes.')
    return {
        'catalog_search': search['name'], 'catalog_execute': execute['name'], 'samples': samples,
        'question': review['question'], 'follow_question': refresh['question'], 'group_name': group['name'],
        'tool_names': [order_call['request']['params']['name'], policy_call['request']['params']['name']],
        'order_args': json.dumps(order_call['request']['params']['arguments']),
        'policy_args': json.dumps(policy_call['request']['params']['arguments']), 'amount': euros(order['amountMinor']),
        'threshold': euros(policy['manualReviewAboveMinor']), 'age': order['daysSinceDelivery'],
        'condition': order['itemCondition'], 'policy': policy['policyId'], 'window': policy['returnWindowDays'],
        'disposition': first['disposition'], 'follow_disposition': second['disposition'],
        'answer': first['explanation'], 'follow_answer': second['explanation'],
        'denied_reason': denial['error']['reason'], 'completed': denial['completedCalls'],
    }


def build():
    sources = {name: binding(path) for name, path in SOURCES.items()}
    data = read_captures()
    media_names = ['editorial-review-90s.mp4', 'editorial-review-poster.jpg']
    # Stage the large render in a private system directory. Verify every media input before replacing the published files.
    with tempfile.TemporaryDirectory() as path:
        directory = Path(path)
        film, poster = encode(data, directory)
        if sources != {name: binding(path) for name, path in SOURCES.items()}:
            raise ValueError('An input changed during the render. Re-run the film against the final evidence.')
        manifest = {
            'format': 'symfony-editorial-review-film/v11', 'durationSeconds': 90, 'fps': FPS, 'frames': 90*FPS,
            'resolution': [W, H], 'audio': 'none',
            'scope': 'Original agent screen footage of an article lookup with the two-tool catalog, followed by a typeset replay of a separate recorded Symfony AI and Hugging Face refund conversation. Its host opened one group and verified its guide. The model selected the reads and answered both user turns. The two-tool catalog and its initial schema measurements are a separate independent HTTP fixture and offline synthetic scaling experiment. The earlier gVisor implementation is a design reference.',
            **sources,
            'screenRecordingEdit': {'master': CATALOG_FILM.name, 'sourceSeconds': [37, 48], 'filmSeconds': [9, 20], 'crop': [558, 195, 1260, 760], 'speed': 1},
            'designReferences': ['https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19',
                                 'https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/22'],
            'segments': [dict(start=s, end=e, title=t, source=p) for s,e,t,p in SEQUENCES],
            'assets': {file.name: hashlib.sha256(file.read_bytes()).hexdigest() for file in (film, poster)},
        }
        manifest_name = 'editorial-review-film.json'
        (directory/manifest_name).write_text(json.dumps(manifest, ensure_ascii=False, indent=2)+'\n')
        for name in media_names + [manifest_name]:
            # Public media may be on a different filesystem to the renderer's scratch volume.
            with tempfile.NamedTemporaryFile(dir=MEDIA, delete=False) as staged:
                staged.write((directory/name).read_bytes())
                staged_path = Path(staged.name)
            staged_path.chmod(0o644)
            os.replace(staged_path, MEDIA/name)


if __name__ == '__main__':
    build()
