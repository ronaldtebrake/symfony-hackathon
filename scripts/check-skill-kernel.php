<?php

declare(strict_types=1);

use Adoption\DemoReaderAccess;
use Adoption\Kernel;
use App\Demo\Mcp\CaptureRequestFactory;
use App\Demo\Skills\Fixture;
use Symfony\Component\HttpFoundation\Request;

$root = dirname(__DIR__);
if (['--in-private-app'] !== array_slice($argv, 1)) {
    if (2 !== $argc) {
        fwrite(\STDERR, "Usage: php scripts/check-skill-kernel.php /path/to/installed-php-sdk-draft\n");
        exit(2);
    }
    require $root.'/vendor/autoload.php';
    $fixture = new Fixture($root, $argv[1], startHttp: false);
    try {
        echo json_encode($fixture->checkKernelReuse(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR)."\n";
    } finally {
        $fixture->close();
    }

    return;
}

// This branch is launched by the fixture inside its disposable copy of the Symfony application.
if ('skill_eval' !== getenv('APP_ENV')) {
    throw new LogicException('The worker requires the isolated Skills fixture.');
}
require getcwd().'/vendor/autoload.php';
require_once $root.'/src/Demo/Mcp/CaptureRequestFactory.php';
require_once $root.'/src/Demo/Skills/Fixture.php';
$_SERVER['APP_SECRET'] ??= bin2hex(random_bytes(32));
$kernel = new Kernel('skill_eval', false);
$kernel->boot();
$container = $kernel->getContainer();
$id = 0;
$requests = new CaptureRequestFactory('persistent-kernel-check', [Fixture::EXTENSION]);
$rpc = static function (string $token, string $method, array $params = []) use ($kernel, $container, $requests, &$id): array {
    $message = $requests->message(++$id, $method, $params);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream',
        'HTTP_AUTHORIZATION' => 'Bearer '.$token];
    $body = json_encode($message, \JSON_THROW_ON_ERROR);
    $request = Request::create('http://127.0.0.1/mcp', 'POST', server: $headers, content: $body);
    $request->headers->add($requests->headers($message));
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);
    $result = json_decode($response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    if ($kernel->getContainer() !== $container || $id !== ($result['id'] ?? null) || !in_array($response->getStatusCode(), [200, 400], true)) {
        throw new RuntimeException('Unexpected response or container replacement in persistent kernel.');
    }

    return $result;
};

$reader = getenv('ADOPTION_MCP_TOKEN');
$viewer = getenv('ADOPTION_VIEWER_TOKEN');
$file = getcwd().'/skills/read-published-article/SKILL.md';
$steps = [];
foreach (['reader', 'viewer', 'reader-again', 'revoked-reader'] as $step) {
    if ('revoked-reader' === $step) {
        (new DemoReaderAccess(getenv('ADOPTION_READER_ACCESS_FILE')))->setGranted(false);
    }
    $token = 'viewer' === $step ? $viewer : $reader;
    $visible = in_array($step, ['reader', 'reader-again'], true);
    $list = $rpc($token, 'skills/list');
    $read = $rpc($token, 'resources/read', ['uri' => Fixture::URI]);
    $skills = $list['result']['skills'] ?? null;
    $contents = $read['result']['contents'][0]['text'] ?? null;
    if (!is_array($skills) || ($visible ? 1 : 0) !== count($skills) || ($visible ? [Fixture::URI] : []) !== array_column($skills, 'uri')
        || ($visible ? file_get_contents($file) !== $contents : (isset($read['result']) || -32602 !== ($read['error']['code'] ?? null)))) {
        throw new RuntimeException('Skill visibility or direct resource access differed at step '.$step.'.');
    }
    $steps[] = ['step' => $step, 'listed' => count($skills), 'returnedBytes' => null === $contents ? 0 : strlen($contents),
        'errorCode' => $read['error']['code'] ?? null];
}
echo json_encode(['format' => 'symfony-skills-kernel-reuse/v1', 'sdkRevision' => Fixture::REVISION, 'sameContainer' => true,
    'kernelRequests' => $id, 'modelRequests' => 0, 'steps' => $steps], \JSON_THROW_ON_ERROR)."\n";
