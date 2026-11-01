<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\ReportStore;
use App\Demo\DemoQuestion;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DemoController
{
    public function __construct(private ReportStore $store, #[Autowire('%kernel.project_dir%')] private string $projectDir) {}

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/demo', name: 'app_demo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(file_get_contents($this->projectDir.'/templates/demo.html'), 'text/html; charset=utf-8');
    }

    #[Route('/demo/report', name: 'app_demo_report', methods: ['GET'])]
    public function report(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->read() ?? '{"status":"empty","results":[]}', 'application/json');
    }

    #[Route('/demo/questions', name: 'app_demo_questions', methods: ['GET'])]
    public function questions(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(json_encode(['choices' => DemoQuestion::choices(), 'maxBytes' => DemoQuestion::MAX_BYTES], JSON_THROW_ON_ERROR), 'application/json');
    }

    private function requireLocalRequest(Request $request): void
    {
        if (!in_array($request->getClientIp(), ['127.0.0.1', '::1'], true) || !in_array($request->getHost(), ['127.0.0.1', 'localhost', '[::1]'], true)) {
            throw new AccessDeniedHttpException('The demo report is available only on loopback with a local Host header.');
        }
    }

    private function response(string $body, string $type): Response
    {
        return new Response($body, headers: [
            'Content-Type' => $type, 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; connect-src 'self'; base-uri 'none'; frame-ancestors 'none'",
        ]);
    }
}
