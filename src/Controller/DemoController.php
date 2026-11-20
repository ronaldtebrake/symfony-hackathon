<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DemoController
{
    public function __construct(#[Autowire('%kernel.project_dir%')] private string $projectDir)
    {
    }

    #[Route('/', name: 'app_home', methods: ['GET'])]
    #[Route('/demo', name: 'app_demo', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $proof = json_decode(file_get_contents($this->projectDir.'/docs/evidence/judge-demo.json'), true, flags: \JSON_THROW_ON_ERROR);
        ob_start();
        try {
            require $this->projectDir.'/templates/demo.php';
            $html = ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return new Response($html, headers: [
            'Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'self'; script-src 'none'; style-src 'self'; img-src 'self'; media-src 'self'; object-src 'none'; base-uri 'none'; frame-ancestors 'none'; form-action 'none'",
        ]);
    }

    #[Route('/evidence/judge-demo.json', name: 'app_judge_trace', defaults: ['file' => 'docs/evidence/judge-demo.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/images/symfony-mcp-architecture.svg', name: 'app_architecture', defaults: ['file' => 'docs/images/symfony-mcp-architecture.svg', 'type' => 'image/svg+xml'], methods: ['GET'])]
    #[Route('/media/editorial-review-90s.mp4', name: 'app_film', defaults: ['file' => 'public/media/editorial-review-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/editorial-review-poster.jpg', name: 'app_poster', defaults: ['file' => 'public/media/editorial-review-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    public function asset(Request $request, string $file, string $type): Response
    {
        $this->requireLocalRequest($request);

        // All file paths come from literal route defaults. BinaryFileResponse supports film seeking.
        return new BinaryFileResponse($this->projectDir.'/'.$file, headers: [
            'Content-Type' => $type, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store',
        ], public: false);
    }

    private function requireLocalRequest(Request $request): void
    {
        if (!\in_array($request->getClientIp(), ['127.0.0.1', '::1'], true) || !\in_array($request->getHost(), ['127.0.0.1', 'localhost', '[::1]'], true)) {
            throw new AccessDeniedHttpException('The workbench requires a local request and Host header.');
        }
    }
}
