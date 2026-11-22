<?php

declare(strict_types=1);

/** @var array $session */
/** @var array $structured */
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
      <h1>Find the right tools. <em>Follow the team’s procedure.</em></h1>
      <p>We started with a question. As a Symfony app grows, why should an agent read every tool definition before it can answer a user? Our MCP catalog starts with just <code>search_tools</code> and <code>execute_tools</code>. The agent looks up what it needs and calls it.</p>
      <p>Finding a tool answers “what can I call?” A team procedure answers “what should I do next?” We prototyped the MCP working group’s named Groups draft so a team can publish instructions alongside the tools for a job. A client can follow them call by call, or write a program that passes one result into the next. Symfony runs its existing handlers and checks each read. The application needs no sandbox.</p>
      <div class="actions"><a class="button" href="#judge-demo">Watch the narrated 90-second demo</a><a href="#quickstart">Try the same question →</a></div>
    </div>
    <figure id="judge-demo">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/symfony-code-mode-judge-poster.jpg" aria-label="90-second demo of the refund Group, an authorized review and a denied order read">
        <source src="media/symfony-code-mode-judge-90s.mp4" type="video/mp4">
        <a href="media/symfony-code-mode-judge-90s.mp4">Download the 90-second demo</a>
      </video>
      <figcaption>The agent opens the team’s refund Group, checks the policy for R-1042 and recommends manual review. When it tries the same procedure for R-1043, Symfony denies the order read.<span class="film-links"><a href="media/judge-workflow-voiceover.txt">Read the transcript</a><a href="#quickstart">Try the same questions</a><a href="#code-mode-ready">Inspect the JavaScript proof</a></span></figcaption>
    </figure>
  </section>

  <section id="how-it-works">
    <p class="eyebrow">Architecture</p>
    <h2>One tool, or a team procedure?</h2>
    <div class="steps">
      <article><h3>Find the tools for the job</h3><p>The client starts with two tool definitions, even in our <?php echo (int) $largestCatalog['availableTools']; ?>-tool test catalog. It uses <code>search_tools</code> to find the readers available to this user. <a href="evidence/catalog-scaling.json">See the startup measurement</a></p></article>
      <article><h3>Open a Group</h3><p>A Group pairs the team’s instructions with the tool definitions they use. In the film, opening <code>refund-review</code> tells the agent where to start: read the order, then look up the policy named on it.</p></article>
      <article><h3>Follow the procedure</h3><p>The agent calls both readers through <code>execute_tools</code>. Symfony checks access each time. When the user asks about a second order, Symfony denies that read.</p></article>
    </div>

    <figure id="mcp-profiler" class="profiler">
      <h3>Follow the calls in Symfony Profiler</h3>
      <a href="images/symfony-mcp-profiler.png"><img src="images/symfony-mcp-profiler.png" width="1425" height="789" loading="lazy" alt="Six MCP requests in order: list tools, find the refund Group, open it, read the order, read its policy, then fail to read the second order."></a>
      <figcaption>In this PHP MCP SDK replay, search finds and opens <code>refund-review</code>. Execution reads R-1042 and its policy. The last request also returns HTTP 200, but the tool reports a denial for R-1043. <a href="images/symfony-mcp-profiler.png">Open the full-size trace</a></figcaption>
    </figure>

    <figure id="mcp-architecture" class="architecture">
      <figcaption>
        <h3>Why did Symfony deny the last read?</h3>
        <p>The agent had already found the order reader. When it asked for R-1043, Symfony checked whether the user could read that order. In the diagram, follow the catalog route to the permission check: the denial goes back to the client before the PHP handler runs.</p>
      </figcaption>
      <p class="diagram-hint">Scroll across the diagram or <a href="images/symfony-mcp-architecture.svg">open it full size</a>.</p>
      <a class="diagram-view" href="images/symfony-mcp-architecture.svg"><img src="images/symfony-mcp-architecture.svg" width="1120" height="748" loading="lazy" alt="A client finds and opens a procedure through the catalog or negotiated Groups methods. Both paths check each read before the Symfony AI Toolbox invokes the handler. Pink diamonds mark this project’s additions and purple squares mark upstream components."></a>
      <p class="caption">The second route shows how a client that supports the Groups draft can reach those same readers. <a href="https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19">Read the Groups proposal ↗</a></p>
    </figure>
  </section>

  <section id="code-mode-ready" class="structured-review">
      <h2>How one read feeds the next</h2>
      <p>Which refund policy applies? The order itself supplies the answer. In this recorded run, Claude’s JavaScript reads the order, then passes its <code>policyId</code> straight into the policy lookup. Symfony checks the caller’s access on both reads.</p>
<?php foreach (array_filter($structured['attempts'], static fn (array $attempt): bool => 'verified' === $attempt['status']) as $attempt) {
    $reads = array_values(array_filter($attempt['calls'], static fn (array $call): bool => 'execute_tools' === $call['tool'] && !$call['isError']));
    $order = $reads[0]['result']['results'][0]['value'];
    $policy = $reads[1]['arguments']['calls'][0];
    ?>
      <dl class="field-handoff">
        <div><dt>Order reader returned</dt><dd><code>policyId</code> <strong><?php echo $escape($order['policyId']); ?></strong></dd></div>
        <div><dt>Claude’s JavaScript</dt><dd><code><?php echo $escape($attempt['propertyReads'][array_key_last($attempt['propertyReads'])]); ?></code></dd></div>
        <div><dt>Policy reader received</dt><dd><code>policyId</code> <strong><?php echo $escape($policy['arguments']['policyId']); ?></strong></dd></div>
      </dl>
      <div class="trace programmatic-trace">
        <details class="verified-program"><summary>Read the JavaScript Claude ran</summary><pre><code><?php echo $escape($attempt['script']); ?></code></pre></details>
<?php foreach ($attempt['calls'] as $call) { ?>
        <details class="structured-call">
          <summary><span><code><?php echo $escape($call['tool']); ?></code> · <?php echo $escape($call['arguments']['calls'][0]['name'] ?? $call['arguments']['group'] ?? $call['arguments']['query'] ?? ''); ?></span></summary>
          <pre><?php echo $escape(json_encode(['arguments' => $call['arguments'], 'result' => $call['result']], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></pre>
        </details>
<?php } ?>
        <details><summary>Read the support question and Claude’s answer</summary><blockquote><?php echo $escape($structured['question']); ?></blockquote><pre><?php echo $escape($attempt['answer']); ?></pre></details>
      </div>
<?php } ?>
      <p class="caption"><a href="evidence/claude-structured-refund.json">Download the run history (JSON)</a></p>
      <p id="programmatic-refund">What if access changes between those two reads? In a separate replay, the client already had the order’s policy ID when the operator revoked access. Symfony denied the policy lookup before its handler ran, so the agent left the review incomplete. <a href="evidence/programmatic-refund.json">Inspect the recorded denial</a></p>
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
    <h2>From a growing catalog to a team procedure</h2>
    <div class="decisions">
      <article><h3>Let PHP do the work</h3><p>Every extra tool meant another definition for the agent to read. Once it found the tools, intermediate results also filled the conversation. We tried Code Mode for that second problem first: the agent wrote PHP and ran it in gVisor. A program could join several tool results and return just the findings. That helped on a data-heavy audit, but it asked every adopting team to host a sandbox. <a href="https://blog.cloudflare.com/code-mode-mcp/">The server-side Code Mode idea ↗</a></p></article>
      <article><h3>Bring retrieval to ordinary Symfony hosting</h3><p>Our next question was whether an agent needed to write PHP for a known read. A tool name and JSON arguments were enough. We kept Symfony AI’s definitions and handlers and used the PHP MCP SDK to carry the calls. The same endpoint handles Codex’s individual calls and Claude Code’s calls from a script. <a href="#code-mode-ready">See how the client chains two reads →</a> · <a href="https://github.com/symfony/ai/issues/2623">Symfony AI’s discovery discussion ↗</a> · <a href="https://laravel.com/blog/a-better-way-to-build-mcp-servers-with-laravel#what-changed-for-me">Pushpak Chhajed’s account of a similar choice ↗</a></p></article>
      <article><h3>Help the agent find the procedure</h3><p>A searchable list solves discovery one tool at a time. Sometimes a team already knows which tools belong together. The MCP working group’s Groups draft gave us a way to describe that knowledge. A client that negotiates Groups can list them, open one and call its members. We also made the same procedure discoverable through our catalog for clients that use ordinary MCP tools. <a href="https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19">Read the named Groups proposal ↗</a> · <a href="evidence/refund-group-discovery.json">See the actual MCP calls</a></p></article>
      <article><h3>Give the script a field it can use</h3><p>Early scripts picked the policy ID out of text. We made it a named field and showed clients where to find it in the reply. Claude could then use the order’s own policy ID for its next call. <a href="#code-mode-ready">Follow the policy ID between reads →</a></p></article>
    </div>
  </section>

  <section id="contributions">
    <h2>What we can contribute</h2>
    <p>Three pieces from the demo that maintainers can try independently.</p>
    <div class="topic-cards">
      <article id="direct-tool"><p class="eyebrow">Symfony AI</p><h3>A permission-aware tool catalog</h3><p>Expose the tool definitions a caller can discover. When the agent asks to run one, check access again before the Toolbox calls the Symfony service.</p><a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/src/Demo/GroupSelection/Discovery.php">Read the discovery implementation →</a><a href="evidence/direct-tool.json">Compare a directly exposed reader</a></article>
      <article><p class="eyebrow">PHP MCP SDK</p><h3>One endpoint, two client paths</h3><p>Our compatibility test compares the definitions returned through catalog search and through the Groups draft. It also checks the shared call budget and what happens after permission is revoked.</p><a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/tests/Integration/RefundFallbackTest.php">Inspect the compatibility test →</a></article>
      <article id="support-calls"><p class="eyebrow">MCP working group</p><h3>A procedure on the wire</h3><p>A recorded exchange shows what an agent sees before it chooses a Group, which instructions it gets on opening it, and how it calls a member by name.</p><a href="evidence/refund-group-discovery.json">Inspect the protocol exchange →</a></article>
    </div>
    <a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/contributing/README.md">Contribution notes and upstream discussions ↗</a>
  </section>

  <section id="whats-next">
    <h2>What’s next</h2>
    <div class="topic-cards">
      <article><h3>Can the agent pick the right procedure?</h3><p>Give several Groups overlapping tools and similar names. Compare the agent’s first choice with the procedure the team intended.</p></article>
      <article><h3>When does a Skill help?</h3><p>Our experimental client could read a remote guide by URI. With a client that can select the Skill, we’d compare the same task with Group instructions alone and with a shared reporting template.</p><a href="https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/22">Groups and Skills discussion ↗</a></article>
      <article><h3>What does a whole task cost?</h3><p>We measured how many tokens it takes to advertise the catalog. Next we’d run matched conversations and compare correct answers, total tokens and requests as the catalog grows.</p><a href="evidence/catalog-scaling.json">See the startup measurement →</a></article>
    </div>
    <p class="caption">Explore the evidence · <a href="#mcp-profiler">Client tool calls</a> · <a href="#proof">Symfony AI conversation</a></p>
  </section>
</main>
<footer>
  <a class="brand" href="#overview">Symfony Code Mode</a>
  <a href="https://github.com/ronaldtebrake/symfony-hackathon">Source on GitHub ↗</a>
  <p><strong>AI disclosure.</strong> I used AI tools to help write the code and documentation.</p>
</footer>
</body>
</html>
