<?php

declare(strict_types=1);

use Drupal\Core\Config\FileStorage;
use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\menu_link_content\Entity\MenuLinkContent;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\path_alias\Entity\PathAlias;
use Drupal\views\Entity\View;

// Operator-only presentation setup. Tools and reader permissions are unchanged.
$fixture = \Drupal::state()->get('code_mode_demo.fixtures');
$type = NodeType::load('code_mode_article');
if (!$type || !is_array($fixture)) {
  throw new RuntimeException('Run the authorization fixture seed first.');
}
foreach ([
  'body' => ['type' => 'text_with_summary', 'label' => 'Body', 'settings' => ['display_summary' => TRUE, 'allowed_formats' => ['plain_text']]],
  'field_demo_topic' => ['type' => 'string', 'label' => 'Topic', 'settings' => []],
  'field_meta_description' => ['type' => 'string_long', 'label' => 'Editorial description', 'settings' => []],
] as $name => $definition) {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create(['field_name' => $name, 'entity_type' => 'node', 'type' => $definition['type']])->save();
  }
  if (!FieldConfig::loadByName('node', $type->id(), $name)) {
    FieldConfig::create(['field_name' => $name, 'entity_type' => 'node', 'bundle' => $type->id(),
      'label' => $definition['label'], 'settings' => $definition['settings']])->save();
  }
}
$type->setDisplaySubmitted(FALSE);
$type->save();
$displays = \Drupal::service('entity_display.repository');
$displays->getFormDisplay('node', $type->id())
  ->setComponent('body', ['type' => 'text_textarea_with_summary', 'weight' => 10])
  ->setComponent('field_demo_topic', ['type' => 'string_textfield', 'weight' => 5])
  ->setComponent('field_meta_description', ['type' => 'string_textarea', 'weight' => 15])->save();
foreach (['default', 'teaser'] as $mode) {
  $displays->getViewDisplay('node', $type->id(), $mode)->enable()
    ->setComponent('field_demo_topic', ['type' => 'string', 'label' => 'hidden', 'weight' => -5])
    ->setComponent('body', ['type' => $mode === 'teaser' ? 'text_summary_or_trimmed' : 'text_default', 'label' => 'hidden', 'weight' => 0])
    ->removeComponent('field_meta_description')->save();
}

$articles = json_decode(file_get_contents(dirname(__DIR__).'/content/journal.json'), TRUE, flags: JSON_THROW_ON_ERROR);
$manifest = [];
$created = 0;
foreach ($articles as $index => $article) {
  // Deterministic UUIDs prevent duplicates; existing editorial changes survive reruns.
  $uuid = '04ed9e60-573a-5d8f-95a1-'.substr(hash('sha256', $article['slug']), 0, 12);
  $matches = \Drupal::entityTypeManager()->getStorage('node')->loadByProperties(['uuid' => $uuid]);
  $node = reset($matches);
  if (!$node) {
    $node = Node::create([
      'uuid' => $uuid, 'type' => $type->id(), 'uid' => 1,
      'title' => $article['title'], 'status' => (int) $article['published'], 'promote' => 1,
      'created' => strtotime('2026-11-01T12:00:00+00:00') - $index * 86400,
      'body' => ['value' => $article['body'], 'summary' => $article['summary'], 'format' => 'plain_text'],
      'field_demo_topic' => $article['topic'], 'field_meta_description' => $article['description'],
    ]);
    $node->save();
    ++$created;
  }
  $path = '/node/'.$node->id();
  $alias = '/journal/'.$article['slug'];
  $aliases = \Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['path' => $path]);
  if (!$aliases && !\Drupal::entityTypeManager()->getStorage('path_alias')->loadByProperties(['alias' => $alias])) {
    PathAlias::create(['path' => $path, 'alias' => $alias, 'langcode' => 'en'])->save();
  }
  $manifest[] = ['id' => (int) $node->id(), 'path' => $path, 'alias' => $alias, 'published' => $node->isPublished()];
}
\Drupal::state()->set('code_mode_demo.journal', $manifest);

// Start with core's published-content view, retaining its access checks and cache contexts.
$view = (new FileStorage(DRUPAL_ROOT.'/core/modules/node/config/optional'))->read('views.view.frontpage');
$view['id'] = 'code_mode_journal';
$view['label'] = 'Code Mode journal';
$view['description'] = 'Public sample articles for the Symfony Code Mode showcase.';
unset($view['display']['feed_1']);
$options = &$view['display']['default']['display_options'];
$options['title'] = 'Field notes';
$options['pager']['options']['items_per_page'] = 6;
$options['empty'] = [];
$options['header']['area_text_custom'] = [
  'id' => 'area_text_custom', 'table' => 'views', 'field' => 'area_text_custom', 'plugin_id' => 'text_custom',
  'empty' => FALSE, 'content' => '<p>A demo journal about useful software, thoughtful content and practical experiments.</p>', 'tokenize' => FALSE,
];
$options['filters']['type'] = ['id' => 'type', 'table' => 'node_field_data', 'field' => 'type',
  'entity_type' => 'node', 'entity_field' => 'type', 'plugin_id' => 'bundle', 'operator' => 'in',
  'value' => ['code_mode_article' => 'code_mode_article'], 'group' => 1, 'exposed' => FALSE];
// Leave the original authorization nodes untouched and out of the editorial listing.
$options['filters']['nid'] = ['id' => 'nid', 'table' => 'node_field_data', 'field' => 'nid',
  'entity_type' => 'node', 'entity_field' => 'nid', 'plugin_id' => 'numeric', 'operator' => '!=',
  'value' => ['value' => (string) $fixture['published']], 'group' => 1, 'exposed' => FALSE];
$view['display']['page_1']['display_options']['path'] = 'journal';
$existing = View::load('code_mode_journal');
if ($existing) {
  $existing->set('display', $view['display'])->save();
} else {
  View::create($view)->save();
}

\Drupal::configFactory()->getEditable('system.theme')->set('default', 'olivero')->set('admin', 'claro')->save();
\Drupal::configFactory()->getEditable('node.settings')->set('use_admin_theme', TRUE)->save();
\Drupal::configFactory()->getEditable('system.site')->set('name', 'Symfony Code Mode')->set('slogan', 'The demo journal')->set('page.front', '/journal')->save();
\Drupal::configFactory()->getEditable('olivero.settings')->set('base_primary_color', '#7f5bff')->set('site_branding_bg_color', 'default')->save();
$accountMenu = \Drupal\block\Entity\Block::load('olivero_account_menu');
$accountMenu?->disable()->save();
foreach ([
  ['uuid' => '04ed9e60-573a-5d8f-95a1-000000000001', 'title' => 'Journal', 'uri' => 'internal:/journal', 'weight' => 0],
  ['uuid' => '04ed9e60-573a-5d8f-95a1-000000000002', 'title' => 'Symfony proof', 'uri' => 'http://127.0.0.1:8000/demo', 'weight' => 1],
] as $link) {
  if (!\Drupal::entityTypeManager()->getStorage('menu_link_content')->loadByProperties(['uuid' => $link['uuid']])) {
    MenuLinkContent::create(['uuid' => $link['uuid'], 'title' => $link['title'], 'link' => ['uri' => $link['uri']],
      'menu_name' => 'main', 'weight' => $link['weight'], 'enabled' => TRUE])->save();
  }
}
echo json_encode(['created' => $created, 'journalArticles' => count($manifest),
  'published' => count(array_filter($manifest, static fn (array $item): bool => $item['published'])),
  'drafts' => count(array_filter($manifest, static fn (array $item): bool => !$item['published'])),
  'theme' => 'olivero', 'frontPage' => '/journal'], JSON_THROW_ON_ERROR) . "\n";
