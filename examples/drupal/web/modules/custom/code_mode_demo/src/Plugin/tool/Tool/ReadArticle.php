<?php

declare(strict_types=1);

namespace Drupal\code_mode_demo\Plugin\tool\Tool;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\node\NodeInterface;
use Drupal\tool\Attribute\Tool;
use Drupal\tool\ExecutableResult;
use Drupal\tool\FailureCategory;
use Drupal\tool\Tool\ToolBase;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\InputDefinition;
use Drupal\tool\TypedData\OutputDefinition;
use Symfony\Component\DependencyInjection\ContainerInterface;

#[Tool(
  id: 'code_mode_read_article',
  label: new TranslatableMarkup('Read a demo article'),
  description: new TranslatableMarkup('Return only the ID and title of an accessible demo article.'),
  operation: ToolOperation::Read,
  permission: 'read code mode articles',
  input_definitions: [
    'id' => new InputDefinition('integer', new TranslatableMarkup('Article ID'), new TranslatableMarkup('The numeric node ID.'), constraints: ['Range' => ['min' => 1, 'max' => 2147483647]]),
  ],
  output_definitions: [
    'id' => new OutputDefinition('integer', new TranslatableMarkup('ID'), new TranslatableMarkup('Article ID.')),
    'title' => new OutputDefinition('string', new TranslatableMarkup('Title'), new TranslatableMarkup('Article title.')),
  ],
)]
final class ReadArticle extends ToolBase {

  protected EntityStorageInterface $nodes;
  protected LoggerChannelInterface $audit;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $instance->nodes = $container->get('entity_type.manager')->getStorage('node');
    $instance->audit = $container->get('logger.factory')->get('code_mode_demo');
    return $instance;
  }

  protected function checkAccess(array $values, AccountInterface $account, bool $return_as_object = FALSE): bool|AccessResultInterface {
    $node = $this->nodes->load($values['id']);
    $access = $this->articleAccess($node, $account);
    // Log only IDs and decisions. Never log inaccessible titles or field values.
    $this->audit->notice('article_access', ['@account' => (int) $account->id(), '@node' => $values['id'], '@decision' => $access->isAllowed() ? 'allowed' : 'denied']);
    return $return_as_object ? $access : $access->isAllowed();
  }

  protected function doExecute(array $values): ExecutableResult {
    // Check again at the data read; callers must not be able to bypass access().
    $this->nodes->resetCache([$values['id']]);
    $node = $this->nodes->load($values['id']);
    if (!$this->currentUser->hasPermission('read code mode articles') || !$this->articleAccess($node, $this->currentUser)->isAllowed()) {
      return ExecutableResult::failure(new TranslatableMarkup('Article is unavailable to this account.'), category: FailureCategory::Access);
    }
    $this->audit->notice('article_read', ['@account' => (int) $this->currentUser->id(), '@node' => $values['id']]);
    return ExecutableResult::success(new TranslatableMarkup('Article read.'), ['id' => (int) $node->id(), 'title' => $node->label()]);
  }

  private function articleAccess(?NodeInterface $node, AccountInterface $account): AccessResultInterface {
    if (!$node || $node->bundle() !== 'code_mode_article') {
      return AccessResult::forbidden('Article is unavailable to this account.')->setCacheMaxAge(0);
    }
    $access = $node->access('view', $account, TRUE)->andIf($node->get('title')->access('view', $account, TRUE));
    // Use the same safe reason for missing, unpublished, and field-restricted data.
    return ($access->isAllowed() ? AccessResult::allowed() : AccessResult::forbidden('Article is unavailable to this account.'))
      ->addCacheableDependency($access)->addCacheableDependency($node)->cachePerUser();
  }

}
