<?php

namespace App\Support;

use App\Models\Organization;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class ConsoleProps
{
    /**
     * Shared props for the React console shell.
     *
     * @return array<string, mixed>
     */
    public static function base(?string $page = 'blade'): array
    {
        $user = Auth::user();
        if ($user) {
            $user->loadMissing(['roles', 'organization']);
        }
        $membership = $user?->organization;
        $brandOrganization = $membership ?: self::guestOrganization($user);
        $primary = $brandOrganization?->primary_color ?: '#000000';
        $consoleModules = app(ConsoleModules::class);
        $modules = $user ? $consoleModules->for($user) : [];

        return [
            'page' => $page,
            'csrf' => csrf_token(),
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'is_admin' => $user->isAdmin(),
                'roles' => $user->roles->pluck('name')->values()->all(),
            ] : null,
            'organization' => $membership ? [
                'id' => $membership->id,
                'name' => $membership->name,
                'description' => $membership->description,
            ] : null,
            'brand' => [
                'name' => 'Lumina GIS',
                'logoUrl' => $brandOrganization?->logo_path
                    ? asset('storage/'.$brandOrganization->logo_path)
                    : null,
                'primaryColor' => $primary,
            ],
            'flash' => [
                'status' => session('status'),
                'success' => session('success'),
                'error' => session('error'),
            ],
            'modules' => $modules,
            'activeModule' => $modules ? $consoleModules->activeKey($modules, request()->route()?->getName()) : null,
            'routes' => self::routes(),
            'abilities' => [
                'edit' => (bool) ($user && $user->hasAnyRole(['admin', 'editor'])),
                'admin' => (bool) ($user && $user->isAdmin()),
            ],
        ];
    }

    /**
     * Guests on a one-organization deploy see that organization's name.
     * A signed-in user without an organization does not borrow another one.
     */
    protected static function guestOrganization($user): ?Organization
    {
        if ($user || ! Schema::hasTable('organizations')) {
            return null;
        }

        $candidates = Organization::query()->orderBy('id')->limit(2)->get();

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    /**
     * @return array<string, string|null>
     */
    protected static function routes(): array
    {
        return [
            'dashboard' => route('dashboard'),
            'login' => route('login'),
            'register' => config('app.allow_registration') && Route::has('register')
                ? route('register')
                : null,
            'logout' => route('logout'),
            'projects' => Route::has('projects.index') ? route('projects.index') : null,
            'projectShow' => Route::has('projects.show') ? url('/projects/__ID__') : null,
            'imports' => Route::has('imports.index') ? route('imports.index') : null,
            'importsCreate' => Route::has('imports.create') ? route('imports.create') : null,
            'layers' => Route::has('layers.index') ? route('layers.index') : null,
            'layerShow' => Route::has('layers.show') ? url('/layers/__ID__') : null,
            'maps' => Route::has('maps.index') ? route('maps.index') : null,
            'mapShow' => Route::has('maps.show') ? url('/maps/__ID__') : null,
            'mapBuilder' => Route::has('maps.builder') ? route('maps.builder') : null,
            'apps' => Route::has('apps.index') ? route('apps.index') : null,
            'solutionPacks' => Route::has('solution-packs.index') ? route('solution-packs.index') : null,
            'catalog' => Route::has('catalog.index') ? route('catalog.index') : null,
            'forms' => Route::has('forms.index') ? route('forms.index') : null,
            'organization' => Route::has('organization.settings') ? route('organization.settings') : null,
            'users' => Route::has('users.index') ? route('users.index') : null,
            'extensions' => Route::has('extensions.index') ? route('extensions.index') : null,
        ];
    }
}
