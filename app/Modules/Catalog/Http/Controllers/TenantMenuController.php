<?php

namespace App\Modules\Catalog\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Models\Tenant;
use App\Modules\Catalog\Services\MenuStockService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Example endpoint to demonstrate layered tenant isolation. */
final class TenantMenuController extends Controller
{
    use AuthorizesRequests;

    public function __construct(private MenuStockService $stock) {}

    public function index(Tenant $tenant): JsonResponse
    {
        $menus = Menu::query()
            ->orderBy('name')
            ->get(['id', 'name', 'base_price', 'is_available']);

        return response()->json(['tenant' => $tenant->slug, 'menus' => $menus]);
    }

    public function show(Tenant $tenant, Menu $menu): JsonResponse
    {
        $this->authorize('view', $menu);

        return response()->json($menu->only(['id', 'name', 'base_price', 'is_available']));
    }

    public function update(Request $request, Tenant $tenant, Menu $menu): JsonResponse
    {
        $this->authorize('update', $menu);
        $data = $request->validate(['is_available' => ['required', 'boolean']]);
        $this->stock->setAvailability($menu, (bool) $data['is_available']);

        return response()->json(['ok' => true, 'is_available' => (bool) $data['is_available']]);
    }
}
