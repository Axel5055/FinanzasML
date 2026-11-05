@props([
    'sucursal',
    'compact' => false,
])

@php
    $etiqueta = $sucursal->activo ? 'Deshabilitar' : 'Habilitar';
@endphp

<flux:button
    type="button"
    size="sm"
    variant="ghost"
    :icon="$sucursal->activo ? 'no-symbol' : 'check-circle'"
    :title="$compact ? $etiqueta : null"
    :aria-label="$etiqueta"
    x-on:click.prevent="
        Swal.fire({
            icon: 'question',
            title: '{{ $sucursal->activo ? '¿Deshabilitar esta sucursal?' : '¿Habilitar esta sucursal?' }}',
            text: '{{ $sucursal->activo ? 'Dejará de aparecer en el select para registrar cuentas nuevas. No se elimina ni afecta el historial ya registrado.' : 'La sucursal volverá a estar disponible para registrar cuentas.' }}',
            showDenyButton: true,
            confirmButtonText: '{{ $sucursal->activo ? 'Deshabilitar' : 'Habilitar' }}',
            denyButtonText: 'Cancelar',
        }).then((result) => {
            if (result.isConfirmed) {
                $wire.toggleActivo({{ $sucursal->id }}).catch((error) => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'Ocurrió un problema al actualizar la sucursal.',
                        showConfirmButton: true,
                    });
                });
            }
        })
    "
>
    @unless ($compact)
        {{ $etiqueta }}
    @endunless
</flux:button>
