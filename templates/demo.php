<?php

declare(strict_types=1);

/** @var array $session */
/** @var array $programmatic */
/** @var array $supportAttempt */
/** @var array $plainChat */
/** @var array $structured */
/** @var array $groupTrace */
/** @var array $largestCatalog */
$escape = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Publish team procedures and Symfony AI tools over MCP. Watch Claude open a Group and pass a named result field from one tool to the next.">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'">
  <title>Symfony Code Mode · Team procedures over MCP</title>
  <link rel="stylesheet" href="demo.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="#overview"><span aria-hidden="true">{ }</span>Symfony Code Mode</a>
  <nav aria-label="Documentation"><a href="#judge-demo">Demo</a><a href="#how-it-works">How it works</a><a href="#journey">Design journey</a><a href="#quickstart">Try it locally</a></nav>
</header>
<main>
  <section id="overview" class="hero">
    <div>
      <p class="eyebrow">Symfony AI × PHP MCP SDK</p>
      <h1>Give agents a procedure <em>they can follow.</em></h1>
      <p>A growing catalog still starts with two MCP tools. <code>search_tools</code> finds a reader or a Group with your team’s instructions. <code>execute_tools</code> calls the reader. The agent can write code that feeds one result into the next call. Symfony checks access each time.</p>
      <div class="actions"><a class="button" href="#judge-demo">Watch the 90-second demo</a><a href="#quickstart">Try the same question →</a></div>
    </div>
    <figure id="demo-tour">
      <video controls playsinline preload="none" width="1536" height="864" poster="media/symfony-code-mode-poster.jpg" aria-label="90-second explainer on tool discovery, team procedures and access checks, with recorded Codex CLI calls">
        <source src="media/symfony-code-mode-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-90s.mp4">Download the 90-second demo</a>
      </video>
      <figcaption>Codex searches the catalog, opens the refund Group and uses the order’s policy ID for its next read. After the operator revokes access, Symfony denies that same read. <a href="media/codex-refund-review.mp4">Watch the source session</a> · <a href="media/symfony-code-mode-film.json">Inspect its MCP calls</a> · <a href="media/judge-voiceover.txt">Read the narration</a><span class="film-links"><a class="alternate-film-link" href="#terminal-demo">Watch the terminal recording →</a><a href="#live-demo">Watch with an explanation →</a></span></figcaption>
    </figure>
    <figure id="native-explainer">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-native-explainer-poster.jpg" aria-label="90-second product tour with Claude Code in macOS Terminal alongside the Symfony explanation">
        <source src="media/symfony-code-mode-native-explainer-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-native-explainer-90s.mp4">Download the 90-second explainer</a>
      </video>
      <figcaption>Watch Claude find the team’s procedure and write a script to follow it. The order read succeeds. When permission changes, Symfony stops the policy read before the handler runs. <span class="film-links"><a href="#terminal-demo">Original terminal footage</a><a href="media/judge-native-explainer-voiceover.txt">Narration script</a><a href="media/symfony-code-mode-native-explainer-film.json">Edit notes</a><a href="#demo-tour">Codex tour</a></span></figcaption>
    </figure>
    <figure id="support-demo">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-support-poster.jpg" aria-label="90-second demo of tool retrieval, MCP Groups and a refund review with Claude">
        <source src="media/symfony-code-mode-support-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-support-90s.mp4">Download the support demo</a>
      </video>
      <figcaption>Earlier cut · Claude reads the order and policy in separate JavaScript programs. <span class="film-links"><a href="#support-calls">See the calls</a><a href="media/judge-support-voiceover.txt">Narration script</a><a href="#plain-demo">Watch the single-script run</a></span></figcaption>
    </figure>
    <figure id="plain-demo">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-plain-poster.jpg" aria-label="90-second demo of Claude opening a Symfony Group and chaining two reads in JavaScript">
        <source src="media/symfony-code-mode-plain-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-plain-90s.mp4">Download the demo</a>
      </video>
      <aside id="plain-claude" class="trace programmatic-trace">
        <details>
          <summary>Inspect Claude’s four calls and the JavaScript that links the reads</summary>
          <p id="plain-calls">Claude searched for the Group, opened it and followed its procedure. The final two calls ran inside the same JavaScript block.</p>
          <ol>
<?php foreach ($plainChat['calls'] as $call) { ?>
            <li><code><?php echo $escape($call['tool']); ?></code> <code><?php echo $escape(json_encode($call['arguments'], \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></code></li>
<?php } ?>
          </ol>
          <pre><code><?php echo $escape($plainChat['script']); ?></code></pre>
          <blockquote><?php echo $escape(explode("\n\n", $plainChat['answer'], 2)[0]); ?></blockquote>
          <p><a href="evidence/claude-plain-launch.json">Inspect the tool arguments, results and full answer</a></p>
        </details>
      </aside>
      <figcaption>Earlier film · Claude used a regular expression to extract the policy ID from the order.<span class="film-links"><a href="#plain-calls">Inspect the JavaScript and calls</a><a href="#ready-demo">Watch the structured-result film</a><a href="media/judge-plain-voiceover.txt">Voiceover script</a><a href="media/symfony-code-mode-plain-film.json">Film sources</a></span></figcaption>
    </figure>
    <figure id="ready-demo">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-ready-poster.jpg" aria-label="90-second film of Claude opening the refund Group and passing the policy ID from the order read into the policy read">
        <source src="media/symfony-code-mode-ready-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-ready-90s.mp4">Download the 90-second film</a>
      </video>
      <figcaption>Watch the third refund review, reopened in Claude Code. The Group supplies the procedure. Claude writes one program to read the order and its policy.<span class="film-links"><a href="#code-mode-ready">See the code and four MCP calls</a><a href="media/judge-ready-voiceover.txt">Narration script</a><a href="media/symfony-code-mode-ready-film.json">Film sources</a></span></figcaption>
    </figure>
    <figure id="judge-demo">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-judge-poster.jpg" aria-label="90-second demo of the refund Group, an authorized review and a denied order read">
        <source src="media/symfony-code-mode-judge-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-judge-90s.mp4">Download the 90-second demo</a>
      </video>
      <figcaption>The agent opens the team’s refund Group, checks the policy for R-1042 and recommends manual review. When it tries the same procedure for R-1043, Symfony denies the order read.<span class="film-links"><a href="media/judge-workflow-voiceover.txt">ElevenLabs narration</a><a href="#quickstart">Try the same questions</a><a href="#ready-demo">Earlier JavaScript proof</a></span></figcaption>
    </figure>
  </section>

  <section id="code-mode-ready" class="structured-review">
      <h2>How one read feeds the next</h2>
      <p>The order reader declares <code>policyId</code> as a required string. The description of <code>execute_tools</code> shows where to find that value in the reply. The path fits in Claude’s short discovery preview.</p>
<?php foreach (array_filter($structured['attempts'], static fn (array $attempt): bool => 'verified' === $attempt['status']) as $index => $attempt) {
    $reads = array_values(array_filter($attempt['calls'], static fn (array $call): bool => 'execute_tools' === $call['tool'] && !$call['isError']));
    $order = $reads[0]['result']['results'][0]['value'];
    $policy = $reads[1]['arguments']['calls'][0];
    ?>
      <p>Claude received the result as JSON text, decoded it with <code>JSON.parse</code> and read the order from <code>results[0].value</code>. Its JavaScript passed the field straight into the next MCP call. Ordinary MCP clients can make the same calls one at a time, and because Claude runs the JavaScript, Symfony only needs to authorize and run its PHP handlers.</p>
      <dl class="field-handoff">
        <div><dt>Order reader returned</dt><dd><code>policyId</code> <strong><?php echo $escape($order['policyId']); ?></strong></dd></div>
        <div><dt>Claude’s JavaScript</dt><dd><code><?php echo $escape($attempt['propertyReads'][array_key_last($attempt['propertyReads'])]); ?></code></dd></div>
        <div><dt>Policy reader received</dt><dd><code>policyId</code> <strong><?php echo $escape($policy['arguments']['policyId']); ?></strong></dd></div>
      </dl>
      <div class="trace programmatic-trace">
        <details class="verified-program"><summary>Read Claude’s JavaScript from attempt <?php echo $index + 1; ?></summary><pre><code><?php echo $escape($attempt['script']); ?></code></pre></details>
<?php foreach ($attempt['calls'] as $call) { ?>
        <details class="structured-call">
          <summary><span><code><?php echo $escape($call['tool']); ?></code> · <?php echo $escape($call['arguments']['calls'][0]['name'] ?? $call['arguments']['group'] ?? $call['arguments']['query'] ?? ''); ?></span></summary>
          <pre><?php echo $escape(json_encode(['arguments' => $call['arguments'], 'result' => $call['result']], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></pre>
        </details>
<?php } ?>
        <details><summary>Read the support question and Claude’s answer</summary><blockquote><?php echo $escape($structured['question']); ?></blockquote><pre><?php echo $escape($attempt['answer']); ?></pre></details>
      </div>
<?php } ?>
      <details class="attempt-history"><summary>Compare all <?php echo count($structured['attempts']); ?> attempts</summary>
        <ol>
<?php foreach ($structured['attempts'] as $attempt) { ?>
          <li><span><?php echo $escape($attempt['reason']); ?></span><p>Claude’s tool preview</p><pre><?php echo $escape($attempt['clientDiscoveryPreview']); ?></pre></li>
<?php } ?>
        </ol>
      </details>
      <p class="caption"><a href="evidence/claude-structured-refund.json">Download all three runs</a> · <a href="#programmatic-refund">See a separate run with access revoked</a> · <a href="#plain-calls">Compare the earlier script</a></p>
  </section>

  <section id="proof">
    <p class="eyebrow">Symfony AI agent transcript</p>
    <h2>Should we approve refund R-1042?</h2>
    <p>The team’s procedure says to read the order first, then look up the policy named in that order. Expand any of the agent’s six calls to see what it sent and what Symfony returned.</p>
    <div class="trace" aria-label="Verified MCP calls">
      <p class="trace-head"><code><?php echo $escape($session['server']); ?></code> <span>MCP over HTTP</span></p>
<?php foreach ($session['turns'] as $index => $turn) { ?>
<?php if ($index === $session['operator']['afterTurn']) { ?>
      <p class="permission-change">The operator revokes <code><?php echo $escape($session['operator']['permission']); ?></code>.</p>
<?php } ?>
      <p class="exchange"><strong>User</strong> <?php echo $escape($turn['question']); ?></p>
<?php foreach ($turn['calls'] as $call) {
    $result = $call['result'];
    $read = $call['arguments']['calls'][0] ?? null;
    $value = $result['results'][0]['value'] ?? [];
    $target = match (true) {
        isset($result['groups']) => 'Found '.implode(', ', array_column($result['groups'], 'name')).' and '.count($result['tools']).' tools',
        isset($result['group']) => 'Opened '.$result['group'].' · procedure and '.count($result['tools']).' tools',
        'refund-review' === $read['name'] => 'Tried to call the group '.$read['name'].' as a tool',
        isset($value['orderId']) => $read['name'].' · '.$value['orderId'].' → '.$value['policyId'],
        default => $read['name'].' · '.implode(', ', $read['arguments']),
    };
    ?>
      <details>
        <summary>
          <code><?php echo $escape($call['tool']); ?></code>
          <span><?php echo $escape($target); ?></span>
          <small class="<?php echo 'tool_denied' === $call['outcome'] ? 'denied' : 'success'; ?>"><?php echo $escape($call['outcome']); ?></small>
        </summary>
        <pre><?php echo $escape(json_encode($call, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></pre>
      </details>
<?php } ?>
      <p class="exchange"><strong>Agent</strong> <?php echo $escape($turn['answer']); ?></p>
<?php } ?>
    </div>
    <p class="caption"><?php echo $escape($session['model']); ?> made these calls through Symfony AI using fixture data. <a href="evidence/agent-procedure.json">Download the recorded session ↗</a></p>
  </section>

  <section id="how-it-works">
    <p class="eyebrow">Architecture</p>
    <h2>One tool, or a team procedure?</h2>
    <div class="steps">
      <article><h3>Find one tool</h3><p><code>search_tools</code> retrieves the definition. <code>execute_tools</code> calls the Symfony service with the arguments it expects.</p></article>
      <article><h3>Open a Group</h3><p>A Group pairs the team’s instructions with the tool definitions they use. Claude opens it through <code>search_tools</code>. Clients that support the MCP Groups draft can also list and open it by name.</p></article>
      <article><h3>Follow the procedure</h3><p>Claude writes and runs the JavaScript. It passes the field returned by one tool into the next. Symfony checks access before the Symfony AI Toolbox runs each reader.</p></article>
    </div>

    <div id="support-calls" class="trace programmatic-trace">
      <details><summary>What does a catalog cost at startup?</summary>
        <p>In our <?php echo (int) $largestCatalog['availableTools']; ?>-tool synthetic catalog, advertising every definition took <?php echo number_format($largestCatalog['direct']['tokens']); ?> tokens. Advertising the two catalog tools took <?php echo number_format($largestCatalog['catalog']['tokens']); ?>. We measured startup definitions only. <a href="evidence/catalog-scaling.json">See the measurements</a></p>
      </details>
      <details><summary>See the draft Groups methods on the wire</summary>
        <p>After the server advertised Groups, a PHP client made these calls in order.</p>
        <ol>
<?php foreach ($groupTrace['groups']['calls'] as $call) {
    $method = $call['request']['method'];
    $params = $call['request']['params'];
    $result = $call['response']['result'];
    $line = match ($method) {
        'groups/list' => implode(', ', array_column($result['groups'], 'name')),
        'groups/get' => $params['name'].' → '.implode(', ', array_column($result['tools'], 'name')),
        'tools/call' => $params['name'].' '.json_encode($params['arguments'], \JSON_THROW_ON_ERROR),
        default => null,
    };
    if (null !== $line) { ?>
          <li><code><?php echo $escape($method); ?></code> · <?php echo $escape($line); ?></li>
<?php }
    } ?>
        </ol>
        <p><a href="evidence/refund-group-discovery.json">Inspect the HTTP exchanges</a>. In an earlier Symfony AI run, the host listed the Groups and the model chose <code>refund-review</code>. Its two reads led to manual review. <a href="evidence/group-selection.json">See the model-run comparison</a></p>
      </details>
      <details><summary>Read the four programs from the earlier cut</summary>
        <p>Claude Code opened the Group through <code>search_tools</code>. It wrote separate JavaScript blocks for the order and policy reads.</p>
<?php foreach ($supportAttempt['programs'] as $program) { ?>
        <pre><code><?php echo $escape($program['source']); ?></code></pre>
<?php } ?>
      </details>
      <details class="support-answer"><summary>See Claude’s answer in the earlier cut</summary>
        <p><?php echo $escape($supportAttempt['question']); ?></p>
        <pre><?php echo $escape($supportAttempt['answer']); ?></pre>
        <p><a href="evidence/claude-support-review.json">Inspect Claude’s code and MCP calls</a> · <a href="media/symfony-code-mode-support-film.json">Film sources</a></p>
      </details>
    </div>
    <div id="live-demo" class="alternate-film">
      <h3>Watch Claude link two reads in one script</h3>
      <figure>
        <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-live-poster.jpg" aria-label="90-second explainer with a live Claude Code CLI recording">
          <source src="media/symfony-code-mode-live-90s.mp4" type="video/mp4">
          <a href="media/symfony-code-mode-live-90s.mp4">Download the live CLI film</a>
        </video>
        <figcaption>Claude finds the refund Group and writes JavaScript to follow its procedure. The script keeps the order facts when Symfony denies the policy read. <a href="media/symfony-code-mode-live-90s.mp4" download>Download the silent film</a> · <a href="media/judge-live-voiceover.txt">Narration script</a> · <a href="evidence/claude-refund-film-session.json">Recorded calls</a> · <a href="media/symfony-code-mode-live-film.json">Edit notes</a> · <a href="#terminal-demo">Terminal recording</a></figcaption>
      </figure>
    </div>
    <div id="terminal-demo" class="alternate-film">
      <h3>The refund review in the terminal</h3>
      <figure>
        <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-terminal-poster.jpg" aria-label="90-second terminal recording of the refund review">
          <source src="media/symfony-code-mode-terminal-90s.mp4" type="video/mp4">
          <a href="media/symfony-code-mode-terminal-90s.mp4">Download the terminal cut</a>
        </video>
        <figcaption>Recorded in macOS Terminal. Follow the prompts and denied policy read in Claude Code. At 1:22 the operator opens the saved Symfony trace. <a href="media/symfony-code-mode-terminal-full.mp4">Watch the full session</a> · <a href="media/symfony-code-mode-terminal-90s.mp4" download>Download the 90-second cut</a> · <a href="media/symfony-code-mode-terminal-film.json">Inspect the edit map</a> · <a href="#live-demo">Illustrated explainer</a></figcaption>
      </figure>
    </div>
    <article id="programmatic-refund">
      <h3>A client runs the refund procedure in code</h3>
      <p>In a separate run, <?php echo $escape($programmatic['client']); ?> opened the same Group and wrote JavaScript that used the order’s policy ID for its next read. The operator revoked access between the reads. Symfony denied the policy call and the script stopped. The client could still inspect the order it already held and reported the review as incomplete.</p>
      <div class="trace programmatic-trace">
        <details><summary>Read the script the agent ran</summary><pre><code><?php echo $escape($programmatic['script']); ?></code></pre></details>
<?php foreach ($programmatic['events'] as $event) { ?>
        <details class="event"><summary><?php echo $escape($event['label']); ?></summary><pre><?php echo $escape(json_encode($event, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></pre></details>
<?php } ?>
        <details class="execution-result"><summary>See the client execution result</summary><pre><?php echo $escape($programmatic['stdout']); ?></pre></details>
        <details><summary>Read the agent’s answer</summary><pre><?php echo $escape($programmatic['answer']); ?></pre></details>
      </div>
      <p class="caption"><a href="evidence/programmatic-refund.json">Download the script and recorded calls ↗</a> · <a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/demo.md#run-the-client-written-refund-review">Run it with a client ↗</a></p>
    </article>
    <div id="direct-tool" class="next">
      <h3>Give a frequent read a direct route</h3>
      <p>The featured refund review uses the two-tool default. In the independent Symfony app, adding <code>publication_read</code> to <code>code_mode.http.direct_tools</code> lets an authorized agent call that reader immediately. One direct read and one read through <code>execute_tools</code> returned the same article and used both available calls. After a budget reset and permission revocation, Symfony denied the direct retry before the reader ran. <a href="evidence/direct-tool.json">Inspect the calls and shared budget ↗</a></p>
    </div>
    <figure id="mcp-architecture" class="architecture"><a href="images/symfony-mcp-architecture.svg"><img src="images/symfony-mcp-architecture.svg" width="1120" height="748" loading="lazy" alt="Agent, MCP client, Symfony catalog, access checks and application handler. Pink diamonds mark this project’s additions and purple squares mark upstream components."></a>
      <figcaption>The diagram also shows the draft Groups route. A client that negotiates the extension can use <code>groups/list</code> and <code>groups/get</code> on the same refund endpoint, then call a member by name. <a href="https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19">Read the proposal ↗</a></figcaption>
    </figure>
  </section>

  <section id="quickstart">
    <p class="eyebrow">Reproduce the demo</p>
    <h2>Try the conversation yourself.</h2>
    <p>Start the local Symfony MCP server with PHP 8.2+ and Composer 2.5+. Leave it running.</p>
    <pre class="command"><code>git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer demo:serve</code></pre>
    <p>Open <strong><code>examples/support</code></strong> as a local project in Claude Code or Codex. Trust the project and approve <code>symfony_code_mode</code> if prompted. Each client uses its own project configuration to fetch the temporary credential.</p>
    <p>Open <code>/mcp</code> to see <code>search_tools</code> and <code>execute_tools</code>, then ask:</p>
    <blockquote><?php echo $escape($structured['question']); ?></blockquote>
    <p>Then ask about R-1043 in the same chat to see an order-level denial.</p>
    <p><a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/codex.md">Client setup and permission-change demo →</a></p>
  </section>

  <section id="journey">
    <p class="eyebrow">Design journey</p>
    <h2>Why we moved from PHP to a catalog.</h2>
    <div class="decisions">
      <article><h3>Let PHP do the work</h3><p>We started with agent-written PHP in gVisor. A program could join several tool results and return just the findings. That helped on a data-heavy audit, but it asked every adopting team to host a sandbox.</p></article>
      <article><h3>Bring retrieval to ordinary Symfony hosting</h3><p>Our next question was whether an agent needed to write PHP for a known read. A tool name and JSON arguments were enough. We kept Symfony AI’s definitions and handlers and used the PHP MCP SDK to carry the calls. The same endpoint handles Codex’s individual calls and Claude Code’s calls from a script. <a href="#programmatic-refund">Read the script and trace →</a> · <a href="https://laravel.com/blog/a-better-way-to-build-mcp-servers-with-laravel#what-changed-for-me">Pushpak Chhajed’s account of a similar choice ↗</a></p></article>
      <article><h3>Help the agent find the procedure</h3><p>A searchable list solves discovery one tool at a time. Sometimes a team already knows which tools belong together. The MCP working group’s Groups draft gave us a way to describe that knowledge. Our refund prototype lets ordinary clients retrieve the same procedure through search.</p></article>
      <article><h3>Give the script a field it can use</h3><p>An earlier Claude run used a regular expression to extract the policy ID from text. We declared the field and made the result path visible in Claude’s short tool preview. On the third run, its script read that field directly. <a href="#code-mode-ready">See the three attempts →</a></p></article>
    </div>
    <div id="contributions" class="next">
      <h3>What we can contribute</h3>
      <p>A Symfony AI recipe for permission-aware search, a PHP MCP SDK compatibility test for both discovery routes, and revocation results for the MCP working group. <a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/contributing/README.md">Contribution notes ↗</a></p>
      <h3 id="whats-next">What’s next</h3>
      <p>What happens when several Groups share the same tools? We want to test how often an agent picks the right procedure as the catalog grows.</p>
      <p>The PHP MCP SDK draft can serve a Skill. With experimental MCP support enabled, Codex could read its Markdown by URI. The remote Skill didn’t appear in the CLI’s Skills picker. Once Codex supports that step, we’d like to test whether a shared reporting template makes answers more consistent than the Group’s instructions alone. <a href="https://github.com/modelcontextprotocol/ext-skills/blob/main/docs/implementations.md#hosts-including-agent-harnesses-ides-and-web-applications">Skills over MCP client support ↗</a></p>
    </div>
    <p class="caption">Earlier runs · <a href="#plain-demo">Claude’s original single-script film</a> · <a href="#support-demo">Claude’s separate-program run</a> · <a href="#proof">Symfony AI conversation</a> · <a href="#native-explainer">Permission change in Claude Code</a> · <a href="#demo-tour">Codex tour</a></p>
  </section>
</main>
<footer><a class="brand" href="#overview">Symfony Code Mode</a><a href="https://github.com/ronaldtebrake/symfony-hackathon">Source on GitHub ↗</a></footer>
</body>
</html>
