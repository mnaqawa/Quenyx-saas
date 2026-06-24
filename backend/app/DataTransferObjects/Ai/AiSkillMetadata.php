<?php

namespace App\DataTransferObjects\Ai;

/**
 * Self-description of a skill for discovery: key, display name, description, the context types
 * it can produce, a version, and free-form tags.
 */
final readonly class AiSkillMetadata
{
    /**
     * @param  list<string>  $supportedContextTypes
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $key,
        public string $displayName,
        public string $description,
        public array $supportedContextTypes,
        public string $version = '1.0',
        public array $tags = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'supported_context_types' => $this->supportedContextTypes,
            'version' => $this->version,
            'tags' => $this->tags,
        ];
    }
}
