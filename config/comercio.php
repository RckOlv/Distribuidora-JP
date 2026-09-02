<?php

return [
    // Datos visibles en el ticket. Configurables por entorno.
    // Pendiente (Fase 6): administración de comercio desde el panel.
    'nombre' => env('COMERCIO_NOMBRE', 'Mi Verdulería'),

    'direccion' => env('COMERCIO_DIRECCION', ''),

    'telefono' => env('COMERCIO_TELEFONO', ''),

    'leyenda' => env('COMERCIO_LEYENDA', 'Gracias por su compra'),
];
