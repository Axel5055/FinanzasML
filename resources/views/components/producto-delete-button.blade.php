@props([
    'producto',
    'compact' => false,
])

<flux:button
    type="button"
    size="sm"
    variant="ghost"
    icon="trash"
    :title="$compact ? 'Eliminar' : null"
    aria-label="Eliminar producto"
    x-on:click.prevent="
        Swal.fire({
            icon: 'question',
            title: '¿Estás seguro de eliminar el producto?',
            text: 'Una vez eliminado no se podrá recuperar.',
            showDenyButton: true,
            confirmButtonText: 'Eliminar',
            denyButtonText: 'No eliminar',
        }).then((result) => {
            if (result.isConfirmed) {
                $wire.eliminar({{ $producto->id }}).catch((error) => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error al eliminar',
                        text: error.message || 'Ocurrió un problema al intentar eliminar el producto.',
                        showConfirmButton: true,
                    });
                });
            }
        })
    "
>
    @unless ($compact)
        Eliminar
    @endunless
</flux:button>
