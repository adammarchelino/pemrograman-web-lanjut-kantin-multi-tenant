<?php

namespace Tests\Feature;

use App\Models\Canteen;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserTenantRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MenuManagerUseCaseTest extends TestCase
{
    use RefreshDatabase;

    private function tenantMember(): array
    {
        $tenant = Tenant::factory()->create(['canteen_id' => Canteen::factory()->create()->id]);
        $user = User::factory()->create(['role' => 'tenant', 'status' => 'active', 'email_verified_at' => now()]);
        UserTenantRole::create(['user_id' => $user->id, 'tenant_id' => $tenant->id, 'role' => 'operator']);
        $category = MenuCategory::factory()->create(['tenant_id' => $tenant->id]);

        return [$tenant, $user, $category];
    }

    public function test_menu_manager_creates_and_toggles_only_the_active_tenant_menu(): void
    {
        [$tenant, $user, $category] = $this->tenantMember();
        $other = Tenant::factory()->create(['canteen_id' => Canteen::factory()->create()->id]);
        $otherCategory = MenuCategory::factory()->create(['tenant_id' => $other->id]);
        $foreign = Menu::factory()->create(['tenant_id' => $other->id, 'category_id' => $otherCategory->id]);

        $component = Livewire::actingAs($user)->test('catalog::menu-manager', ['tenantId' => $tenant->id])
            ->set('name', 'Nasi Ayam')->set('description', 'Ayam bakar')
            ->set('categoryId', $category->id)->set('basePrice', 18000)
            ->set('prepMinutes', 8)->set('stockQty', 5)->call('saveMenu')
            ->assertHasNoErrors()->assertSee('Nasi Ayam');

        $menu = Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'Nasi Ayam')->firstOrFail();
        $component->call('toggle', $menu->id)->assertSee('HABIS');
        $this->assertFalse($menu->fresh()->is_available);
        $this->assertDatabaseHas('menu_stock_movements', ['tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'quantity_delta' => 5]);
        $this->assertDatabaseHas('audit_logs', ['tenant_id' => $tenant->id, 'entity' => 'menu', 'action' => 'availability_toggled']);
        $component->call('deleteMenu', $menu->id);
        $this->assertSoftDeleted('menus', ['id' => $menu->id]);
        $this->get(route('tenant.menus.show', ['tenant' => $tenant->slug, 'menu' => $foreign->id]))->assertNotFound();
    }

    public function test_modifier_group_rules_are_validated_and_group_stays_tenant_scoped(): void
    {
        [$tenant, $user, $category] = $this->tenantMember();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);

        Livewire::actingAs($user)->test('catalog::menu-manager', ['tenantId' => $tenant->id])
            ->set('groupName', 'Level pedas')->set('minSelect', 2)->set('maxSelect', 1)
            ->set('optionName', 'Sedang')->set('optionPrice', 0)->set('attachMenuId', $menu->id)
            ->call('addModifier')->assertHasErrors('maxSelect');

        $this->assertDatabaseMissing('modifier_groups', ['tenant_id' => $tenant->id, 'name' => 'Level pedas']);
    }

    public function test_modifier_options_can_be_added_and_updated(): void
    {
        [$tenant, $user, $category] = $this->tenantMember();
        $menu = Menu::factory()->create(['tenant_id' => $tenant->id, 'category_id' => $category->id]);
        $component = Livewire::actingAs($user)->test('catalog::menu-manager', ['tenantId' => $tenant->id])
            ->set('groupName', 'Level pedas')->set('minSelect', 0)->set('maxSelect', 2)
            ->set('optionName', 'Sedang')->set('optionPrice', 0)->set('attachMenuId', $menu->id)
            ->call('addModifier')->assertHasNoErrors();

        $groupId = (int) \DB::table('modifier_groups')->where('tenant_id', $tenant->id)->value('id');
        $optionId = (int) \DB::table('modifier_options')->where('tenant_id', $tenant->id)->value('id');
        $this->assertDatabaseHas('menu_modifier_groups', ['tenant_id' => $tenant->id, 'menu_id' => $menu->id, 'modifier_group_id' => $groupId]);
        $component->set('optionName', 'Pedas')->set('optionPrice', 2000)->call('addOption', $groupId)->assertHasNoErrors();
        $component->call('editOption', $groupId, $optionId)->set('optionName', 'Tidak pedas')->set('optionPrice', 500)->call('addOption', $groupId);
        $this->assertDatabaseHas('modifier_options', ['id' => $optionId, 'name' => 'Tidak pedas', 'price_delta' => 500]);
    }

    public function test_photo_is_converted_to_webp_with_a_maximum_800_pixel_side(): void
    {
        Storage::fake('public');
        [$tenant, $user, $category] = $this->tenantMember();

        Livewire::actingAs($user)->test('catalog::menu-manager', ['tenantId' => $tenant->id])
            ->set('name', 'Foto Menu')->set('categoryId', $category->id)->set('basePrice', 12000)
            ->set('prepMinutes', 7)->set('stockQty', 3)
            ->set('photo', UploadedFile::fake()->image('menu.jpg', 1600, 1200))
            ->call('saveMenu')->assertHasNoErrors();

        $menu = Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('name', 'Foto Menu')->firstOrFail();
        $this->assertStringEndsWith('.webp', $menu->photo_path);
        Storage::disk('public')->assertExists($menu->photo_path);
        $this->assertSame('image/webp', mime_content_type(Storage::disk('public')->path($menu->photo_path)));
        [$width, $height] = getimagesize(Storage::disk('public')->path($menu->photo_path));
        $this->assertSame(800, max($width, $height));
    }

    public function test_tenant_can_open_the_menu_manager_page(): void
    {
        [$tenant, $user] = $this->tenantMember();

        $this->actingAs($user)->get(route('tenant.menu-manager', ['tenant' => $tenant->slug]))
            ->assertOk()->assertSee('Manajemen menu');
    }
}
