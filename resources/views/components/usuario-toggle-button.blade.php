@props([
    'usuario',
    'compact' => false,
])

@php
    $etiqueta = $usuario->activo ? 'Deshabilitar' : 'Habilitar';
@endphp

<flux:button
    type="button"
    size="sm"
    variant="ghost"
    :icon="$usuario->activo ? 'no-symbol' : 'check-circle'"
    :title="$compact ? $etiqueta : null"
    :aria-label="$etiqueta"
    :disabled="$usuario->id === auth()->id()"
    x-on:click.prevent="
        Swal.fire({
            icon: 'question',
            title: '{{ $usuario->activo ? '¿Deshabilitar este usuario?' : '¿Habilitar este usuario?' }}',
            text: '{{ $usuario->activo ? 'El usuario no podrá iniciar sesión, pero su historial no se verá afectado.' : 'El usuario podrá volver a iniciar sesión.' }}',
            showDenyButton: true,
            confirmButtonText: '{{ $etiqueta }}',
            denyButtonText: 'Cancelar',
        }).then((result) => {
            if (result.isConfirmed) {
                $wire.toggleActivo({{ $usuario->id }}).catch((error) => {
                    Swal.fire({
                        icon: 'error',
                        title: 'Error',
                        text: error.message || 'Ocurrió un problema al actualizar el usuario.',
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
