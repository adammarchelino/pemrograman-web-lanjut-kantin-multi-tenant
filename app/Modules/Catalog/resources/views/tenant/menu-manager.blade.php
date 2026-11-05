<x-layouts.tenant title="Manajemen Menu">
    <div class="mx-auto max-w-6xl p-4 sm:p-6">
        <h1 class="text-2xl font-bold">Manajemen menu</h1>
        <p class="mt-1 text-sm text-zinc-600">Menu dan kategori hanya dapat dikelola oleh tenant yang sedang aktif.</p>
        <livewire:catalog::menu-manager :tenant-id="$tenant->id" />
    </div>
</x-layouts.tenant>
