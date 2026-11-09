<?php

declare(strict_types=1);

namespace App\Demo\Performance;

/** Public projections available to the unchanged performance readers and policy. */
interface ReadDataset
{
    public function allowed(string $id): bool;
    public function requests(): array;
    public function profile(string $id): array;
    public function logs(string $id): array;
}
