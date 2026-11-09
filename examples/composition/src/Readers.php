<?php

declare(strict_types=1);

namespace Composition;

use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Platform\Tool\ToolEffectHints;

#[AsTool('cms_list_articles', 'List published article IDs and titles. Paginate with offset and limit, maximum 12. Returns items, total and next_offset.', method: 'listArticles', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::PAGE])]
#[AsTool('cms_get_articles', 'Read metadata for up to twelve published IDs. Drafts are denied. Empty meta_description needs review; join image_id with media id.', method: 'getArticles', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::ARTICLES])]
#[AsTool('cms_get_media', 'Read up to twelve public media records. Empty alt needs review. Join id to article image_id.', method: 'getMedia', effectHints: new ToolEffectHints(readOnlyHint: true), metadata: ['code_mode.output_schema' => self::MEDIA])]
final readonly class Readers
{
    private const ID_TITLE = ['type'=>'object','additionalProperties'=>false,'required'=>['id','title'],'properties'=>['id'=>['type'=>'integer'],'title'=>['type'=>'string']]];
    public const PAGE = ['type'=>'object','additionalProperties'=>false,'required'=>['items','total','next_offset'],'properties'=>[
        'items'=>['type'=>'array','items'=>self::ID_TITLE], 'total'=>['type'=>'integer'], 'next_offset'=>['type'=>['integer','null']]]];
    public const ARTICLES = ['type'=>'object','additionalProperties'=>false,'required'=>['items'],'properties'=>['items'=>['type'=>'array','items'=>[
        'type'=>'object','additionalProperties'=>false,'required'=>['id','title','status','language','path','updated','meta_description','image_id'],
        'properties'=>['id'=>['type'=>'integer'],'title'=>['type'=>'string'],'status'=>['type'=>'string'],'language'=>['type'=>'string'],'path'=>['type'=>'string'], 'updated'=>['type'=>'string'],'meta_description'=>['type'=>'string'],'image_id'=>['type'=>'integer']]]]]];
    public const MEDIA = ['type'=>'object','additionalProperties'=>false,'required'=>['items'],'properties'=>['items'=>['type'=>'array','items'=>[
        'type'=>'object','additionalProperties'=>false,'required'=>['id','alt','file_name','width','height'],
        'properties'=>['id'=>['type'=>'integer'],'alt'=>['type'=>'string'],'file_name'=>['type'=>'string'],'width'=>['type'=>'integer'],'height'=>['type'=>'integer']]]]]];
    public function __construct(private AuditDataset $data) {}
    public function listArticles(int $offset, int $limit): array
    {
        if ($offset < 0 || $offset > 1000 || $limit < 1 || $limit > 12) { throw new \InvalidArgumentException('Invalid page.'); }
        $articles = array_values(array_filter($this->data->articles(), fn ($a) => 'published' === $a['status']));
        $page = array_slice($articles, $offset, $limit);
        return ['items'=>array_map(fn ($a) => ['id'=>$a['id'],'title'=>$a['title']], $page), 'total'=>count($articles), 'next_offset'=>$offset+count($page)<count($articles) ? $offset+count($page) : null];
    }
    /** @param list<int> $ids */
    public function getArticles(array $ids): array
    {
        // Recheck resource visibility at the point of reading, including non-Code-Mode consumers.
        $articles = $this->data->articles(); $items = [];
        if ([] === $ids || count($ids)>12) { throw new \InvalidArgumentException('Invalid batch.'); }
        foreach ($ids as $id) {
            if (!is_int($id) || !isset($articles[$id]) || 'published' !== $articles[$id]['status']) { throw new \RuntimeException('Read denied.'); }
            $items[] = $articles[$id];
        }
        return ['items'=>$items];
    }
    /** @param list<int> $ids */
    public function getMedia(array $ids): array
    {
        $media = $this->data->media(); $items = [];
        if ([] === $ids || count($ids)>12) { throw new \InvalidArgumentException('Invalid batch.'); }
        foreach ($ids as $id) {
            if (!is_int($id) || !isset($media[$id])) { throw new \RuntimeException('Read denied.'); }
            $items[] = $media[$id];
        }
        return ['items'=>$items];
    }
}
