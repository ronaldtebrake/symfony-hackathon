<?php

declare(strict_types=1);

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;

// Trusted operator script, never exposed as a tool. Repeatable without replacing data.
if (!NodeType::load('code_mode_article')) {
  NodeType::create(['type' => 'code_mode_article', 'name' => 'Code Mode article'])->save();
}
if (!Role::load('code_mode_reader')) {
  Role::create(['id' => 'code_mode_reader', 'label' => 'Code Mode reader', 'is_admin' => FALSE,
    'permissions' => ['access content', 'read code mode articles']])->save();
}
$accounts = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => 'code_mode_reader']);
$account = reset($accounts);
if (!$account) {
  $account = User::create(['name' => 'code_mode_reader', 'mail' => 'reader@example.invalid',
    'pass' => bin2hex(random_bytes(32)), 'status' => 1, 'roles' => ['code_mode_reader']]);
  $account->save();
}
$ids = [];
foreach ([
  'published' => ['uuid' => 'd468a760-2b3c-43cf-8726-7cf8686f0001', 'title' => 'Published article for the Code Mode proof', 'status' => 1],
  'restricted' => ['uuid' => 'd468a760-2b3c-43cf-8726-7cf8686f0002', 'title' => 'RESTRICTED-CONTENT-CANARY-DO-NOT-RETURN', 'status' => 0],
] as $key => $values) {
  $nodes = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $values['uuid']]);
  $node = reset($nodes);
  if (!$node) {
    $node = Node::create($values + ['type' => 'code_mode_article', 'uid' => 1]);
    $node->save();
  }
  $ids[$key] = (int) $node->id();
}
\Drupal::state()->set('code_mode_demo.fixtures', $ids + ['account' => (int) $account->id()]);
echo json_encode(['seeded' => TRUE, 'ids' => $ids, 'account' => (int) $account->id()], JSON_THROW_ON_ERROR) . "\n";
