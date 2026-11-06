<?php

declare(strict_types=1);

namespace App\Controller;

use App\Demo\ReportStore;
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

    #[Route('/demo/security-report', name: 'app_demo_security_report', methods: ['GET'])]
    public function securityReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readSecurityProof() ?? '{"status":"empty","cases":[]}', 'application/json');
    }

    #[Route('/demo/codex-report', name: 'app_demo_codex_report', methods: ['GET'])]
    public function codexReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readCodexProof() ?? '{"status":"empty","turns":[]}', 'application/json');
    }

    #[Route('/demo/boundary-report', name: 'app_demo_boundary_report', methods: ['GET'])]
    public function boundaryReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readBoundaryRecovery() ?? '{"status":"empty","turns":[]}', 'application/json');
    }

    #[Route('/demo/huggingface-report', name: 'app_demo_huggingface_report', methods: ['GET'])]
    public function huggingFaceReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readHuggingFaceProof() ?? '{"status":"empty","result":null}', 'application/json');
    }

    #[Route('/demo/huggingface-comparison', name: 'app_demo_huggingface_comparison', methods: ['GET'])]
    public function huggingFaceComparison(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readHuggingFaceComparison(), 'application/json');
    }

    #[Route('/demo/adoption-report', name: 'app_demo_adoption_report', methods: ['GET'])]
    public function adoptionReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readAdoptionProof() ?? '{"status":"empty"}', 'application/json');
    }

    #[Route('/demo/discovery-report', name: 'app_demo_discovery_report', methods: ['GET'])]
    public function discoveryReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = json_decode($this->store->readDiscoveryComparison() ?? '{"status":"empty"}', true, flags: JSON_THROW_ON_ERROR);
        if ('empty' !== $report['status']) {
            $report['summary'] = (new \App\Demo\DiscoveryComparison())->summarize($report);
            if ('complete' === $report['status'] && !$report['summary']['allCorrect']) { $report['status'] = 'failed'; }
        }
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/evaluation-report', name: 'app_demo_evaluation_report', methods: ['GET'])]
    public function evaluationReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = json_decode($this->store->readEvaluation() ?? '{"version":1,"kind":"evaluation","batches":[]}', true, flags: JSON_THROW_ON_ERROR);
        // Recheck completed rows instead of trusting saved success flags.
        foreach ($report['batches'] as &$batch) {
            foreach ($batch['runs'] as &$run) {
                if (in_array($run['status'], ['passed', 'failed'], true)) {
                    $run['recordedVerification'] = $run['verification'] ?? null;
                    $run['verification'] = (new \App\Demo\EvaluationVerifier())->verify($run);
                    $run['status'] = !isset($run['error']) && $run['verification']['passed'] ? 'passed' : 'failed';
                }
            }
            unset($run);
        }
        unset($batch);
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/performance-report', name: 'app_demo_performance_report', methods: ['GET'])]
    public function performanceReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = json_decode($this->store->readPerformanceProof() ?? '{"status":"empty"}', true, flags: JSON_THROW_ON_ERROR);
        if ('empty' !== $report['status']) {
            $report['verification'] = (new \App\Demo\Performance\ProofVerifier())->verify($report);
            if (!$report['verification']['passed']) { $report['status'] = 'failed'; }
        }
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/response-payloads', name: 'app_demo_response_payloads', methods: ['GET'])]
    public function responsePayloads(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(json_encode((new \App\Demo\ResponsePayloadComparison($this->projectDir))->report(), JSON_THROW_ON_ERROR), 'application/json');
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
