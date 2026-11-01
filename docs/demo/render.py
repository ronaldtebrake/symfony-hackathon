#!/usr/bin/env python3
"""Render the local 90-second evidence walkthrough. No network or model calls.

Requires Python 3, FFmpeg with libass, and macOS's installed Daniel voice.
Screenshots are captured through the browser before this script is run.
"""

from concurrent.futures import ThreadPoolExecutor
from hashlib import sha256
import json
from pathlib import Path
import re
import subprocess
import textwrap

ROOT = Path(__file__).resolve().parents[2]
WORK = ROOT / 'var/demo-video/build'
OUT = ROOT / 'docs/demo/rendered'
FONT = '/System/Library/Fonts/Supplemental/Arial.ttf'
BOLD = '/System/Library/Fonts/Supplemental/Arial Bold.ttf'
MONO = '/System/Library/Fonts/Menlo.ttc'
PAPER, INK, MUTED, VIOLET, PINK = '0xf8f7ff', '0x191226', '0x686174', '0x4c1d95', '0xdb2777'
CAPTURE = ROOT / 'docs/evidence/codex-conversation-2026-11-01.json'
SECURITY = ROOT / 'docs/evidence/security-proof-2026-11-01.json'

# Wording is specific to the rendered edit: its opening is a transcript excerpt,
# not screen footage of a newly executed Codex desktop conversation.
NARRATION = [
    (0, 10, 'Symfony Code Mode lets agents compose approved tools in PHP. In this recorded Codex session, the first request audits published articles.'),
    (10, 20, 'The follow-up asks which shared-image fixes help the most articles. Codex computes a new repair plan within the same permissions.'),
    (20, 35, 'This saved rehearsal shows both turns. The follow-up rechecked forty-eight articles and found three shared-image fixes, each helping two articles. Here is the exact PHP it submitted.'),
    (35, 48, 'The host recorded ten upstream calls: four list pages, four article batches and two media batches. Expand PHP execute for the actual request and response.'),
    (48, 60, 'A separate Symfony AI reference comparison returns the same findings with ten calls each and eighty-five percent less tool-result payload. That measures bytes, not model tokens.'),
    (60, 82, 'These fixed probes ran real PHP in gVisor. An approved read succeeded. A delete call was denied. An infinite loop hit the two-second watchdog. Each container was confirmed removed. The guest has no network or host credentials. These checks are evidence, not a security audit.'),
    (82, 90, 'A reusable PHP package. Conversations stay in Codex. The host controls execution.'),
]


def run(args):
    subprocess.run([str(a) for a in args], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.PIPE)


def probe(path):
    return json.loads(subprocess.check_output(['ffprobe', '-v', 'error', '-show_format', '-show_streams', '-of', 'json', str(path)]))


def textfile(name, value):
    path = WORK / f'{name}.txt'
    path.write_text(value)
    return path


def draw(name, value, x, y, size=32, color=INK, font=FONT):
    path = textfile(name, value)
    return f"drawtext=fontfile='{font}':textfile='{path}':expansion=none:x={x}:y={y}:fontsize={size}:fontcolor={color}:line_spacing=13"


def box(x, y, w, h, color):
    return f'drawbox=x={x}:y={y}:w={w}:h={h}:color={color}:t=fill'


def common(name, title, chapter, provenance):
    return [
        box(0, 0, 1920, 7, VIOLET),
        draw(name+'-logo', '{ }', 62, 31, 31, PINK, BOLD),
        draw(name+'-brand', 'Symfony Code Mode', 125, 32, 28, INK, BOLD),
        draw(name+'-chapter', chapter, 1510, 37, 21, MUTED, MONO),
        draw(name+'-title', title, 64, 88, 48, VIOLET, BOLD),
        draw(name+'-provenance', provenance, 65, 900, 22, MUTED),
        box(0, 954, 1920, 126, '0x171027'),
    ]


def render_frame(name, filters, image=None, crop=None, target=None):
    args = ['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-f', 'lavfi', '-i', f'color=c={PAPER}:s=1920x1080:r=30']
    graph = ''
    start = '[0:v]'
    if image:
        args += ['-i', ROOT / image]
        x, y, w, h = crop
        tx, ty, tw, th = target
        graph = f'[1:v]crop={w}:{h}:{x}:{y},scale={tw}:{th}:flags=lanczos[picture];[0:v][picture]overlay={tx}:{ty}[base];'
        start = '[base]'
    graph += start + ','.join(filters) + '[frame]'
    path = WORK / f'{name}.png'
    run(args + ['-filter_complex', graph, '-map', '[frame]', '-frames:v', '1', '-update', '1', path])
    return path


def encode_clip(item):
    name, duration, frame = item
    output = WORK / f'{name}.mp4'
    run(['ffmpeg', '-hide_banner', '-loglevel', 'error', '-y', '-loop', '1', '-framerate', '30', '-i', frame,
         '-t', duration, '-an', '-c:v', 'libx264', '-threads', '3', '-preset', 'fast', '-crf', '18', '-pix_fmt', 'yuv420p', output])
    return output


def timestamp(ms):
    h, rem = divmod(ms, 3600000)
    m, rem = divmod(rem, 60000)
    s, milli = divmod(rem, 1000)
    return f'{h:02}:{m:02}:{s:02},{milli:03}'


def main():
    WORK.mkdir(parents=True, exist_ok=True)
    OUT.mkdir(parents=True, exist_ok=True)
    capture = json.loads(CAPTURE.read_text())
    security = json.loads(SECURITY.read_text())
    assert security['status'] == 'complete' and security['runtime'] == 'runsc'
    assert len(security['cases']) == 3 and all(c['passed'] and c['containerRemoved'] for c in security['cases'])
    loop = next(c for c in security['cases'] if c['id'] == 'infinite_loop')
    assert loop['reason'] == 'wall_timeout' and loop['wallTimeMs'] == 2000
    assert loop['toolCalls'][0]['name'] == 'cms_list_articles' and loop['toolCalls'][0]['status'] == 'succeeded'
    assert capture['backend'] == 'Symfony fixture' and len(capture['turns']) == 2
    for turn in capture['turns']:
        executions = [c for c in turn['items'] if c['type'] == 'mcpToolCall' and c['tool'] == 'php_execute']
        assert len(executions) == 1 and executions[0]['status'] == 'completed'
        assert len(executions[0]['result']['structuredContent']['toolCalls']) == 10

    clips = []
    sources = [CAPTURE, SECURITY, ROOT/'packages/php-code-mode/docs/verification.md']
    for i in (0, 1):
        name = f'0{i+1}-codex'
        turn = capture['turns'][i]
        calls = [c for c in turn['items'] if c['type'] == 'mcpToolCall']
        execution = next(c for c in calls if c['tool'] == 'php_execute')
        value = execution['result']['structuredContent']['value']
        prompt = turn['prompt'].split('. ')[0] + '.' if i == 0 else '. '.join(turn['prompt'].split('. ')[:2]) + '.'
        filters = common(name, 'Start with a question.' if i == 0 else 'Reshape the task. Keep the permissions.', f'0{i+1} / 07  CODEX', 'Codex app-server capture · 1 Nov 2026 · Transcript excerpts, not Codex UI footage')
        filters += [
            box(64, 178, 1792, 366, 'white'), box(64, 178, 6, 366, PINK),
            draw(name+'-human', 'HUMAN  /  '+('ORIGINAL QUESTION' if i == 0 else 'FOLLOW-UP'), 100, 212, 23, MUTED, MONO),
            draw(name+'-prompt', textwrap.fill(prompt, 66), 100, 268, 43, INK),
            draw(name+'-calls-label', 'ACTUAL MCP CALLS · COMPLETED', 65, 587, 22, MUTED, MONO),
        ]
        for n, call in enumerate(calls):
            x = 65 + n*610
            filters += [box(x, 630, 575, 72, '0xeee7ff'), draw(name+f'-call-{n}', call['tool'], x+25, 649, 30, VIOLET, MONO)]
        result = f"{value['total_audited']} articles audited  /  {value['affected_count']} affected" if i == 0 else f"{value['total_audited']} articles rechecked  /  {len(value['hero_image_alt_fixes'])} shared-image fixes"
        filters += [draw(name+'-result', result, 65, 756, 48, VIOLET, BOLD), draw(name+'-model', 'gpt-6-astra · Symfony fixture · real PHP execution in gVisor', 65, 831, 25, MUTED)]
        clips.append((name, 10, render_frame(name, filters)))

    shots = [
        ('03-audit', 4, 'Two turns, one recorded conversation.', '03 / 07  REHEARSAL', 'docs/demo/assets/audit.jpg', (46, 0, 1158, 680), (347, 164, 1226, 720), 'Recorded Codex rehearsal · 1 Nov 2026 · Read-only workbench'),
        ('04-followup', 5, 'The follow-up changes the computation.', '03 / 07  FOLLOW-UP', 'docs/images/codex-proof.jpg', (46, 174, 1158, 538), (192, 164, 1536, 714), '48 articles rechecked · Three shared-image fixes · No content changed'),
        ('05-php', 6, 'The exact PHP submitted to gVisor.', '03 / 07  PHP', 'docs/demo/assets/followup-php.jpg', (46, 60, 1158, 551), (207, 164, 1506, 716), 'Actual php_execute arguments · Image repairs sorted by published article count'),
        ('06-calls', 8, 'Ten calls. Recorded by the host.', '04 / 07  CALLS', 'docs/images/codex-tool-calls.jpg', (433, 190, 769, 510), (417, 164, 1086, 720), 'Four list pages + four article batches + two media batches · All succeeded'),
        ('07-mcp', 5, 'Inspect the actual MCP request.', '04 / 07  MCP', 'docs/demo/assets/mcp-request.jpg', (433, 60, 769, 510), (417, 164, 1086, 720), 'Recorded php_execute request and response · Source is shown as captured'),
        ('08-comparison', 12, 'Same findings. Less data returned to the agent.', '05 / 07  PAYLOAD', 'docs/images/code-mode-proof.jpg', (46, 228, 1158, 344), (64, 240, 1792, 532), 'Separate reference execution · 31 Oct 2026 · No model called · Bytes, not tokens'),
        ('09-security', 8, 'Authorization and limits, visibly enforced.', '06 / 07  SECURITY', 'docs/images/security-proof.jpg', (46, 305, 1158, 371), (64, 235, 1792, 574), 'Fixed PHP probes · 1 Nov 2026 · No model called · 2-second proof budget'),
    ]
    for name, duration, title, chapter, source, crop, target, provenance in shots:
        filters = common(name, title, chapter, provenance)
        clips.append((name, duration, render_frame(name, filters, source, crop, target)))
        sources.append(ROOT/source)

    name = '10-loop'
    filters = common(name, 'The loop stopped. The container is gone.', '06 / 07  TRACE', 'Actual host trace · Successful read witness · Exact container absence checked')
    filters += [
        draw(name+'-label', 'OBSERVED BY THE HOST', 80, 222, 24, MUTED, MONO),
        draw(name+'-read', 'Read completed.', 80, 301, 62, VIOLET, BOLD),
        draw(name+'-read-detail', 'cms_list_articles · succeeded', 80, 385, 30, MUTED, MONO),
        draw(name+'-timeout', 'Loop terminated.', 80, 477, 62, VIOLET, BOLD),
        draw(name+'-timeout-detail', 'wall_timeout', 80, 562, 32, MUTED, MONO),
        draw(name+'-removed', 'Cleanup checked.', 80, 659, 62, VIOLET, BOLD),
        draw(name+'-removed-detail', 'containerRemoved: true', 80, 744, 32, MUTED, MONO),
    ]
    clips.append((name, 8, render_frame(name, filters, 'docs/demo/assets/loop-trace.jpg', (833, 146, 367, 425), (1130, 164, 622, 720))))
    sources.append(ROOT/'docs/demo/assets/loop-trace.jpg')

    name = '11-boundary'
    filters = common(name, 'The host owns the boundary.', '06 / 07  LIMITS', 'Specific controls verified locally · This development proof is not a security audit')
    controls = [('gVisor required', 'Native PHP inside an application-kernel sandbox.'), ('No guest network', 'No host mounts, credentials or Docker socket.'), ('Bounded execution', 'Time, memory and tool-call limits enforced by the host.'), ('Per-call authorization', 'Generated code cannot grant itself permissions.')]
    for i, (title, detail) in enumerate(controls):
        x, y = 64+(i%2)*912, 200+(i//2)*310
        filters += [box(x,y,880,270,'white'), box(x,y,6,270,VIOLET), draw(name+f'-h{i}',title,x+36,y+48,44,VIOLET,BOLD),draw(name+f'-d{i}',textwrap.fill(detail,43),x+36,y+130,29,MUTED)]
    clips.append((name, 6, render_frame(name, filters)))

    name = '12-package'
    filters = common(name, 'PHP does the data work.', '07 / 07  REUSE', 'Recorded evidence walkthrough · Synthetic narration · Repository package, not yet on Packagist')
    filters += [
        draw(name+'-headline', 'The agent keeps\nthe conversation.', 80, 230, 93, VIOLET, BOLD),
        draw(name+'-package', 'ronaldtebrake/php-code-mode', 83, 510, 41, INK, MONO),
        draw(name+'-stack', 'Symfony AI  +  official PHP MCP SDK  +  gVisor', 83, 600, 33, MUTED),
        box(80, 708, 1760, 104, '0xeee7ff'),
        draw(name+'-repo', 'github.com/ronaldtebrake/symfony-hackathon', 112, 741, 38, VIOLET, MONO),
    ]
    clips.append((name, 8, render_frame(name, filters)))
    assert sum(c[1] for c in clips) == 90
    print('Rendered 12 evidence frames.', flush=True)

    # Generate local speech per narration block; fit only when necessary, then
    # pad to the planned interval. Captions follow the rendered speech interval.
    audio_paths, cues, ass_cues, audio_manifest = [], [], [], []
    for i, (start, end, text) in enumerate(NARRATION):
        source = textfile(f'voice-{i}', text)
        aiff = WORK/f'voice-{i}.aiff'
        # Pronunciation hint affects only speech; captions retain the proper name.
        speech_source = textfile(f'speech-{i}', text.replace('gVisor', 'gee visor').replace('Symfony', 'Simfony'))
        run(['say', '-v', 'Daniel', '-r', '145', '-f', speech_source, '-o', aiff])
        raw = float(probe(aiff)['format']['duration'])
        slot = end-start
        available = slot-0.7
        rate = max(1.0, raw/available)
        assert rate < 1.3, 'Narration needs rewriting rather than excessive acceleration.'
        spoken = raw/rate
        wav = WORK/f'voice-{i}.wav'
        samples=slot*48000
        run(['ffmpeg','-hide_banner','-loglevel','error','-y','-i',aiff,'-af',f'atempo={rate},aresample=48000,asetpts=N/SR/TB,adelay=300:all=1,apad=whole_len={samples},atrim=end_sample={samples},asetpts=N/SR/TB','-ar','48000','-ac','2',wav])
        assert abs(float(probe(wav)['format']['duration'])-slot)<0.001
        audio_paths.append(wav)
        audio_manifest.append({'start':start,'end':end,'sourceDuration':raw,'tempo':rate})
        chunks = []
        for sentence in re.split(r'(?<=[.!?])\s+', text):
            current = []
            for word in sentence.split():
                if len(' '.join(current+[word])) > 78:
                    chunks.append(' '.join(current)); current=[]
                current.append(word)
            if current: chunks.append(' '.join(current))
        count = sum(len(c.split()) for c in chunks)
        done = 0
        for chunk in chunks:
            first = start+0.3+spoken*done/count
            done += len(chunk.split())
            last = start+0.3+spoken*done/count
            lines=textwrap.wrap(chunk,width=42,break_long_words=False,break_on_hyphens=False)
            assert len(lines)<=2
            cues.append(f'{len(cues)+1}\n{timestamp(round(first*1000))} --> {timestamp(round(last*1000))}\n'+'\n'.join(lines))
            ass_time=lambda seconds: timestamp(round(seconds*1000))[1:-1].replace(',', '.')
            ass_cues.append(f'Dialogue: 0,{ass_time(first)},{ass_time(last)},Default,,0,0,0,,'+'\\N'.join(lines))
    captions = OUT/'symfony-code-mode-90s.srt'
    captions.write_text('\n\n'.join(cues)+'\n')
    ass=WORK/'captions.ass'
    ass.write_text('[Script Info]\nScriptType: v4.00+\nPlayResX: 1920\nPlayResY: 1080\nWrapStyle: 2\n\n[V4+ Styles]\nFormat: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\nStyle: Default,Arial,36,&H00FFFFFF,&H00FFFFFF,&H00271017,&H00271017,0,0,0,0,100,100,0,0,1,1,0,2,90,90,31,1\n\n[Events]\nFormat: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n'+'\n'.join(ass_cues)+'\n')
    (OUT/'narration.txt').write_text('\n\n'.join(t for _,_,t in NARRATION)+'\n')
    audio_list = WORK/'audio.ffconcat'
    audio_list.write_text('ffconcat version 1.0\n'+''.join(f"file '{p}'\n" for p in audio_paths))
    narration = WORK/'narration.wav'
    run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','concat','-safe','0','-i',audio_list,'-af','loudnorm=I=-16:TP=-1.5:LRA=11','-ar','48000',narration])
    assert abs(float(probe(narration)['format']['duration'])-90)<0.001
    print('Synthesized and fitted local narration.', flush=True)

    with ThreadPoolExecutor(max_workers=3) as pool:
        encoded = list(pool.map(encode_clip, clips))
    video_list = WORK/'video.ffconcat'
    video_list.write_text('ffconcat version 1.0\n'+''.join(f"file '{p}'\n" for p in encoded))
    output = OUT/'symfony-code-mode-90s.mp4'
    filters=f"ass='{ass}',drawbox=x=0:y=1074:w=1920:h=6:color=0x7f5bff:t=fill,fade=t=out:st=89.6:d=0.4"
    run(['ffmpeg','-hide_banner','-loglevel','error','-y','-f','concat','-safe','0','-i',video_list,'-i',narration,'-vf',filters,'-map','0:v','-map','1:a','-c:v','libx264','-threads','4','-preset','fast','-crf','18','-pix_fmt','yuv420p','-c:a','aac','-b:a','192k','-t','90','-movflags','+faststart',output])
    info=probe(output)
    assert abs(float(info['format']['duration'])-90)<0.05
    video=next(s for s in info['streams'] if s['codec_type']=='video')
    assert video['width']==1920 and video['height']==1080 and video['r_frame_rate']=='30/1'
    audio=next(s for s in info['streams'] if s['codec_type']=='audio')
    assert abs(float(audio['duration'])-90)<0.05
    manifest={'durationSeconds':90,'resolution':[1920,1080],'fps':30,'voice':'macOS Daniel, synthetic local narration','opening':'Edited excerpts of the real Codex capture; not Codex UI footage. Automated app access was blocked.','screens':'Actual workbench screenshots, cropped and scaled for legibility; no outcomes retouched.','newExecution':False,'audio':audio_manifest,'sources':{str(p.relative_to(ROOT)):sha256(p.read_bytes()).hexdigest() for p in sorted(set(sources))},'outputSha256':sha256(output.read_bytes()).hexdigest()}
    (OUT/'provenance.json').write_text(json.dumps(manifest,indent=2)+'\n')
    run(['ffmpeg','-hide_banner','-loglevel','error','-y','-ss','29','-i',output,'-frames:v','1','-update','1',OUT/'poster.jpg'])
    print(f'Wrote {output.relative_to(ROOT)} ({output.stat().st_size/1e6:.1f} MB), exactly 90 seconds.', flush=True)


if __name__ == '__main__':
    try:
        main()
    except subprocess.CalledProcessError as error:
        print(error.stderr.decode(errors='replace') if error.stderr else str(error))
        raise
