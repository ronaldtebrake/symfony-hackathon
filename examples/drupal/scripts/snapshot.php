<?php

declare(strict_types=1);

use Drupal\Core\Session\AnonymousUserSession;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;

// Independent operator-side oracle. It cannot be invoked from the guest.
$ids = \Drupal::state()->get('code_mode_demo.fixtures');
if (!is_array($ids)) {
  throw new RuntimeException('Run scripts/setup.sh first.');
}
$account = User::load($ids['account']);
$published = Node::load($ids['published']);
$restricted = Node::load($ids['restricted']);
if (!$account || !$published || !$restricted || $account->getAccountName() !== 'code_mode_reader') {
  throw new RuntimeException('The proof fixture is incomplete.');
}
$events = [];
$rows = \Drupal::database()->select('watchdog', 'w')->fields('w', ['wid', 'message', 'variables', 'timestamp'])
  ->condition('type', 'code_mode_demo')->orderBy('wid', 'DESC')->range(0, 100)->execute();
foreach ($rows as $row) {
  $values = unserialize($row->variables, ['allowed_classes' => FALSE]);
  $events[] = ['id' => (int) $row->wid, 'event' => $row->message,
    'account' => (int) ($values['@account'] ?? 0), 'node' => (int) ($values['@node'] ?? 0),
    'decision' => $values['@decision'] ?? NULL, 'timestamp' => (int) $row->timestamp];
}
$allPermissions = [];
foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple($account->getRoles()) as $role) {
  if ($role->isAdmin()) {
    throw new RuntimeException('The proof account must not have an administrator role.');
  }
  $allPermissions = array_merge($allPermissions, $role->getPermissions());
}
$allPermissions = array_values(array_unique($allPermissions));
sort($allPermissions);
echo json_encode([
  'drupalVersion' => \Drupal::VERSION,
  'account' => ['id' => (int) $account->id(), 'name' => $account->getAccountName(), 'active' => $account->isActive(), 'roles' => $account->getRoles(), 'permissions' => $allPermissions],
  'published' => ['id' => (int) $published->id(), 'title' => $published->label(), 'published' => $published->isPublished(), 'viewAllowed' => $published->access('view', $account)],
  'restricted' => ['id' => (int) $restricted->id(), 'exists' => TRUE, 'published' => $restricted->isPublished(), 'viewAllowed' => $restricted->access('view', $account), 'ownedByReader' => $restricted->getOwnerId() === $account->id()],
  'anonymousMcpAllowed' => (new AnonymousUserSession())->hasPermission('access mcp server'),
  'contentHash' => hash('sha256', json_encode([$published->toArray(), $restricted->toArray()], JSON_THROW_ON_ERROR)),
  'auditCursor' => (int) \Drupal::database()->select('watchdog', 'w')->fields('w', ['wid'])->orderBy('wid', 'DESC')->range(0, 1)->execute()->fetchField(),
  'events' => array_reverse($events),
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
