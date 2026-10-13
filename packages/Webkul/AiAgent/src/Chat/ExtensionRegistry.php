<?php

namespace Webkul\AiAgent\Chat;

use Webkul\MagicAI\Models\MagicAIPlatform;

/**
 * Registration boundary for trusted, installed extensions.
 *
 * Providers, views and components come from service providers, never HTTP input.
 * Registration supplies integration points, not permission to access a resource;
 * each extension must authorize its requests. See CHAT_EXTENSIONS.md.
 */
class ExtensionRegistry
{
    public const COMPOSER_REFERENCES = 2;

    private array $providers = [];

    private array $composers = [];

    private array $capabilities = [];

    public function provider(int $id, callable $resolve, array $option): void
    {
        // Negative IDs cannot collide with persisted MagicAI platform IDs. Resolve
        // the platform on demand so connection state and credentials stay server-side.
        if ($id >= 0) {
            throw new \InvalidArgumentException('Extension provider IDs must be negative.');
        }
        $this->providers[$id] = ['resolve' => $resolve, 'option' => $option + ['id' => $id]];
    }

    public function resolve(int $id): ?MagicAIPlatform
    {
        return isset($this->providers[$id]) ? ($this->providers[$id]['resolve'])() : null;
    }

    public function options(): array
    {
        return array_values(array_map(fn ($entry) => $entry['option'], $this->providers));
    }

    public function composer(string $key, string $component, string $view): void
    {
        // The key also namespaces this composer's inert request context. It is not
        // a client-selectable view name or a substitute for server validation.
        $this->composers[$key] = compact('key', 'component', 'view');
    }

    public function composers(): array
    {
        return array_values($this->composers);
    }

    public function capability(string $key, array $presentation): void
    {
        $this->capabilities[$key] = $presentation;
    }

    public function capabilities(): array
    {
        return $this->capabilities;
    }
}
