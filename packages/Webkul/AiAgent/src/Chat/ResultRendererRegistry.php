<?php

namespace Webkul\AiAgent\Chat;

/** Only installed server code registers component/view names. Descriptors are inert references. */
class ResultRendererRegistry
{
    private array $renderers = [];

    public function register(string $type, string $component, string $view, ?string $consumesContext = null): void
    {
        // Clear a composer's selection only when its tool actually returns this
        // result type; a clarification reply must leave the selection available.
        if (! preg_match('/^[a-z][a-z0-9.-]{0,63}$/', $type)
            || ! preg_match('/^v-[a-z0-9-]+$/', $component)) {
            throw new \InvalidArgumentException('Invalid result renderer registration.');
        }
        $this->renderers[$type] = compact('component', 'view', 'consumesContext');
    }

    public function components(): array
    {
        return array_map(fn ($r) => $r['component'], $this->renderers);
    }

    public function contextConsumers(): array
    {
        return array_filter(array_map(fn ($r) => $r['consumesContext'], $this->renderers));
    }

    public function views(): array
    {
        return array_unique(array_column($this->renderers, 'view'));
    }

    public function descriptor(mixed $value): ?array
    {
        // Keep unknown but well-formed types in history so removing an extension
        // does not destroy its references. The widget uses its generic fallback.
        // An opaque ID grants no access: the renderer's endpoints must check ownership.
        if (! is_array($value) || count($value) !== 2
            || ! is_string($value['type'] ?? null) || ! is_string($value['id'] ?? null)
            || ! preg_match('/^[a-z][a-z0-9.-]{0,63}$/', $value['type'])
            || ! preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value['id'])) {
            return null;
        }

        return ['type' => $value['type'], 'id' => $value['id']];
    }
}
