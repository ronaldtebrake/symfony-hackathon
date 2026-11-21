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
        $session = json_decode(file_get_contents($this->projectDir.'/docs/evidence/agent-procedure.json'), true, flags: \JSON_THROW_ON_ERROR);
        $programmatic = json_decode(file_get_contents($this->projectDir.'/docs/evidence/programmatic-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $support = json_decode(file_get_contents($this->projectDir.'/docs/evidence/claude-support-review.json'), true, flags: \JSON_THROW_ON_ERROR);
        $supportAttempt = $support['attempts'][$support['featuredAttempt'] - 1];
        $plainChat = json_decode(file_get_contents($this->projectDir.'/docs/evidence/claude-plain-launch.json'), true, flags: \JSON_THROW_ON_ERROR);
        $structured = json_decode(file_get_contents($this->projectDir.'/docs/evidence/claude-structured-refund.json'), true, flags: \JSON_THROW_ON_ERROR);
        $groupTrace = json_decode(file_get_contents($this->projectDir.'/docs/evidence/refund-group-discovery.json'), true, flags: \JSON_THROW_ON_ERROR);
        $scale = json_decode(file_get_contents($this->projectDir.'/docs/evidence/catalog-scaling.json'), true, flags: \JSON_THROW_ON_ERROR)['samples'];
        $largestCatalog = $scale[array_key_last($scale)];
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
    #[Route('/evidence/direct-tool.json', name: 'app_direct_tool_trace', defaults: ['file' => 'docs/evidence/direct-tool.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/agent-procedure.json', name: 'app_agent_transcript', defaults: ['file' => 'docs/evidence/agent-procedure.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/programmatic-refund.json', name: 'app_programmatic_refund', defaults: ['file' => 'docs/evidence/programmatic-refund.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/claude-refund-film-session.json', name: 'app_live_film_session', defaults: ['file' => 'docs/evidence/claude-refund-film-session.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/claude-support-review.json', name: 'app_support_session', defaults: ['file' => 'docs/evidence/claude-support-review.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/claude-plain-launch.json', name: 'app_plain_claude_session', defaults: ['file' => 'docs/evidence/claude-plain-launch.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/claude-structured-refund.json', name: 'app_structured_refund_session', defaults: ['file' => 'docs/evidence/claude-structured-refund.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/refund-group-discovery.json', name: 'app_refund_discovery_trace', defaults: ['file' => 'docs/evidence/refund-group-discovery.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/group-selection.json', name: 'app_group_selection', defaults: ['file' => 'docs/evidence/group-selection.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/evidence/catalog-scaling.json', name: 'app_catalog_scaling', defaults: ['file' => 'docs/evidence/catalog-scaling.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-ready-90s.mp4', name: 'app_ready_film', defaults: ['file' => 'public/media/symfony-code-mode-ready-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-ready-poster.jpg', name: 'app_ready_poster', defaults: ['file' => 'public/media/symfony-code-mode-ready-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-ready-film.json', name: 'app_ready_film_sources', defaults: ['file' => 'public/media/symfony-code-mode-ready-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-ready-voiceover.txt', name: 'app_ready_voiceover', defaults: ['file' => 'docs/judge-ready-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-judge-90s.mp4', name: 'app_judge_workflow_film', defaults: ['file' => 'public/media/symfony-code-mode-judge-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-judge-poster.jpg', name: 'app_judge_workflow_poster', defaults: ['file' => 'public/media/symfony-code-mode-judge-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-judge-film.json', name: 'app_judge_workflow_film_sources', defaults: ['file' => 'public/media/symfony-code-mode-judge-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-workflow-voiceover.txt', name: 'app_judge_workflow_voiceover', defaults: ['file' => 'docs/judge-workflow-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-plain-90s.mp4', name: 'app_plain_film', defaults: ['file' => 'public/media/symfony-code-mode-plain-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-plain-poster.jpg', name: 'app_plain_poster', defaults: ['file' => 'public/media/symfony-code-mode-plain-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-plain-film.json', name: 'app_plain_film_sources', defaults: ['file' => 'public/media/symfony-code-mode-plain-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-plain-voiceover.txt', name: 'app_plain_voiceover', defaults: ['file' => 'docs/judge-plain-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-support-90s.mp4', name: 'app_support_film', defaults: ['file' => 'public/media/symfony-code-mode-support-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-support-poster.jpg', name: 'app_support_poster', defaults: ['file' => 'public/media/symfony-code-mode-support-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-support-film.json', name: 'app_support_film_sources', defaults: ['file' => 'public/media/symfony-code-mode-support-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-support-voiceover.txt', name: 'app_support_voiceover', defaults: ['file' => 'docs/judge-support-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/images/symfony-mcp-architecture.svg', name: 'app_architecture', defaults: ['file' => 'docs/images/symfony-mcp-architecture.svg', 'type' => 'image/svg+xml'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-90s.mp4', name: 'app_judge_film', defaults: ['file' => 'public/media/symfony-code-mode-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-poster.jpg', name: 'app_judge_poster', defaults: ['file' => 'public/media/symfony-code-mode-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-film.json', name: 'app_judge_film_record', defaults: ['file' => 'public/media/symfony-code-mode-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-voiceover.txt', name: 'app_judge_voiceover', defaults: ['file' => 'docs/judge-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-native-explainer-90s.mp4', name: 'app_native_explainer', defaults: ['file' => 'public/media/symfony-code-mode-native-explainer-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-native-explainer-poster.jpg', name: 'app_native_explainer_poster', defaults: ['file' => 'public/media/symfony-code-mode-native-explainer-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-native-explainer-film.json', name: 'app_native_explainer_edit_map', defaults: ['file' => 'public/media/symfony-code-mode-native-explainer-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-native-explainer-voiceover.txt', name: 'app_native_explainer_voiceover', defaults: ['file' => 'docs/judge-native-explainer-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-live-90s.mp4', name: 'app_live_film', defaults: ['file' => 'public/media/symfony-code-mode-live-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-live-poster.jpg', name: 'app_live_poster', defaults: ['file' => 'public/media/symfony-code-mode-live-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-live-film.json', name: 'app_live_film_record', defaults: ['file' => 'public/media/symfony-code-mode-live-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-terminal-90s.mp4', name: 'app_terminal_cut', defaults: ['file' => 'public/media/symfony-code-mode-terminal-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-terminal-full.mp4', name: 'app_terminal_recording', defaults: ['file' => 'public/media/symfony-code-mode-terminal-full.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-terminal-operator.mp4', name: 'app_terminal_operator', defaults: ['file' => 'public/media/symfony-code-mode-terminal-operator.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-terminal-poster.jpg', name: 'app_terminal_poster', defaults: ['file' => 'public/media/symfony-code-mode-terminal-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/symfony-code-mode-terminal-film.json', name: 'app_terminal_edit_map', defaults: ['file' => 'public/media/symfony-code-mode-terminal-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/judge-live-voiceover.txt', name: 'app_live_voiceover', defaults: ['file' => 'docs/judge-live-voiceover.txt', 'type' => 'text/plain; charset=UTF-8'], methods: ['GET'])]
    #[Route('/media/editorial-review-90s.mp4', name: 'app_film', defaults: ['file' => 'public/media/editorial-review-90s.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/editorial-review-film.json', name: 'app_editorial_film_record', defaults: ['file' => 'public/media/editorial-review-film.json', 'type' => 'application/json'], methods: ['GET'])]
    #[Route('/media/editorial-review-poster.jpg', name: 'app_poster', defaults: ['file' => 'public/media/editorial-review-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/codex-refund-review.mp4', name: 'app_codex_film', defaults: ['file' => 'public/media/codex-refund-review.mp4', 'type' => 'video/mp4'], methods: ['GET'])]
    #[Route('/media/codex-refund-review-poster.jpg', name: 'app_codex_poster', defaults: ['file' => 'public/media/codex-refund-review-poster.jpg', 'type' => 'image/jpeg'], methods: ['GET'])]
    #[Route('/media/codex-refund-review-film.json', name: 'app_codex_film_record', defaults: ['file' => 'public/media/codex-refund-review-film.json', 'type' => 'application/json'], methods: ['GET'])]
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
