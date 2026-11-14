<?= "<?php\n" ?>

declare(strict_types=1);

namespace <?= $namespace ?>;

use <?= $voter_class ?>;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\AiBundle\Security\Attribute\IsGrantedTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('<?= $tool_name ?>', 'Read an approved record by integer ID.', effectHints: new ToolEffectHints(readOnlyHint: true),
    metadata: ['code_mode.output_schema' => ['type' => 'object']])]
#[IsGrantedTool(<?= $voter_short ?>::READ, subject: 'id')]
final class <?= $class_name."\n" ?>
{
    public function __invoke(int $id): array
    {
        // Inject your reader and keep resource/field checks at the data boundary.
        throw new \LogicException('Implement the approved read before enabling this tool.');
    }
}
