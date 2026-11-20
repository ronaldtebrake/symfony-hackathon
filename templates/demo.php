<?php

declare(strict_types=1);

/** @var array $proof */
$escape = static fn (string $text): string => htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="Discover approved Symfony tools over MCP, follow a team procedure and check access on every read.">
  <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'">
  <title>Symfony Code Mode · A searchable MCP catalog</title>
  <link rel="stylesheet" href="demo.css">
</head>
<body>
<header class="site-header">
  <a class="brand" href="#overview"><span aria-hidden="true">{ }</span>Symfony Code Mode</a>
  <nav aria-label="Documentation"><a href="#demo-tour">Demo</a><a href="#how-it-works">How it works</a><a href="#journey">Design journey</a><a href="#quickstart">Try it locally</a></nav>
</header>
<main>
  <section id="overview" class="hero">
    <div>
      <p class="eyebrow">Symfony AI × PHP MCP SDK</p>
      <h1>Find the tools <em>a job needs.</em></h1>
      <p>An agent can call your Symfony services over the Model Context Protocol (MCP). As the list grows, sending every tool definition with every conversation costs context and tells the agent little about which tools work together.</p>
      <p>Symfony Code Mode gives the agent two tools. <code>search_tools</code> finds an approved capability or a team procedure. <code>execute_tools</code> calls its readers with the user’s current permissions.</p>
      <div class="actions"><a class="button" href="#quickstart">Try it locally <span aria-hidden="true">→</span></a><a href="#proof">See the calls ↓</a></div>
    </div>
    <figure id="demo-tour">
      <video controls playsinline preload="none" width="1920" height="1080" poster="media/editorial-review-poster.jpg" aria-label="Silent 90-second product film">
        <source src="media/editorial-review-90s.mp4" type="video/mp4">
        <a href="media/editorial-review-90s.mp4">Download the demo</a>
      </video>
      <figcaption><strong>The 90-second demo</strong> · Original agent footage of a catalog lookup and a replay of a separate Symfony AI refund conversation. In the film, the host opens the group. In today’s catalog, the agent can find and open it through search.</figcaption>
    </figure>
  </section>

  <section id="proof">
    <p class="eyebrow">Inside the current demo</p>
    <h2>One connection, from question to decision.</h2>
    <blockquote>“Find our refund review procedure and follow it for R-1042. Should I approve the refund or send it for manual review?”</blockquote>
    <p>The order points to its policy. Its €75 value exceeds the €50 automatic approval threshold, so it needs manual review. Watch what happens when the same user asks to check that policy again after access changes.</p>
    <div class="trace" aria-label="Verified MCP calls">
      <p class="trace-head"><code>symfony_code_mode</code> <span>Recorded HTTP proof</span></p>
<?php foreach ($proof['calls'] as $index => $call) { ?>
<?php
    $target = match (true) {
        isset($call['opened']) => 'Opened '.$call['opened'],
        isset($call['reader']) => $call['reader'],
        [] !== $call['groups'] => 'Found '.implode(', ', $call['groups']),
        default => 'No matching groups or tools',
    };
    if ($index === $proof['revokedAfterCall']) { ?>
      <p class="permission-change">Operator revokes the reader’s permission</p>
<?php } ?>
      <details>
        <summary>
          <code><?php echo $escape($call['tool']); ?></code>
          <span><?php echo $escape($target); ?></span>
          <small class="<?php echo 'denied' === $call['outcome'] ? 'denied' : 'success'; ?>"><?php echo $escape($call['outcome']); ?></small>
        </summary>
        <pre><?php echo $escape(json_encode($call, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)); ?></pre>
      </details>
<?php } ?>
    </div>
    <p class="caption">Expand a call to inspect its recorded arguments and outcome. This reproducible check uses the PHP MCP SDK against an authenticated HTTP server and makes zero model requests. The film’s agent conversation was captured separately. <a href="evidence/judge-demo.json">Download the current trace ↗</a></p>
  </section>

  <section id="how-it-works">
    <p class="eyebrow">Architecture</p>
    <h2>From a user’s question to a Symfony handler.</h2>
    <div class="steps">
      <article><h3>Discover</h3><p>The PHP MCP SDK advertises two tools. Search ranks visible contracts using Symfony AI search components. A group bundles readers with the team’s instructions for a job.</p></article>
      <article><h3>Choose</h3><p>The client opens a group through <code>search_tools</code> or uses an individual match. It can pass a value from one read into the next. Opening a group runs no application handler.</p></article>
      <article><h3>Execute</h3><p>Symfony checks the call budget, arguments and current permission. An allowed request reaches the Symfony AI Toolbox. Revocation stops the next read before the handler runs.</p></article>
    </div>
    <figure id="mcp-architecture" class="architecture"><a href="images/symfony-mcp-architecture.svg"><img src="images/symfony-mcp-architecture.svg" width="1120" height="748" loading="lazy" alt="Agent, MCP client, Symfony catalog, access checks and application handler. Pink diamonds mark this project’s additions and purple squares mark upstream components."></a>
      <figcaption>The diagram also shows the draft Groups route. A client that negotiates the extension can use <code>groups/list</code> and <code>groups/get</code> on the same refund endpoint, then call a member by name. <a href="https://github.com/modelcontextprotocol/progressive-disclosure-wg/pull/19">Read the proposal ↗</a></figcaption>
    </figure>
  </section>

  <section id="quickstart">
    <p class="eyebrow">Reproduce the demo</p>
    <h2>Run the proof on your machine.</h2>
    <p>With PHP 8.2+ and Composer 2, run the following from a terminal. The proof uses synthetic data and a local HTTP server. You can run it without a model account or container runtime.</p>
    <pre class="command"><code>git clone https://github.com/ronaldtebrake/symfony-hackathon.git
cd symfony-hackathon
composer install
composer proof:discovery</code></pre>
    <p>To ask the question yourself, connect your MCP client to the recording server as <code>symfony_code_mode</code>. Follow the live calls in the native Symfony Profiler. <a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/codex.md">Connection settings and judge prompt →</a></p>
  </section>

  <section id="journey">
    <p class="eyebrow">Design journey</p>
    <h2>Why we moved from PHP to a catalog.</h2>
    <div class="decisions">
      <article><h3>Let PHP do the work</h3><p>We started with agent-written PHP in gVisor. A program could join several tool results and return just the findings. That helped on a data-heavy audit, but it asked every adopting team to host a sandbox.</p></article>
      <article><h3>Call code the app already owns</h3><p>Our next question was whether the agent needed to write PHP to call a known reader. A name and literal JSON arguments were enough. Pushpak Chhajed described reaching a similar conclusion for Laravel. We kept Symfony AI’s tool definitions and handlers and used the PHP MCP SDK to carry the calls. <a href="https://laravel.com/blog/a-better-way-to-build-mcp-servers-with-laravel#what-changed-for-me">Read Pushpak’s account ↗</a></p></article>
      <article><h3>Help the agent find the procedure</h3><p>A searchable list solves discovery one tool at a time. Sometimes a team already knows which tools belong together. The MCP working group’s Groups draft gave us a way to describe that knowledge. Our refund prototype lets ordinary clients retrieve the same procedure through search.</p></article>
    </div>
    <div id="contributions" class="next">
      <h3>What we can contribute</h3>
      <p>A Symfony AI recipe for permission-aware search, a PHP MCP SDK compatibility test for both discovery routes, and revocation results for the MCP working group. <a href="https://github.com/ronaldtebrake/symfony-hackathon/blob/main/docs/contributing/README.md">Contribution notes ↗</a></p>
      <p id="whats-next"><strong>Next question.</strong> When several procedures overlap, does grouping help an agent choose the right tools? That needs a larger catalog and repeated measurements.</p>
    </div>
  </section>
</main>
<footer><a class="brand" href="#overview">Symfony Code Mode</a><a href="https://github.com/ronaldtebrake/symfony-hackathon">Source on GitHub ↗</a></footer>
</body>
</html>
