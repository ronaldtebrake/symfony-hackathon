<?php

declare(strict_types=1);

namespace App\Demo\Performance;

use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Controller\ControllerResolver;
use Symfony\Component\HttpKernel\EventListener\ProfilerListener;
use Symfony\Component\HttpKernel\EventListener\RouterListener;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\Profiler\FileProfilerStorage;
use Symfony\Component\HttpKernel\Profiler\Profiler;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;

/** Host-owned fixture generation, before a read-only Code Mode session is created. */
final class Capture
{
    public function run(): Dataset
    {
        if (!extension_loaded('pdo_sqlite')) { throw new \RuntimeException('The performance fixture requires pdo_sqlite.'); }
        $directory = sys_get_temp_dir().'/code-mode-profiler-'.bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) { throw new \RuntimeException('Cannot create isolated profiler storage.'); }
        try {
            $db = new \PDO('sqlite::memory:', options: [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            $db->exec('CREATE TABLE authors (id INTEGER PRIMARY KEY, name TEXT, email TEXT); CREATE TABLE products (id INTEGER PRIMARY KEY, author_id INTEGER);');
            $insert = $db->prepare('INSERT INTO authors VALUES (?, ?, ?)');
            for ($id = 1; $id <= 4; ++$id) { $insert->execute([$id, 'Author '.$id, RequestCollector::CANARY.'@example.test']); }
            $insert = $db->prepare('INSERT INTO products VALUES (?, ?)');
            for ($id = 1; $id <= 12; ++$id) { $insert->execute([$id, 1 + ($id - 1) % 4]); }

            $collector = new RequestCollector();
            $routes = new RouteCollection();
            $routes->add('catalog_naive', new Route('/catalog/before', ['_controller' => static function () use ($db, $collector): JsonResponse {
                $items = [];
                foreach ($collector->query($db, 'catalog_list', 'SELECT id, author_id FROM products ORDER BY id') as $product) {
                    $author = $collector->query($db, 'author_by_id', 'SELECT name, email FROM authors WHERE id = ?', [$product['author_id']])[0];
                    $items[] = ['id' => $product['id'], 'author' => $author['name']];
                }
                $collector->warning('authors.loaded_individually', ['count' => count($items), 'authorization' => RequestCollector::CANARY]);
                $collector->info('catalog.rendered', ['count' => count($items), 'customer_email' => RequestCollector::CANARY.'@example.test']);
                return new JsonResponse($items);
            }]));
            $routes->add('catalog_joined', new Route('/catalog/after', ['_controller' => static function () use ($db, $collector): JsonResponse {
                $items = $collector->query($db, 'catalog_join', 'SELECT products.id, authors.name AS author FROM products JOIN authors ON products.author_id = authors.id ORDER BY products.id');
                $collector->info('catalog.rendered', ['count' => count($items), 'authorization' => RequestCollector::CANARY]);
                return new JsonResponse($items);
            }]));
            $routes->add('health', new Route('/health', ['_controller' => static function () use ($collector): JsonResponse {
                $collector->info('health.ok', ['count' => 1]);
                return new JsonResponse(['ok' => true]);
            }]));
            $routes->add('internal_billing', new Route('/internal/billing', ['_controller' => static function () use ($collector): JsonResponse {
                $collector->warning('private.billing', ['secret' => RequestCollector::CANARY]);
                return new JsonResponse(['private' => RequestCollector::CANARY]);
            }]));

            $profiler = new Profiler(new FileProfilerStorage('file:'.$directory));
            $profiler->add($collector);
            $stack = new RequestStack();
            $dispatcher = new EventDispatcher();
            $dispatcher->addSubscriber(new RouterListener(new UrlMatcher($routes, new RequestContext()), $stack));
            $dispatcher->addSubscriber(new ProfilerListener($profiler, $stack));
            $kernel = new HttpKernel($dispatcher, new ControllerResolver(), $stack);
            $records = [];
            foreach (['profile-01' => ['/catalog/before', 'before', true], 'profile-02' => ['/catalog/after', 'after', true],
                'profile-03' => ['/health', 'control', true], 'profile-04' => ['/internal/billing', 'restricted', false]] as $id => [$path, $variant, $approved]) {
                $profiler->reset();
                $request = Request::create('http://localhost'.$path);
                $request->headers->set('Authorization', 'Bearer '.RequestCollector::CANARY);
                $response = $kernel->handle($request);
                $kernel->terminate($request, $response);
                $stored = $profiler->loadProfileFromResponse($response);
                if (null === $stored || 200 !== $response->getStatusCode()) { throw new \RuntimeException('Synthetic profile was not persisted.'); }
                $records[$id] = ['approved' => $approved, 'variant' => $variant, 'profile' => $stored->getCollector($collector->getName())->snapshot()];
            }
            $dataset = new Dataset($records);
            if (str_contains(serialize($dataset), RequestCollector::CANARY)) {
                throw new \RuntimeException('Raw fixture data survived projection.');
            }
            return $dataset;
        } finally {
            // Profiles are serialized trusted objects. Never load arbitrary caller-selected profiler files.
            (new Filesystem())->remove($directory);
        }
    }
}
