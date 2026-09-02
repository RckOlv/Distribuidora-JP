<?php

return [
    // Límite de minutos para recuperar un trabajo que quedó PROCESANDO
    // (por corte del bridge) y devolverlo a PENDIENTE.
    'recuperar_procesando_minutos' => (int) env('IMPRESION_RECUPERAR_PROCESANDO_MINUTOS', 5),

    // Intervalo mínimo (seg) entre escrituras de `ultima_conexion` por
    // dispositivo. Evita un UPDATE de BD en cada polling (~3s) del bridge.
    'ultima_conexion_intervalo' => (int) env('IMPRESION_ULTIMA_CONEXION_INTERVALO', 55),
];
