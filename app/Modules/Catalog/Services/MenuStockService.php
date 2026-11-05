<?php

namespace App\Modules\Catalog\Services;

use App\Models\Menu;
use App\Modules\Admin\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MenuStockService
{
    public function __construct(private AuditLogger $audit) {}

    public function toggleAvailability(Menu $menu): void
    {
        DB::transaction(function () use ($menu): void {
            $locked = Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $menu->tenant_id)->lockForUpdate()->findOrFail($menu->id);
            $this->saveAvailability($locked, ! $locked->is_available, 'availability_toggled');
        });
    }

    public function setAvailability(Menu $menu, bool $available): void
    {
        DB::transaction(function () use ($menu, $available): void {
            $locked = Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $menu->tenant_id)->lockForUpdate()->findOrFail($menu->id);
            if ($locked->is_available !== $available) {
                $this->saveAvailability($locked, $available, 'availability_changed');
            }
        });
    }

    public function adjust(Menu $menu, int $newQuantity): void
    {
        if ($newQuantity < 0) {
            throw new \InvalidArgumentException('Stok tidak boleh negatif.');
        }
        DB::transaction(function () use ($menu, $newQuantity): void {
            $locked = Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $menu->tenant_id)->lockForUpdate()->findOrFail($menu->id);
            $delta = $newQuantity - $locked->stock_qty;
            if ($delta === 0) {
                return;
            }
            $before = ['stock_qty' => $locked->stock_qty];
            $locked->stock_qty = $newQuantity;
            $locked->save();
            DB::table('menu_stock_movements')->insert([
                'tenant_id' => $locked->tenant_id, 'menu_id' => $locked->id,
                'idempotency_key' => (string) Str::uuid(), 'type' => 'adjustment', 'quantity_delta' => $delta,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('menu', $locked->id, 'stock_adjusted', $before,
                ['stock_qty' => $newQuantity, 'quantity_delta' => $delta], $locked->tenant_id, $locked->tenant->canteen_id);
        });
    }

    private function saveAvailability(Menu $menu, bool $available, string $action): void
    {
        $before = ['is_available' => $menu->is_available];
        $menu->is_available = $available;
        $menu->save();
        $this->audit->record('menu', $menu->id, $action, $before,
            ['is_available' => $menu->is_available], $menu->tenant_id, $menu->tenant->canteen_id);
    }
}
