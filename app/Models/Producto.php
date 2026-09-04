<?php

namespace App\Models;

use App\Enums\UnidadVenta;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class Producto extends Model
{
    protected $appends = ['imagen_url'];

    protected $fillable = [
        'categoria_id',
        'codigo',
        'nombre',
        'descripcion',
        'imagen',
        'unidad_medida',
        'stock_minimo',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'unidad_medida' => UnidadVenta::class,
            'stock_minimo' => 'decimal:3',
            'activo' => 'boolean',
        ];
    }

    public function categoria(): BelongsTo
    {
        return $this->belongsTo(Categoria::class);
    }

    public function precios(): HasMany
    {
        return $this->hasMany(Precio::class);
    }

    public function precioVigente(): HasOne
    {
        return $this->hasOne(Precio::class)->where('vigente', true);
    }

    public function costos(): HasMany
    {
        return $this->hasMany(Costo::class);
    }

    public function costoVigente(): HasOne
    {
        return $this->hasOne(Costo::class)->where('vigente', true);
    }

    public function getImagenUrlAttribute(): ?string
    {
        if (! $this->imagen) {
            return null;
        }

        // Storage::url() genera una URL absoluta basada en APP_URL (p. ej.
        // http://verduleria.test/storage/...). Cuando se navega desde otro host
        // o IP, ese dominio no se resuelve (ERR_NAME_NOT_RESOLVED), así que
        // devolvemos una ruta relativa para que el navegador la resuelva contra
        // el propio servidor que está sirviendo la aplicación.
        $rutaAbsoluta = Storage::disk('public')->url($this->imagen);

        return '/'.ltrim((string) parse_url($rutaAbsoluta, PHP_URL_PATH), '/');
    }

    /**
     * Busca productos por nombre o código/barcode (reutilizable por el POS).
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        return $query->when($termino !== null && $termino !== '', function (Builder $q) use ($termino) {
            $q->where(function (Builder $sub) use ($termino) {
                $sub->where('nombre', 'ilike', "%{$termino}%")
                    ->orWhere('codigo', 'ilike', "%{$termino}%");
            });
        });
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    public function movimientosStock(): HasMany
    {
        return $this->hasMany(MovimientoStock::class);
    }

    public function stockTotal(): float
    {
        return (float) $this->stocks()->sum('cantidad');
    }
}
