<?php

declare(strict_types=1);

use App\Demo\CatalogScalingProof;

$root = dirname(__DIR__);
require $root.'/vendor/autoload.php';

$proof = CatalogScalingProof::payloads($root);
echo "Search: record_read_001\n";
printf("%-13s %12s %12s\n", 'Catalog tools', 'Advertised', 'Matches');
foreach ($proof['samples'] as $sample) {
    $match = json_decode($sample['selectedContract']['text'], true, flags: \JSON_THROW_ON_ERROR);
    if (['record_read_001'] !== array_column($match['tools'], 'name')) {
        throw new RuntimeException('The exact search did not return the expected tool.');
    }
    printf("%-13d %12d %12d\n", $sample['availableTools'], $sample['catalog']['advertisedTools'], $match['returned']);
}
