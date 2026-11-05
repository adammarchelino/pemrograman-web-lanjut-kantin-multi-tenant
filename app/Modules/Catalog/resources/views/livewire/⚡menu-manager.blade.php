<?php

use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\ModifierGroup;
use App\Models\Tenant;
use App\Models\UserTenantRole;
use App\Modules\Catalog\Services\MenuPhotoStore;
use App\Modules\Catalog\Services\MenuStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads;
    use WithPagination;

    public ?int $menuId = null;
    #[Locked]
    public int $tenantId;
    public string $name = '';
    public string $description = '';
    public ?int $categoryId = null;
    public int $basePrice = 0;
    public int $prepMinutes = 10;
    public int $stockQty = 0;
    public $photo = null;
    public string $search = '';
    public ?int $filterCategory = null;
    public string $newCategory = '';
    public string $groupName = '';
    public int $minSelect = 0;
    public int $maxSelect = 1;
    public string $optionName = '';
    public int $optionPrice = 0;
    public ?int $optionStock = null;
    public ?int $optionId = null;
    public ?int $optionGroupId = null;
    public ?int $attachMenuId = null;

    public function mount(int $tenantId): void { $this->tenantId = $tenantId; $this->ensureTenantAccess(); }
    private function tenantId(): int { $this->ensureTenantAccess(); return $this->tenantId; }
    private function ensureTenantAccess(): void
    {
        abort_unless(auth()->check() && Tenant::query()->whereKey($this->tenantId)->where('status', 'active')->exists()
            && UserTenantRole::query()->where('user_id', auth()->id())->where('tenant_id', $this->tenantId)->exists(), 403);
    }

    #[Computed]
    public function categories() { $tenantId = $this->tenantId(); return MenuCategory::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->orderBy('sort_order')->orderBy('name')->get(); }

    #[Computed]
    public function groups() { $tenantId = $this->tenantId(); return ModifierGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->with(['options' => fn($q) => $q->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)])->orderBy('name')->get(); }

    #[Computed]
    public function menus() {
        $tenantId = $this->tenantId();
        return Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $tenantId)->with(['category:id,name', 'modifierGroups.options'])
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.$this->search.'%'))
            ->when($this->filterCategory, fn ($q) => $q->where('category_id', $this->filterCategory))
            ->orderBy('name')->paginate(20)->withQueryString();
    }

    public function updatedSearch(): void { unset($this->menus); $this->resetPage(); }
    public function updatedFilterCategory(): void { unset($this->menus); $this->resetPage(); }

    public function createCategory(): void
    {
        $data = $this->validate(['newCategory' => ['required', 'string', 'max:120', Rule::unique('menu_categories', 'name')->where('tenant_id', $this->tenantId())]]);
        (new MenuCategory)->forceFill(['tenant_id' => $this->tenantId(), 'name' => $data['newCategory'], 'sort_order' => (int) MenuCategory::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->max('sort_order') + 1])->save();
        $this->reset('newCategory'); unset($this->categories); $this->dispatch('catalog-saved');
    }

    public function saveMenu(): void
    {
        $data = $this->validate([
            'name' => ['required', 'string', 'max:120'], 'description' => ['nullable', 'string', 'max:2000'],
            'categoryId' => ['required', 'integer', Rule::exists('menu_categories', 'id')->where('tenant_id', $this->tenantId())],
            'basePrice' => ['required', 'integer', 'min:1'], 'prepMinutes' => ['required', 'integer', 'min:1', 'max:240'],
            'stockQty' => ['required', 'integer', 'min:0'], 'photo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);
        $menu = $this->menuId ? $this->ownedMenu($this->menuId) : new Menu;
        if (! $menu->exists) { $menu->tenant_id = $this->tenantId(); }
        $oldPhoto = $menu->photo_path;
        $menu->fill(['category_id' => $data['categoryId'], 'name' => $data['name'], 'description' => $data['description'] ?? null,
            'base_price' => $data['basePrice'], 'prep_minutes' => $data['prepMinutes']]);
        if (! $menu->exists) { $menu->stock_qty = 0; }
        if ($this->photo) { $menu->photo_path = app(MenuPhotoStore::class)->store($this->photo, $this->tenantId()); }
        $menu->save();
        app(MenuStockService::class)->adjust($menu, $data['stockQty']);
        if ($this->photo && $oldPhoto) { Storage::disk('public')->delete($oldPhoto); }
        $this->reset('menuId', 'name', 'description', 'categoryId', 'basePrice', 'prepMinutes', 'stockQty', 'photo');
        unset($this->menus); $this->dispatch('catalog-saved');
    }

    public function edit(int $id): void
    {
        $menu = $this->ownedMenu($id);
        $this->menuId = $menu->id; $this->name = $menu->name; $this->description = $menu->description ?? '';
        $this->categoryId = $menu->category_id; $this->basePrice = $menu->base_price;
        $this->prepMinutes = $menu->prep_minutes; $this->stockQty = $menu->stock_qty;
    }

    public function toggle(int $id): void
    {
        $menu = $this->ownedMenu($id);
        app(MenuStockService::class)->toggleAvailability($menu);
        unset($this->menus);
    }

    public function deleteMenu(int $id): void
    {
        $menu = $this->ownedMenu($id);
        $menu->delete(); unset($this->menus);
    }

    public function addModifier(): void
    {
        $data = $this->validate([
            'groupName' => ['required', 'string', 'max:120'], 'minSelect' => ['required', 'integer', 'min:0'],
            'maxSelect' => ['required', 'integer', 'min:1', 'gte:minSelect'], 'optionName' => ['required', 'string', 'max:120'],
            'optionPrice' => ['required', 'integer', 'min:0'], 'optionStock' => ['nullable', 'integer', 'min:0'],
            'attachMenuId' => ['nullable', Rule::exists('menus', 'id')->where('tenant_id', $this->tenantId())],
        ]);
        DB::transaction(function () use ($data): void {
            $group = (new ModifierGroup)->forceFill(['tenant_id' => $this->tenantId(), 'name' => $data['groupName'], 'min_select' => $data['minSelect'], 'max_select' => $data['maxSelect']]);
            $group->save();
            $group->options()->getRelated()->forceFill(['tenant_id' => $this->tenantId(), 'group_id' => $group->id, 'name' => $data['optionName'], 'price_delta' => $data['optionPrice'], 'stock_qty' => $data['optionStock'] ?? 0])->save();
            if ($data['attachMenuId']) {
                $menu = $this->ownedMenu($data['attachMenuId']);
                $menu->modifierGroups()->attach($group->id, ['tenant_id' => $this->tenantId(), 'sort_order' => 0]);
            }
        });
        $this->reset('groupName', 'minSelect', 'maxSelect', 'optionName', 'optionPrice', 'optionStock', 'attachMenuId');
        unset($this->groups, $this->menus);
    }

    public function addOption(int $groupId): void
    {
        $data = $this->validate([
            'optionName' => ['required', 'string', 'max:120'], 'optionPrice' => ['required', 'integer', 'min:0'],
            'optionStock' => ['nullable', 'integer', 'min:0'],
        ]);
        $group = ModifierGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($groupId);
        $option = $this->optionId
            ? $group->options()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($this->optionId)
            : $group->options()->getRelated();
        $option->forceFill(['tenant_id' => $this->tenantId(), 'group_id' => $group->id,
            'name' => $data['optionName'], 'price_delta' => $data['optionPrice'], 'stock_qty' => $data['optionStock'] ?? 0])->save();
        $this->reset('optionId', 'optionName', 'optionPrice', 'optionStock');
        unset($this->groups, $this->menus);
    }

    public function editOption(int $groupId, int $optionId): void
    {
        $group = ModifierGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($groupId);
        $option = $group->options()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($optionId);
        $this->optionId = $option->id; $this->optionName = $option->name;
        $this->optionPrice = $option->price_delta; $this->optionStock = $option->stock_qty;
    }

    public function deleteOption(int $groupId, int $optionId): void
    {
        $group = ModifierGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($groupId);
        $group->options()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($optionId)->delete();
        unset($this->groups, $this->menus);
    }

    public function deleteModifierGroup(int $groupId): void
    {
        DB::transaction(function () use ($groupId): void {
            $group = ModifierGroup::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($groupId);
            $group->menus()->detach();
            $group->options()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->delete();
            $group->delete();
        });
        unset($this->groups, $this->menus);
    }

    private function ownedMenu(int $id): Menu
    {
        return Menu::query()->withoutGlobalScope('tenant')->where('tenant_id', $this->tenantId())->findOrFail($id);
    }
}; ?>

<section class="mt-6 space-y-8" wire:poll.5s>
    <div class="grid gap-6 lg:grid-cols-2">
        <form wire:submit="saveMenu" class="space-y-3 rounded-xl border bg-white p-4">
            <h2 class="text-lg font-semibold">{{ $menuId ? 'Ubah menu' : 'Tambah menu' }}</h2>
            <label class="block text-sm">Nama menu<input wire:model="name" class="mt-1 w-full rounded-lg border p-3" />@error('name')<span class="text-red-700">{{ $message }}</span>@enderror</label>
            <label class="block text-sm">Deskripsi<textarea wire:model="description" class="mt-1 w-full rounded-lg border p-3"></textarea>@error('description')<span class="text-red-700">{{ $message }}</span>@enderror</label>
            <div class="grid grid-cols-2 gap-3"><label class="text-sm">Kategori<select wire:model="categoryId" class="mt-1 w-full rounded-lg border p-3"><option value="">Pilih kategori</option>@foreach($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select>@error('categoryId')<span class="text-red-700">{{ $message }}</span>@enderror</label><label class="text-sm">Harga (rupiah)<input type="number" wire:model="basePrice" min="1" class="mt-1 w-full rounded-lg border p-3" />@error('basePrice')<span class="text-red-700">{{ $message }}</span>@enderror</label></div>
            <div class="grid grid-cols-2 gap-3"><label class="text-sm">Waktu siap (menit)<input type="number" wire:model="prepMinutes" min="1" class="mt-1 w-full rounded-lg border p-3" /></label><label class="text-sm">Stok<input type="number" wire:model="stockQty" min="0" class="mt-1 w-full rounded-lg border p-3" /></label></div>
            <label class="block text-sm">Foto (JPG, PNG, WebP; maks. 2 MB)<input type="file" wire:model="photo" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full" />@error('photo')<span class="text-red-700">{{ $message }}</span>@enderror</label>
            <button class="min-h-11 rounded-lg bg-red-600 px-4 font-semibold text-white">{{ $menuId ? 'Simpan perubahan' : 'Tambah menu' }}</button>
            @if($menuId)<button type="button" wire:click="$set('menuId', null)" class="min-h-11 px-3">Batal</button>@endif
        </form>
        <div class="space-y-3 rounded-xl border bg-white p-4">
            <h2 class="text-lg font-semibold">Kategori</h2><form wire:submit="createCategory" class="flex gap-2"><input wire:model="newCategory" aria-label="Nama kategori baru" placeholder="Contoh: Minuman" class="min-h-11 min-w-0 flex-1 rounded-lg border p-3" /><button class="rounded-lg border px-3">Tambah</button></form>@error('newCategory')<span class="text-red-700">{{ $message }}</span>@enderror
            <ul class="flex flex-wrap gap-2">@foreach($this->categories as $category)<li class="rounded-full bg-zinc-100 px-3 py-1 text-sm">{{ $category->name }}</li>@endforeach</ul>
        </div>
    </div>
    <div class="space-y-3 rounded-xl border bg-white p-4">
        <h2 class="text-lg font-semibold">Modifier group dan option</h2><p class="text-sm text-zinc-600">Jumlah pilihan minimum dan maksimum diperiksa di server.</p>
        <form wire:submit="addModifier" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <input wire:model="groupName" aria-label="Nama grup modifier" placeholder="Grup, mis. Level pedas" class="min-h-11 rounded-lg border p-3" />
            <div class="flex gap-2"><input type="number" min="0" wire:model="minSelect" aria-label="Minimum pilihan" class="min-h-11 w-1/2 rounded-lg border p-3" /><input type="number" min="1" wire:model="maxSelect" aria-label="Maksimum pilihan" class="min-h-11 w-1/2 rounded-lg border p-3" /></div>
            <input wire:model="optionName" aria-label="Nama opsi" placeholder="Opsi pertama" class="min-h-11 rounded-lg border p-3" />
            <div class="flex gap-2"><input type="number" min="0" wire:model="optionPrice" aria-label="Tambahan harga" placeholder="Tambahan harga" class="min-h-11 w-1/2 rounded-lg border p-3" /><button class="rounded-lg bg-zinc-900 px-3 text-white">Tambah grup</button></div>
            <select wire:model="attachMenuId" aria-label="Pasang ke menu" class="min-h-11 rounded-lg border p-3"><option value="">Belum pasang ke menu</option>@foreach($this->menus as $menu)<option value="{{ $menu->id }}">{{ $menu->name }}</option>@endforeach</select>
            @foreach(['groupName','minSelect','maxSelect','optionName','optionPrice','attachMenuId'] as $field)@error($field)<span class="text-sm text-red-700">{{ $message }}</span>@enderror @endforeach
        </form>
        <ul class="grid gap-2 sm:grid-cols-2">@foreach($this->groups as $group)<li class="rounded-lg bg-zinc-50 p-3"><strong>{{ $group->name }}</strong> · pilih {{ $group->min_select }}–{{ $group->max_select }} @foreach($group->options as $option)<div class="text-sm">{{ $option->name }} (+Rp{{ number_format($option->price_delta, 0, ',', '.') }})</div>@endforeach</li>@endforeach</ul>
        <div class="grid gap-3 sm:grid-cols-2">@foreach($this->groups as $group)<section class="rounded-lg border p-3" wire:key="modifier-tools-{{ $group->id }}"><h3 class="font-semibold">Kelola opsi: {{ $group->name }}</h3><ul>@foreach($group->options as $option)<li class="flex items-center justify-between py-1 text-sm"><span>{{ $option->name }} (+Rp{{ number_format($option->price_delta, 0, ',', '.') }})</span><div class="flex gap-2"><button wire:click="editOption({{ $group->id }}, {{ $option->id }})" class="rounded border px-2 py-1">Ubah</button><button wire:click="deleteOption({{ $group->id }}, {{ $option->id }})" class="rounded border px-2 py-1">Hapus</button></div></li>@endforeach</ul><form wire:submit="addOption({{ $group->id }})" class="mt-2 flex gap-2"><input wire:model="optionName" aria-label="Nama opsi baru {{ $group->name }}" placeholder="Opsi baru" class="min-h-10 min-w-0 flex-1 rounded border p-2"><input type="number" min="0" wire:model="optionPrice" aria-label="Tambahan harga opsi" placeholder="Harga +" class="min-h-10 w-28 rounded border p-2"><button class="rounded border px-3">{{ $optionId ? 'Simpan opsi' : 'Tambah opsi' }}</button></form><button wire:click="deleteModifierGroup({{ $group->id }})" wire:confirm="Hapus grup dan seluruh opsinya?" class="mt-2 min-h-10 rounded border px-3 text-sm">Hapus grup</button></section>@endforeach</div>
    </div>
    <div class="space-y-3">
        <h2 class="text-lg font-semibold">Daftar menu</h2><div class="flex gap-2"><input wire:model.live.debounce.300ms="search" placeholder="Cari menu" aria-label="Cari menu" class="min-h-11 flex-1 rounded-lg border p-3" /><select wire:model.live="filterCategory" aria-label="Saring kategori" class="min-h-11 rounded-lg border p-3"><option value="">Semua kategori</option>@foreach($this->categories as $category)<option value="{{ $category->id }}">{{ $category->name }}</option>@endforeach</select></div>
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@forelse($this->menus as $menu)<article class="rounded-xl border bg-white p-4" wire:key="menu-{{ $menu->id }}">
            @if($menu->photo_path)<img src="{{ Storage::disk('public')->url($menu->photo_path) }}" alt="Foto {{ $menu->name }}" class="mb-3 aspect-video w-full rounded-lg object-cover" loading="lazy">@endif
            <div class="flex items-start justify-between gap-2"><div><h3 class="font-semibold">{{ $menu->name }}</h3><p class="text-sm text-zinc-600">{{ $menu->category?->name }} · Rp{{ number_format($menu->base_price, 0, ',', '.') }}</p><p class="text-sm">Stok {{ $menu->stock_qty }} · ± {{ $menu->prep_minutes }} menit</p></div><span class="rounded-full px-2 py-1 text-xs {{ $menu->is_available && $menu->stock_qty > 0 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">{{ $menu->is_available && $menu->stock_qty > 0 ? 'TERSEDIA' : 'HABIS' }}</span></div>
            <p class="mt-2 text-sm">{{ $menu->description }}</p><div class="mt-3 flex flex-wrap gap-2"><button wire:click="toggle({{ $menu->id }})" class="min-h-10 rounded-lg border px-3">{{ $menu->is_available ? 'Tandai habis' : 'Tersedia kembali' }}</button><button wire:click="edit({{ $menu->id }})" class="min-h-10 rounded-lg border px-3">Edit</button><button wire:click="deleteMenu({{ $menu->id }})" wire:confirm="Hapus menu ini? Riwayat tetap disimpan." class="min-h-10 rounded-lg border px-3">Hapus</button></div>
            @if($menu->modifierGroups->isNotEmpty())<p class="mt-2 text-xs text-zinc-500">Modifier: {{ $menu->modifierGroups->pluck('name')->join(', ') }}</p>@endif
        </article>@empty<p class="rounded-xl border bg-white p-6 text-sm text-zinc-600">Belum ada menu yang cocok.</p>@endforelse</div>{{ $this->menus->links() }}
    </div>
</section>
