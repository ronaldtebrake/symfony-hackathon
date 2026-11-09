<?php

declare(strict_types=1);

use Composition\{AuditDataset, Readers, ReadPolicy, LocalReader, MediaConnection, ProofCommand};
use CodeMode\Mcp\McpGateway;
use CodeMode\Provider\{McpProvider, ProviderRegistry};
use CodeMode\Symfony\Ai\ApprovedToolbox;
use CodeMode\Symfony\Provider\SymfonyAiProvider;
use Symfony\AI\Agent\Toolbox\Toolbox;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $container->extension('framework', ['secret'=>'%env(APP_SECRET)%', 'http_method_override'=>false]);
    $s = $container->services()->defaults()->autowire()->autoconfigure();
    foreach ([AuditDataset::class, Readers::class, ReadPolicy::class, LocalReader::class] as $class) { $s->set($class); }
    $s->set('app.toolbox', Toolbox::class)->args(['$tools'=>[service(Readers::class)], '$eventDispatcher'=>service('event_dispatcher')]);
    $s->set('app.approved', ApprovedToolbox::class)->args([service('app.toolbox'), ['cms_list_articles','cms_get_articles'], service(ReadPolicy::class)]);
    $s->set('app.catalog_provider', SymfonyAiProvider::class)->args(['catalog', service('app.approved'), ['listArticles'=>'cms_list_articles','getArticles'=>'cms_get_articles']]);
    $s->set(MediaConnection::class)->args(['%kernel.project_dir%', service(ReadPolicy::class)]);
    $s->set('app.media_gateway', McpGateway::class)->factory([service(MediaConnection::class), 'gateway']);
    $s->set('app.media_provider', McpProvider::class)->args(['media', service('app.media_gateway'), ['getMedia'=>'cms_get_media']]);
    $container->extension('code_mode', ['identity_provider'=>LocalReader::class, 'providers'=>['app.catalog_provider','app.media_provider'], 'wall_time_ms'=>15000, 'tool_time_ms'=>3000, 'max_tool_calls'=>30]);
    $s->set(ProviderRegistry::class)->args([[service('app.catalog_provider'), service('app.media_provider')]]);
    $s->set(ProofCommand::class)->arg('$projectDir', '%kernel.project_dir%')->arg('$docker', service('code_mode.docker'))->arg('$limits', service('code_mode.limits'))->arg('$image', '%env(CODE_MODE_IMAGE)%');
};
