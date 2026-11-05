<?php

namespace App\Modules\Catalog\Services;

use App\Models\Canteen;
use App\Models\Menu;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Explicit, reviewable cross-tenant query for one public canteen catalog. */
final class PublicCatalogQuery
{
    /** @return Collection<int, Menu> */
    public function forCanteen(Canteen $canteen): Collection
    {
        return $this->browseAll($canteen)->getCollection();
    }

    /** @return LengthAwarePaginator<int, Menu> */
    public function browseAll(Canteen $canteen, string $search = '', ?int $tenantId = null, ?string $category = null): LengthAwarePaginator
    {
        return Menu::query()->withoutGlobalScope('tenant')
            ->with(['tenant:id,canteen_id,display_name,status', 'category:id,name', 'modifierGroups.options'])
            ->whereHas('tenant', function (Builder $query) use ($canteen): void {
                $query->where('canteen_id', $canteen->getKey())->where('status', 'active')
                    ->whereHas('canteen', fn (Builder $q) => $q->where('status', 'active'));
            })
            ->when($tenantId, fn (Builder $query) => $query->where('tenant_id', $tenantId))
            ->when(trim($search) !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes(trim($search), '%_\\\\').'%';
                $query->where(function (Builder $q) use ($term): void {
                    $q->where('name', 'like', $term)->orWhere('description', 'like', $term)
                        ->orWhereHas('tenant', fn (Builder $t) => $t->where('display_name', 'like', $term))
                        ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', $term));
                });
            })
            ->when($category, fn (Builder $query) => $query->whereHas('category', fn (Builder $q) => $q->where('name', $category)))
            ->orderBy('tenant_id')->orderBy('name')->paginate(24)->withQueryString();
    }

    /** @return Collection<int, string> */
    public function categoryNames(Canteen $canteen): Collection
    {
        return Menu::query()->withoutGlobalScope('tenant')->whereHas('tenant', fn (Builder $q) => $q
            ->where('canteen_id', $canteen->id)->where('status', 'active'))
            ->whereHas('category', fn (Builder $q) => $q->where('is_active', true))
            ->join('menu_categories', function ($join): void {
                $join->on('menus.category_id', '=', 'menu_categories.id')->on('menus.tenant_id', '=', 'menu_categories.tenant_id');
            })->distinct()->orderBy('menu_categories.name')->pluck('menu_categories.name');
    }
}
