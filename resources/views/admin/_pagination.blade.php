{{-- Vue de pagination Laravel (->links('admin._pagination')) rendue avec <x-admin.pagination>. --}}
<x-admin.pagination :page="$paginator->currentPage()" :derniere="$paginator->lastPage()" :url="fn (int $p) => $paginator->url($p)" class="mt-4" />
