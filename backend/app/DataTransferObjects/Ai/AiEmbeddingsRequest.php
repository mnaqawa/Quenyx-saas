<?php

namespace App\DataTransferObjects\Ai;

final readonly class AiEmbeddingsRequest
{
    /**
     * @param  list<string>  $inputs
     */
    public function __construct(
        public array $inputs,
        public ?string $model = null,
    ) {}
}
