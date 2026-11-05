<?php

use App\Models\Canteen;
use App\Models\Menu;
use App\Models\Tenant;
use App\Modules\Catalog\Services\PublicCatalogQuery;
use App\Modules\Catalog\Services\TenantOpeningHours;
use App\Modules\Kitchen\Services\WaitTimeEstimator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

new class extends Component {
    public Canteen $canteen;
    #[Url(except: '')]
    public string $search = '';
    #[Url(except: '')]
    public ?int $tenantId = null;
    #[Url(except: '')]
    public ?string $category = null;
    public ?int $detailMenuId = null;
    public array $selectedOptions = [];

    public function mount(string $canteenSlug): void
    {
        $this->canteen = Canteen::query()->where('slug', $canteenSlug)->where('status', 'active')->firstOrFail();
    }

    #[Computed]
    public function menus() { return app(PublicCatalogQuery::class)->browseAll($this->canteen, $this->search, $this->tenantId, $this->category); }

    #[Computed]
    public function categories() { return app(PublicCatalogQuery::class)->categoryNames($this->canteen); }

    #[Computed]
    public function tenants() { return app(TenantOpeningHours::class)->activeTenants($this->canteen); }

    public function filterCategory(string $category): void { $this->category = $this->category === $category ? null : $category; unset($this->menus); }

    public function showDetails(int $id): void
    {
        $this->detailMenuId = $id; $this->selectedOptions = [];
    }

    public function toggleOption(int $groupId, int $optionId, int $max): void
    {
        $selected = $this->selectedOptions[$groupId] ?? [];
        if (in_array($optionId, $selected)) {
            $selected = array_values(array_diff($selected, [$optionId]));
        } elseif (count($selected) < $max) {
            $selected[] = $optionId;
        }
        $this->selectedOptions[$groupId] = $selected;
    }

    #[Computed]
    public function detailMenu(): ?Menu { return $this->detailMenuId ? $this->menus->getCollection()->firstWhere('id', $this->detailMenuId) : null; }

    public function selectedTotal(): int
    {
        $menu = $this->detailMenu;
        if (! $menu) return 0;
        $ids = collect($this->selectedOptions)->flatten()->all();
        $delta = $menu->modifierGroups->flatMap->options->whereIn('id', $ids)->sum('price_delta');
        return $menu->base_price + $delta;
    }
}; ?>

<x-layouts.customer :title="$canteen->name">
    <section class="space-y-5" wire:poll.5s>
        <header><h1 class="text-2xl font-bold">Menu kantin</h1><p class="text-sm text-zinc-600">Pilih menu dari tenant aktif. Stok dan jam buka dapat berubah sewaktu-waktu.</p></header>
        <div class="grid gap-3 sm:grid-cols-2"><label class="text-sm">Cari menu, tenant, atau kategori<input wire:model.live.debounce.300ms="search" class="mt-1 min-h-12 w-full rounded-lg border bg-white p-3" placeholder="Contoh: ayam" /></label><label class="text-sm">Saring tenant<select wire:model.live="tenantId" class="mt-1 min-h-12 w-full rounded-lg border bg-white p-3"><option value="">Semua tenant</option>@foreach($this->tenants as $tenant)<option value="{{ $tenant->id }}">{{ $tenant->display_name }}</option>@endforeach</select></label></div>
        <nav aria-label="Kategori menu" class="flex gap-2 overflow-x-auto pb-1"><button wire:click="$set('category', null)" class="min-h-10 shrink-0 rounded-full border px-4">Semua kategori</button>@foreach($this->categories as $name)<button wire:click="filterCategory(@js($name))" aria-pressed="{{ $category === $name ? 'true' : 'false' }}" class="min-h-10 shrink-0 rounded-full border px-4 {{ $category === $name ? 'bg-zinc-900 text-white' : 'bg-white' }}">{{ $name }}</button>@endforeach</nav>
        @php($groups = $this->menus->getCollection()->groupBy('tenant_id'))
        @forelse($groups as $tenantMenus)
            @php($tenant = $tenantMenus->first()->tenant)
            @php($open = app(TenantOpeningHours::class)->isOpen($tenant))
            <section class="space-y-3" aria-label="Menu {{ $tenant->display_name }}">
                <header class="rounded-xl border bg-white p-4"><div class="flex flex-wrap items-center justify-between gap-2"><div><h2 class="text-lg font-bold">{{ $tenant->display_name }}</h2><p class="text-sm text-zinc-600">@if($open)Buka{{ app(TenantOpeningHours::class)->todayLabel($tenant) ? ' · '.app(TenantOpeningHours::class)->todayLabel($tenant) : '' }}@else Tutup · Buka pukul {{ app(TenantOpeningHours::class)->opensAtToday($tenant) ?? '—' }}@endif</p></div><span class="text-sm font-medium">{{ app(WaitTimeEstimator::class)->label($tenant) }}</span></div></header>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">@foreach($tenantMenus as $menu)
                    @php($sellable = $open && $menu->is_available && $menu->stock_qty > 0)
                    <article class="overflow-hidden rounded-xl border bg-white {{ $sellable ? '' : 'opacity-75' }}" data-sellable="{{ $sellable ? '1' : '0' }}" wire:key="public-menu-{{ $menu->id }}">
                        @if($menu->photo_path)<img src="{{ Storage::disk('public')->url($menu->photo_path) }}" alt="Foto {{ $menu->name }}" class="aspect-video w-full object-cover" loading="lazy">@endif
                        <div class="space-y-2 p-4"><div class="flex items-start justify-between gap-2"><h3 class="font-semibold">{{ $menu->name }}</h3>@unless($sellable)<span class="rounded-full bg-red-100 px-2 py-1 text-xs font-bold text-red-800">{{ $open ? 'HABIS' : 'TUTUP' }}</span>@endunless</div><p class="text-sm text-zinc-600">{{ $menu->category?->name }} · {{ $menu->description }}</p><p class="font-semibold">Rp{{ number_format($menu->base_price, 0, ',', '.') }} <span class="text-xs font-normal text-zinc-500">· siap ± {{ $menu->prep_minutes }} mnt</span></p>
                            @if($sellable)<button wire:click="showDetails({{ $menu->id }})" class="min-h-11 w-full rounded-lg bg-red-600 px-4 font-semibold text-white">Lihat detail & modifier</button>@else<button disabled class="min-h-11 w-full rounded-lg bg-zinc-200 px-4 text-zinc-500">Tidak tersedia</button>@endif
                        </div>
                    </article>
                @endforeach</div>
            </section>
        @empty
            <div class="rounded-xl border bg-white p-6 text-center text-zinc-600">Menu tidak ditemukan.</div>
        @endforelse
        <div>{{ $this->menus->links() }}</div>
        @if($this->detailMenu)
            <div class="fixed inset-0 z-20 flex items-end justify-center bg-black/50 p-0 sm:items-center sm:p-4" role="presentation" wire:click.self="$set('detailMenuId', null)">
                <section role="dialog" aria-modal="true" aria-labelledby="menu-detail-title" class="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-t-2xl bg-white p-5 text-zinc-900 sm:rounded-2xl">
                    <div class="flex items-start justify-between"><h2 id="menu-detail-title" class="text-xl font-bold">{{ $this->detailMenu->name }}</h2><button wire:click="$set('detailMenuId', null)" aria-label="Tutup detail" class="min-h-11 min-w-11 rounded-lg border">×</button></div><p class="mt-2">{{ $this->detailMenu->description }}</p>
                    @foreach($this->detailMenu->modifierGroups as $group)<fieldset class="mt-4 rounded-lg border p-3"><legend class="px-1 font-semibold">{{ $group->name }} <span class="text-xs font-normal">Pilih {{ $group->min_select }}–{{ $group->max_select }}</span></legend>@foreach($group->options->where('is_available', true)->where(fn($option) => $option->stock_qty > 0) as $option)<label class="flex min-h-11 items-center gap-3 py-1"><input type="checkbox" @checked(in_array($option->id, $selectedOptions[$group->id] ?? [])) wire:click="toggleOption({{ $group->id }}, {{ $option->id }}, {{ $group->max_select }})"><span class="flex-1">{{ $option->name }}</span><span>+Rp{{ number_format($option->price_delta, 0, ',', '.') }}</span></label>@endforeach</fieldset>@endforeach
                    <p class="mt-4 text-lg font-bold">Total pilihan: Rp{{ number_format($this->selectedTotal(), 0, ',', '.') }}</p><p class="mt-2 text-xs text-zinc-500">Harga dan stok akan divalidasi kembali saat pemesanan tersedia.</p>
                </section>
            </div>
        @endif
    </section>
</x-layouts.customer>
