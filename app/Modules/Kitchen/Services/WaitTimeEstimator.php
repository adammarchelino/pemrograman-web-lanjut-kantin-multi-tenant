<?php

namespace App\Modules\Kitchen\Services;

use App\Models\Menu;
use App\Models\Tenant;

final class WaitTimeEstimator
{
    private const PARALLEL_CAPACITY = 2;

    /** @return array{low:int, high:int} */
    public function estimate(Tenant $tenant): array
    {
        $prep = (int) Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)
            ->whereNull('deleted_at')->avg('prep_minutes');
        $prep = max(1, $prep);
        $queue = (int) $tenant->tenantOrders()->whereIn('status', ['accepted', 'preparing'])
            ->with('items:id,tenant_order_id,prep_minutes_snapshot,quantity')
            ->get()->sum(fn ($order) => $order->items->sum(fn ($item) => $item->prep_minutes_snapshot * $item->quantity));
        $minutes = (int) ceil($queue / self::PARALLEL_CAPACITY) + $prep;

        return ['low' => $minutes, 'high' => (int) ceil($minutes * 1.5)];
    }

    public function label(Tenant $tenant): string
    {
        $estimate = $this->estimate($tenant);

        return sprintf('Antrean ± %d–%d mnt', $estimate['low'], $estimate['high']);
    }
}
