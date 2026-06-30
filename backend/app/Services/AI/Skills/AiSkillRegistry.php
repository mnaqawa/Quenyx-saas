<?php

namespace App\Services\AI\Skills;

use App\Contracts\Ai\AiSkillInterface;
use App\Exceptions\Ai\AiSkillException;

/**
 * Registry of AI Skills. Supports registration (config-driven + manual), discovery, lookup,
 * enable/disable, priority ordering, and feature flags. Contains NO business logic — it only
 * manages the catalog of skills the router can choose from.
 */
class AiSkillRegistry
{
    /** @var array<string, AiSkillInterface> */
    private array $skills = [];

    /** @var array<string, int> */
    private array $priorities = [];

    /** @var array<string, bool> */
    private array $configEnabled = [];

    /** @var array<string, true> */
    private array $runtimeDisabled = [];

    private bool $booted = false;

    public function register(AiSkillInterface $skill, int $priority = 100, bool $enabled = true): void
    {
        $this->skills[$skill->key()] = $skill;
        $this->priorities[$skill->key()] = $priority;
        $this->configEnabled[$skill->key()] = $enabled;
    }

    /**
     * All registered skills, highest priority first.
     *
     * @return list<AiSkillInterface>
     */
    public function all(): array
    {
        $this->boot();

        $skills = array_values($this->skills);
        usort($skills, fn (AiSkillInterface $a, AiSkillInterface $b) => $this->priorities[$b->key()] <=> $this->priorities[$a->key()]);

        return $skills;
    }

    /**
     * Enabled skills only (feature flags + runtime state), highest priority first.
     *
     * @return list<AiSkillInterface>
     */
    public function enabled(): array
    {
        return array_values(array_filter($this->all(), fn (AiSkillInterface $s) => $this->isEnabled($s->key())));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(fn (AiSkillInterface $s) => $s->key(), $this->all());
    }

    public function has(string $key): bool
    {
        $this->boot();

        return isset($this->skills[$key]);
    }

    public function get(string $key): AiSkillInterface
    {
        $this->boot();

        if (! isset($this->skills[$key])) {
            throw new AiSkillException("Unknown AI skill: {$key}.", 'ai_skill_unknown');
        }

        return $this->skills[$key];
    }

    public function isEnabled(string $key): bool
    {
        $this->boot();

        if (! isset($this->skills[$key]) || isset($this->runtimeDisabled[$key])) {
            return false;
        }

        if (! (bool) config('ai.skills.enabled', true)) {
            return false;
        }

        return $this->configEnabled[$key] ?? true;
    }

    public function enable(string $key): void
    {
        unset($this->runtimeDisabled[$key]);
    }

    public function disable(string $key): void
    {
        $this->runtimeDisabled[$key] = true;
    }

    public function priority(string $key): int
    {
        $this->boot();

        return $this->priorities[$key] ?? 100;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return array_map(fn (AiSkillInterface $s) => array_merge(
            $s->metadata()->toArray(),
            ['priority' => $this->priority($s->key()), 'enabled' => $this->isEnabled($s->key())],
        ), $this->all());
    }

    /**
     * Populate the registry from config on first use. Idempotent.
     */
    private function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        foreach ((array) config('ai.skills.registered', []) as $key => $definition) {
            $class = is_array($definition) ? ($definition['class'] ?? null) : $definition;
            if ($class === null || ! class_exists($class) || isset($this->skills[$key])) {
                continue;
            }

            $skill = new $class();
            if (! $skill instanceof AiSkillInterface) {
                continue;
            }

            $this->skills[$skill->key()] = $skill;
            $this->priorities[$skill->key()] = is_array($definition) ? (int) ($definition['priority'] ?? 100) : 100;
            $this->configEnabled[$skill->key()] = is_array($definition) ? (bool) ($definition['enabled'] ?? true) : true;
        }
    }
}
