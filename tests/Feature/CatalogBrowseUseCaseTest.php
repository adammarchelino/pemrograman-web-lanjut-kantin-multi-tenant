<?php

namespace Tests\Feature;

use App\Models\Canteen;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Tenant;
use App\Modules\Catalog\Services\PublicCatalogQuery;
use App\Modules\Kitchen\Services\WaitTimeEstimator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CatalogBrowseUseCaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_catalog_searches_and_never_shows_another_canteen(): void
    {
        $canteen = Canteen::factory()->create(['status' => 'active']);
        $tenant = Tenant::factory()->create(['canteen_id' => $canteen->id, 'display_name' => 'Warung Rina']);
        $category = MenuCategory::factory()->create(['tenant_id' => $tenant->id, 'name' => 'Makanan']);
        Menu::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'name' => 'Nasi Ayam']);
        $otherCanteen = Canteen::factory()->create(['status' => 'active']);
        $otherTenant = Tenant::factory()->create(['canteen_id' => $otherCanteen->id]);
        $otherCategory = MenuCategory::factory()->create(['tenant_id' => $otherTenant->id]);
        Menu::factory()->create(['tenant_id' => $otherTenant->id, 'category_id' => $otherCategory->id, 'name' => 'Menu Rahasia']);

        Livewire::test('catalog::menu-catalog', ['canteenSlug' => $canteen->slug])
            ->assertSee('Warung Rina')->assertSee('Nasi Ayam')->assertDontSee('Menu Rahasia')
            ->set('search', 'Warung')->assertSee('Nasi Ayam')->assertDontSee('Menu Rahasia');

        $this->get(route('customer.home', ['canteen' => $canteen->slug]))->assertOk()->assertSee('Nasi Ayam');
    }

    public function test_public_query_eager_loads_category_and_sellable_menu_requires_stock(): void
    {
        $canteen = Canteen::factory()->create(['status' => 'active']);
        $tenant = Tenant::factory()->create(['canteen_id' => $canteen->id]);
        $category = MenuCategory::factory()->create(['tenant_id' => $tenant->id]);
        Menu::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id, 'stock_qty' => 0, 'prep_minutes' => 10]);

        $menus = app(PublicCatalogQuery::class)->browseAll($canteen);
        $this->assertCount(1, $menus);
        $this->assertSame($category->name, $menus->first()->category->name);
        $this->assertSame(0, Menu::query()->where('tenant_id', $tenant->id)->sellable()->count());
        $this->assertSame(['low' => 10, 'high' => 15], app(WaitTimeEstimator::class)->estimate($tenant));
    }
}
