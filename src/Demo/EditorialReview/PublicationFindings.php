<?php

declare(strict_types=1);

namespace App\Demo\EditorialReview;

use App\Demo\FixtureServer;
use CodeMode\ExecutionIdentity;
use CodeMode\Symfony\Http\CatalogStateStore;
use CodeMode\Symfony\Http\SecurityIdentityProvider;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

/** Application-owned report. Its narrow grant is never passed to a public raw reader. */
#[AsTool(self::NAME, 'Find published articles with missing metadata or hero-image alt text. Returns issue codes, affected public article IDs, and a shared image ID only for missing alt. You can group those findings locally.', effectHints: new ToolEffectHints(readOnlyHint: true))]
final class PublicationFindings
{
    public const NAME = 'audit_publication_metadata';
    private const BATCH_SIZE = 12;
    private const MAX_ARTICLES = 48;
    private const SOURCE_READ_LIMIT = 20;
    private array $reads = [];

    public function __construct(private readonly SecurityIdentityProvider $identities, private readonly Access $access, private readonly CatalogStateStore $states, private readonly FixtureServer $records = new FixtureServer())
    {
    }

    public function __invoke(): array
    {
        $this->reads = [];
        $identity = $this->identities->identity();
        $articles = $mediaIds = [];
        $offset = 0;
        do {
            $page = $this->read($identity, 'list_published', fn () => $this->records->listArticles($offset, self::BATCH_SIZE));
            if ($page['total'] > self::MAX_ARTICLES) {
                throw new \RuntimeException('The publication exceeds this bounded report.');
            }
            $ids = array_column($page['items'], 'id');
            if ($ids) {
                $records = $this->read($identity, 'published_article_metadata', fn () => $this->records->getArticles($ids));
                foreach ($records['items'] as $row) {
                    $articles[$row['id']] = ['image' => $row['image_id'], 'missingDescription' => '' === trim($row['meta_description'])];
                    $mediaIds[$row['image_id']] = true;
                }
            }
            $offset = $page['next_offset'];
        } while (null !== $offset);
        $missingAlt = [];
        foreach (array_chunk(array_keys($mediaIds), self::BATCH_SIZE) as $ids) {
            $records = $this->read($identity, 'referenced_public_media', fn () => $this->records->getMedia($ids));
            foreach ($records['items'] as $row) {
                $missingAlt[$row['id']] = '' === trim($row['alt']);
            }
        }

        $findings = [];
        foreach ($articles as $id => $article) {
            $issues = $article['missingDescription'] ? ['missing_description'] : [];
            if ($missingAlt[$article['image']]) {
                $issues[] = 'missing_alt';
            }
            if ($issues) {
                $finding = ['article_id' => $id, 'issues' => $issues];
                if ($missingAlt[$article['image']]) {
                    $finding['image_id'] = $article['image'];
                }
                $findings[] = $finding;
            }
        }
        $this->access->assertFindings($identity);

        return ['audited' => \count($articles), 'findings' => $findings];
    }

    public function sourceReads(): array
    {
        return $this->reads;
    }

    public function resetTrace(): void
    {
        $this->reads = [];
    }

    private function read(ExecutionIdentity $identity, string $operation, \Closure $query): array
    {
        // Charge the underlying work even if the query fails or a later grant check changes.
        return $this->states->withState('findings-source/'.$identity->subject, function (array $state, \Closure $save) use ($identity, $operation, $query) {
            $this->access->assertFindings($identity);
            $used = (int) ($state['reads'] ?? 0);
            if ($used >= self::SOURCE_READ_LIMIT) {
                throw new \RuntimeException('The reviewer source-read budget is exhausted.');
            }
            $save(['reads' => ++$used]);
            $value = $query()->structuredContent;
            $this->reads[] = ['operation' => $operation, 'permission' => 'publication.findings', 'rows' => \count($value['items']), 'reservedReads' => $used];

            return $value;
        });
    }
}
