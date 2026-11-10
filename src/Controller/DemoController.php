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

    #[Route('/demo/agent-report', name: 'app_demo_agent_report', methods: ['GET'])]
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

    #[Route('/demo/audit-three-way', name: 'app_demo_audit_three_way', methods: ['GET'])]
    public function threeWayAudit(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = $this->store->readThreeWayAudit();
        foreach ($report['attempts'] as &$attempt) {
            foreach ($attempt['runs'] as &$run) {
                $run['verification'] = \App\Demo\ThreeWayAuditVerifier::verify($run);
                if (isset($run['draft']['answer'])) {
                    $run['draft']['verification'] = (new \App\Demo\AuditVerifier())->verify(json_decode($run['draft']['answer'], true), 'fixture', $run['result']['brokerCalls'] ?? []);
                }
                if ('running' !== $run['status']) { $run['status'] = $run['verification']['passed'] ? 'complete' : 'failed'; }
            }
            unset($run);
            $attempt['verification'] = \App\Demo\ThreeWayAuditVerifier::compare($attempt);
            if ('running' !== $attempt['status']) { $attempt['status'] = $attempt['verification']['passed'] ? 'complete' : 'failed'; }
        }
        unset($attempt);
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/adoption-report', name: 'app_demo_adoption_report', methods: ['GET'])]
    public function adoptionReport(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response($this->store->readAdoptionProof() ?? '{"status":"empty"}', 'application/json');
    }

    #[Route('/demo/catalog-proof', name: 'app_demo_catalog_proof', methods: ['GET'])]
    public function catalogProof(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = $this->store->readCatalogProof();
        foreach ($report['attempts'] as &$attempt) {
            if (isset($attempt['controls'])) { $attempt['controls']['verification'] = \App\Demo\CatalogControlProof::verify($attempt['controls']); }
            foreach ($attempt['runs'] as &$run) {
                if (!in_array($run['status'], ['complete', 'failed'], true)) { continue; }
                $run['recordedVerification'] = $run['verification'] ?? null;
                $run['verification'] = \App\Demo\CatalogProofVerifier::verify($run);
                $run['status'] = $run['verification']['passed'] ? 'complete' : 'failed';
            }
            unset($run);
        }
        unset($attempt);
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/native-catalog', name: 'app_demo_native_catalog', methods: ['GET'])]
    public function nativeCatalog(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $raw = $this->store->readNativeCatalogRehearsal();
        if (null === $raw) { return $this->response('{"status":"empty"}', 'application/json'); }
        $capture = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        $verification = \App\Demo\NativeCatalogRehearsal::verify($capture);
        $implementation = [];
        // Current application source, not a claim that source bytes were captured at execution.
        foreach (['handler' => 'ArticleTool.php', 'policy' => 'ReadPolicy.php'] as $role => $file) {
            $path = 'examples/symfony/src/'.$file;
            $source = file_get_contents($this->projectDir.'/'.$path);
            $implementation[$role] = ['path' => $path, 'source' => $source, 'sha256' => hash('sha256', $source)];
        }
        return $this->response(json_encode([
            'status' => $verification['passed'] ? 'passed' : 'failed', 'capture' => $capture,
            'captureSha256' => hash('sha256', $raw), 'verification' => $verification,
            'implementation' => $implementation,
        ], JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/http-catalog', name: 'app_demo_http_catalog', methods: ['GET'])]
    public function httpCatalog(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(json_encode(\App\Demo\HttpCatalogProof::load($this->projectDir), JSON_THROW_ON_ERROR), 'application/json');
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

    #[Route('/demo/performance-conversation', name: 'app_demo_performance_conversation', methods: ['GET'])]
    public function performanceConversation(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $source = $this->store->readPerformanceConversation() ?? '{"status":"empty"}';
        $report = json_decode($source, true, flags: JSON_THROW_ON_ERROR);
        if ('empty' !== $report['status']) {
            $verify = static function (array $attempt) use ($source): array {
                $attempt['verification'] = (new \App\Demo\Performance\ConversationVerifier())->verify($attempt);
                $attempt['payloadMeasurement'] = (new \App\Demo\Performance\TokenBreakdown())->summarize($attempt);
                if ('complete' === $attempt['status'] && !$attempt['verification']['passed']) { $attempt['status'] = 'failed'; }
                return $attempt;
            };
            $report = $verify($report);
            $report['previousAttempts'] = array_map($verify, $report['previousAttempts'] ?? []);
        }
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/performance-baseline', name: 'app_demo_performance_baseline', methods: ['GET'])]
    public function performanceBaseline(Request $request): Response
    {
        $this->requireLocalRequest($request);
        $report = json_decode($this->store->readPerformanceBaseline() ?? '{"status":"empty"}', true, flags: JSON_THROW_ON_ERROR);
        if ('empty' !== $report['status']) {
            $source = $this->store->readPerformanceConversation() ?? '{}';
            $verify = static function (array $attempt) use ($source): array {
                $attempt['comparison'] = (new \App\Demo\Performance\PerformanceComparison())->summarize($attempt, $source);
                $attempt['verification'] = $attempt['comparison']['directVerification'];
                if ('complete' === $attempt['status'] && !$attempt['verification']['passed']) { $attempt['status'] = 'failed'; }
                return $attempt;
            };
            $report = $verify($report);
            $report['previousAttempts'] = array_map($verify, $report['previousAttempts'] ?? []);
        }
        return $this->response(json_encode($report, JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/demo/response-payloads', name: 'app_demo_response_payloads', methods: ['GET'])]
    public function responsePayloads(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(json_encode((new \App\Demo\ResponsePayloadComparison($this->projectDir))->report(), JSON_THROW_ON_ERROR), 'application/json');
    }

    #[Route('/images/code-mode-value.svg', name: 'app_value_explainer', methods: ['GET'])]
    public function explainer(Request $request): Response
    {
        $this->requireLocalRequest($request);
        return $this->response(file_get_contents($this->projectDir.'/docs/images/code-mode-value.svg'), 'image/svg+xml');
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
