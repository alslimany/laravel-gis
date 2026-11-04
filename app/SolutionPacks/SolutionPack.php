<?php

namespace App\SolutionPacks;

abstract class SolutionPack
{
    /**
     * Stable identifier used by the CLI, routes, and install metadata.
     */
    abstract public function slug(): string;

    /**
     * Display name for the pack.
     */
    abstract public function name(): string;

    /**
     * One-line description shown in organization settings.
     */
    abstract public function description(): string;

    /**
     * Pack schema: layers with typed fields, parent/child relationships,
     * optional sample features seeded on first install, and optional
     * workspace artifacts (starter map, public form, dashboard).
     *
     * @return array{
     *     layers: list<array{
     *         name: string,
     *         description: string,
     *         geometry_type: string,
     *         style?: array{renderer: string, symbol?: array<string, mixed>},
     *         fields: list<array{
     *             name: string,
     *             alias?: string,
     *             type?: string,
     *             required?: bool,
     *             domain?: list<string>,
     *             calculated?: ?string,
     *         }>,
     *     }>,
     *     relationships: list<array{name: string, parent: string, child: string, foreign_key: string}>,
     *     features: list<array{
     *         layer: string,
     *         attributes: array<string, mixed>,
     *         wkt?: ?string,
     *         refs?: array<string, array{layer: string, name: string}>,
     *     }>,
     *     maps?: list<array{
     *         name: string,
     *         description?: string,
     *         basemap?: string,
     *         viewport?: array{center: array{float, float}, zoom: float|int, rotation?: float|int},
     *         layers: list<string>,
     *     }>,
     *     forms?: list<array{
     *         name: string,
     *         description?: string,
     *         layer: string,
     *         is_public?: bool,
     *         collect_geometry?: bool,
     *         schema: list<array<string, mixed>>,
     *     }>,
     *     dashboards?: list<array{
     *         name: string,
     *         description?: string,
     *         widgets: list<array<string, mixed>>,
     *     }>,
     *     workflow?: list<array{
     *         title: string,
     *         description?: string,
     *         kind: 'form'|'dashboard'|'map'|'text',
     *         target?: string,
     *     }>,
     * }
     */
    abstract public function definition(): array;

    /**
     * Declarative runbook steps describing how workspace artifacts chain into
     * a workflow. Steps are documentation, not executable automation: targets
     * are resolved by name at render time and disabled when missing.
     *
     * @return list<array{title: string, description?: string, kind: string, target?: string}>
     */
    public function workflow(): array
    {
        $workflow = $this->definition()['workflow'] ?? [];

        return is_array($workflow) ? array_values($workflow) : [];
    }

    /**
     * Human-readable list of what the pack contains, shown in settings.
     */
    public function contentsSummary(): string
    {
        $definition = $this->definition();

        $parts = [
            trans_choice('packs.count.layers', count($definition['layers'])),
            trans_choice('packs.count.relationships', count($definition['relationships'])),
        ];
        foreach (['maps', 'forms', 'dashboards'] as $key) {
            $count = count($definition[$key] ?? []);
            if ($count > 0) {
                $parts[] = trans_choice("packs.count.{$key}", $count);
            }
        }
        if (! empty($definition['features'])) {
            $parts[] = trans_choice('packs.count.features', count($definition['features']));
        }

        return implode(' · ', $parts);
    }

    /** Pack name for display: built-in packs have catalog translations, custom packs keep their own name. */
    public function displayName(): string
    {
        $key = 'packs.builtin.'.$this->slug().'.name';

        return __($key) !== $key ? __($key) : $this->name();
    }

    public function displayDescription(): string
    {
        $key = 'packs.builtin.'.$this->slug().'.description';

        return __($key) !== $key ? __($key) : $this->description();
    }

    /**
     * All registered solution packs.
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [new AecConstructionPack];
    }

    /**
     * Find a pack by slug.
     */
    public static function find(string $slug): ?self
    {
        foreach (self::all() as $pack) {
            if ($pack->slug() === $slug) {
                return $pack;
            }
        }

        return null;
    }
}
