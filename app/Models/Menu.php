<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\MenuFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Menu extends Model
{
    use BelongsToTenant;

    /** @use HasFactory<MenuFactory> */
    use HasFactory;

    use SoftDeletes;

    protected $fillable = ['category_id', 'name', 'description', 'photo_path', 'base_price', 'stock_qty', 'is_available', 'prep_minutes'];

    protected function casts(): array
    {
        return [
            'base_price' => 'integer',
            'stock_qty' => 'integer',
            'is_available' => 'boolean',
            'prep_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<MenuCategory, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    /** @return BelongsToMany<ModifierGroup, $this> */
    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'menu_modifier_groups')
            ->withPivot(['tenant_id', 'sort_order'])->withTimestamps()->orderByPivot('sort_order');
    }

    /** @param Builder<Menu> $query @return Builder<Menu> */
    public function scopeSellable(Builder $query): Builder
    {
        return $query->where('is_available', true)->where('stock_qty', '>', 0)
            ->whereHas('tenant', fn (Builder $tenant) => $tenant->where('status', 'active')
                ->whereHas('canteen', fn (Builder $canteen) => $canteen->where('status', 'active')));
    }
}
