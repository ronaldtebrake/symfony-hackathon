<?php

/** Operator-only fixture controls. Never exposed through MCP or Code Mode. */

use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;
use Drupal\mcp_server_tool_bridge\Entity\McpToolConfig;

$action = getenv('SURFACE_PROOF_ACTION') ?: 'snapshot';
if (!in_array($action, ['setup', 'snapshot', 'direct', 'revoke', 'restore'], TRUE)) {
  throw new InvalidArgumentException('Unknown fixture action.');
}
$state = \Drupal::state();
$role_id = 'code_mode_surface_reader';
$permissions = ['access content', 'access webmcp surface demo'];
$definition = [
  'orchard'=>['SCMQ orchard guide', 1, 'a20d7ff8-640c-4911-a234-000000000001'],
  'garden'=>['SCMQ garden guide', 1, 'a20d7ff8-640c-4911-a234-000000000002'],
  'shared'=>['SCMQ orchard + SCMQ garden notes', 1, 'a20d7ff8-640c-4911-a234-000000000003'],
  'draft'=>['SCMQ orchard PRIVATE_DRAFT_CANARY', 0, 'a20d7ff8-640c-4911-a234-000000000004'],
  'field_denied'=>['SCMQ orchard PRIVATE_FIELD_CANARY', 1, 'a20d7ff8-640c-4911-a234-000000000005'],
];
if ('setup' === $action) {
  if (!$state->get('code_mode_surface_proof.fixtures') && (Role::load($role_id) || user_load_by_name($role_id) || NodeType::load('code_mode_surface_proof') || McpToolConfig::load('code_mode_surface_search'))) {
    throw new RuntimeException('A fixture identifier already exists outside this proof.');
  }
  if (!$state->get('code_mode_surface_proof.fixtures')) {
    Role::create(['id'=>$role_id,'label'=>'Code Mode surface proof reader','permissions'=>$permissions])->save();
    $user = User::create(['name'=>$role_id,'mail'=>'surface-proof@example.invalid','status'=>1,'roles'=>[$role_id],'pass'=>bin2hex(random_bytes(32))]); $user->save();
    NodeType::create(['type'=>'code_mode_surface_proof','name'=>'Code Mode query proof'])->save();
    $ids = [];
    foreach ($definition as $key => [$title,$status,$uuid]) {
      $node = Node::create(['type'=>'code_mode_surface_proof','title'=>$title,'status'=>$status,'uuid'=>$uuid,'uid'=>0]); $node->save(); $ids[$key] = (int) $node->id();
    }
    McpToolConfig::create(['id'=>'code_mode_surface_search','tool_id'=>'webmcp_surface_search:content','description'=>NULL,'status'=>TRUE])->save();
    $state->set('code_mode_surface_proof.fixtures', ['account'=>(int)$user->id(),'nodes'=>$ids]);
    \Drupal::service('plugin.manager.mcp_server.tool')->clearCachedDefinitions();
  }
}
$fixture = $state->get('code_mode_surface_proof.fixtures');
if (!is_array($fixture)) { throw new RuntimeException('Initialize the separate query fixtures first.'); }
$user = User::load($fixture['account']); $role = Role::load($role_id);
if (!$user || !$role || $role->isAdmin() || $user->getAccountName() !== $role_id || !$user->isActive() || (int)$user->id() === 1) { throw new RuntimeException('Unexpected fixture identity.'); }
if (in_array($action, ['revoke','restore'], TRUE)) {
  $expected = 'revoke' === $action ? $permissions : ['access content'];
  $actual = $role->getPermissions(); sort($actual); sort($expected);
  if ($actual !== $expected) { throw new RuntimeException('Refusing to change a drifted fixture role.'); }
  if ('revoke' === $action) { $role->revokePermission('access webmcp surface demo'); }
  else { $role->grantPermission('access webmcp surface demo'); }
  $role->save();
}
$all_permissions = []; $node_data = []; $nodes = []; $expected = [];
foreach (\Drupal::entityTypeManager()->getStorage('user_role')->loadMultiple($user->getRoles()) as $user_role) {
  if ($user_role->isAdmin()) { throw new RuntimeException('Administrator fixture identity is forbidden.'); }
  $all_permissions = array_merge($all_permissions, $user_role->getPermissions());
}
$all_permissions = array_values(array_unique($all_permissions)); sort($all_permissions);
foreach ($fixture['nodes'] as $key => $id) {
  $node = Node::load($id);
  if (!$node || $node->uuid() !== $definition[$key][2] || $node->bundle() !== 'code_mode_surface_proof') { throw new RuntimeException('Fixture content changed.'); }
  $node_data[] = $node->toArray();
  $nodes[$key] = ['id'=>$id,'published'=>$node->isPublished(),'entityAllowed'=>$node->access('view',$user),'idAllowed'=>$node->get('nid')->access('view',$user),'titleAllowed'=>$node->get('title')->access('view',$user)];
  if (in_array($key, ['orchard','garden','shared'], TRUE)) { $expected[$key] = ['id'=>$id,'title'=>$node->label(),'url'=>$node->toUrl()->toString()]; }
}
$proxy = \Drupal::currentUser(); $before = $proxy->getAccount();
$direct = [];
try {
  $proxy->setAccount($user);
  $runner = \Drupal::service('webmcp_surface_query.runner'); $query = \Drupal::service('webmcp_surface_demo.content_query');
  $allowed = $runner->access($query)->isAllowed();
  if ('direct' === $action) {
    foreach (['orchard'=>'SCMQ orchard','garden'=>'SCMQ garden','empty'=>'SCMQ no-such-result','draft'=>'PRIVATE_DRAFT_CANARY','field_denied'=>'PRIVATE_FIELD_CANARY'] as $key => $keywords) {
      $direct[$key] = $runner->execute($query, ['keywords'=>$keywords,'limit'=>10]);
    }
  }
} finally { $proxy->setAccount($before); }
$bridge = McpToolConfig::load('code_mode_surface_search');
$sources = [];
foreach ([
  \Drupal\webmcp_integration_surface_query\QueryRunner::class,
  \Drupal\webmcp_integration_surface_query\OutputPresence::class,
  \Drupal\webmcp_integration_surface_demo\Query\PublishedContentQuery::class,
  \Drupal\webmcp_integration_surface_demo\Surface\SearchSurface::class,
] as $class) {
  $sources[$class] = hash_file('sha256', (new ReflectionClass($class))->getFileName());
}
$sources['field-access-fixture'] = hash_file('sha256', __DIR__.'/code_mode_surface_proof.module');
$sources['view-config'] = hash('sha256', json_encode(\Drupal::config('views.view.webmcp_surface_search')->getRawData(), JSON_THROW_ON_ERROR));
$versions = ['drupal/core' => \Drupal::VERSION, 'php' => PHP_VERSION];
foreach (['drupal/data_surface', 'drupal/tool', 'drupal/mcp_server', 'drupal/mcp_server_tool_bridge'] as $package) {
  $versions[$package] = \Composer\InstalledVersions::isInstalled($package)
    ? [\Composer\InstalledVersions::getPrettyVersion($package), \Composer\InstalledVersions::getReference($package)]
    : 'local checkout';
}
echo json_encode(['account'=>['id'=>(int)$user->id(),'name'=>$user->getAccountName(),'permissions'=>$all_permissions], 'queryAllowed'=>$allowed,
  'bridgeValid'=>$bridge && $bridge->status() && $bridge->get('tool_id') === 'webmcp_surface_search:content',
  'nodes'=>$nodes,'expected'=>$expected,'contentHash'=>hash('sha256',json_encode($node_data,JSON_THROW_ON_ERROR)), 'sources'=>$sources, 'versions'=>$versions, 'direct'=>$direct], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
