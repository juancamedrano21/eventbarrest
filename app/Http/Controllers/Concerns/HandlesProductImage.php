<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domains\Catalog\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * La foto de un producto. Lo mismo en los dos mundos: aquí no hay ninguna
 * regla de comercio ni de negocio, solo un archivo que entra y otro que se
 * va — por eso sí se comparte, al contrario que las operaciones del catálogo.
 *
 * Al reemplazar se borra la anterior: nadie va a volver a esa foto, y dejarla
 * en el disco es basura que crece con cada corrección de la carta.
 */
trait HandlesProductImage
{
    /** @return array<int, string> Las reglas del campo, para el validate() de quien llame. */
    protected function reglasDeImagen(): array
    {
        // 4 MB da de sobra para una foto de carta y corta de raíz la subida
        // de un original de cámara sin redimensionar.
        return ['nullable', 'image', 'mimes:jpeg,jpg,png,webp', 'max:4096'];
    }

    /**
     * Guarda la foto que venga y devuelve lo que hay que escribir en el
     * producto: la ruta nueva, null si piden quitarla, o nada si el
     * formulario no la mencionó.
     *
     * @return array<string, string|null>
     */
    protected function imagenDe(Request $request, ?Product $product = null): array
    {
        if ($request->hasFile('image')) {
            $this->borraImagen($product);

            return ['image_path' => $request->file('image')->store('product-images', 'public')];
        }

        // Casilla marcada: quitar la que había y quedarse sin foto.
        if ($request->boolean('remove_image')) {
            $this->borraImagen($product);

            return ['image_path' => null];
        }

        return [];
    }

    protected function borraImagen(?Product $product): void
    {
        if ($product?->image_path !== null) {
            Storage::disk('public')->delete($product->image_path);
        }
    }
}
