<?php

use App\Models\Tenant;
use App\Modules\Catalog\Http\Controllers\TenantMenuController;
use App\Support\Routing\PortalRoutes;
use Illuminate\Support\Facades\Route;

PortalRoutes::tenant(function (): void {
    Route::get('/menu-manager', fn (Tenant $tenant) => view('catalog::tenant.menu-manager', ['tenant' => $tenant]))->name('menu-manager');
    Route::get('/menus', [TenantMenuController::class, 'index'])->name('menus.index');
    Route::get('/menus/{menu}', [TenantMenuController::class, 'show'])->name('menus.show');
    Route::patch('/menus/{menu}', [TenantMenuController::class, 'update'])->name('menus.update');
});
