# Verduleria Print Bridge

Puente local (Windows) que conecta Laravel (VPS, HTTPS saliente) con una
impresora térmica **Unnion TP95** por **Ethernet/TCP** usando ESC/POS.

```
Laravel VPS  ──HTTPS──▶  Print Bridge (Python)  ──TCP/Ethernet──▶  TP95 80mm
```

El Bridge **inicia** siempre la comunicación; no se abren puertos entrantes
ni se expone la impresora a Internet.

## Requisitos
- Python 3.10+ (probado en 3.14).
- `pip install -r requirements.txt` (solo `requests`; `pywin32` opcional, solo Windows, para el servicio).

## Configuración
1. Copiá `config.example.json` a `config.json`.
2. Configurá `server_url` (https de tu VPS), `device_id`, `token` y los datos de la impresora.
3. El token NO va en el repositorio; es secreto y se obtiene así en Laravel:

```bash
php artisan impresion:dispositivo crear --nombre=bridge-001
#  → imprime el token (cópialo a config.json)
php artisan impresion:dispositivo listar
```

> Puerto de impresora: el default es `9100` para pruebas, pero **debe verificarse
> contra la TP95 física**; la mayoría de las térmicas Ethernet usan 9100 (raw).

## Ejecución
```bash
python -m bridge            # daemon en primer plano (modo servicio portable)
python -m bridge --ui       # interfaz mínima interactiva (estado + prueba)
python -m bridge --prueba   # ticket de prueba directo a la impresora (sin venta)
```

### Como servicio de Windows (auto-inicio)
```bash
python -m app.service.entry install   # registra el servicio (requiere pywin32)
sc start VerduleriaPrintBridge
sc query VerduleriaPrintBridge
sc stop VerduleriaPrintBridge
python -m app.service.entry remove    # desinstala
```

### Empaquetado (PyInstaller)
Sin binario prebuild; ejecutá:
```bash
pip install pyinstaller
pyinstaller bridge.spec            # dependencias: solo requests
```
El `.exe` generado puede instalarse como servicio mediante
`pywin32` o un wrapper de WinSW. La arquitectura es agnóstica a esto.

## Cómo funciona
1. **Polling** cada `polling_interval` (3 s por defecto, configurable).
2. **Claim atómico**: `GET /api/bridge/impresiones/pendientes` reclama a lo sumo
   un trabajo (lo deja `PROCESANDO` con `dispositivo_id` y `procesando_at`).
3. Genera los **bytes ESC/POS** desde el **snapshot** del ticket (datos históricos,
   no re-consulta productos).
4. Envía por TCP a la impresora.
5. `PUT .../impreso` al éxito, o `PUT .../error` en caso de fallo:
    - `reintentar=true` (errores **transitorios**: impresora apagada, sin papel,
      sin red, timeout) → el trabajo vuelve a `PENDIENTE` y sigue disponible
      para reintentarlo sin límite artificial.
    - `reintentar=false` (errores **permanentes**: snapshot corrupto o inválido,
      formato no interpretable) → el trabajo queda en `ERROR` y NO se reintenta;
      se registra diagnóstico suficiente en los logs.
 6. **Backoff progresivo** (3→5→10→20→30 s) cuando cae el servidor; recuperación
    automática al volver.
7. **Recuperación de PROCESANDO**: el servidor devuelve a `PENDIENTE` trabajos
   huérfanos tras `recuperar_procesando_minutos` (5 min).

## Limitación exactly-once
No hay garantía absoluta de exactamente una impresión. Existe una ventana entre
"la impresora ya imprimió" y "el bridge informa IMPRESO": si el bridge se cae ahí,
el trabajo puede reintentarse. Se mitiga con claim atómico, estado PROCESANDO,
timestamps, recuperación y estado local, pero el at-least-once es inherente al
medio físico.

## Estructura
```
bridge/
  app/
    config.py        # carga y validación de config.json
    auth.py          # headers Bearer del dispositivo
    api_client.py    # cliente HTTP (solo cola de impresión)
    escpos.py        # buffer ESC/POS + codificación español (CP-850)
    print_job.py     # construye el ticket desde el snapshot
    printer.py       # conexión TCP/Ethernet + prueba de impresión
    retry.py         # backoff y reintentos
    state.py         # estado local liviano (JSON)
    logging_setup.py # logging con rotación
    bridge.py        # circuito principal de polling/procesamiento
    ui.py            # interfaz mínima
    service/         # servicio de Windows (opcional pywin32)
  tests/             # suite de tests (sin impresora real)
  config.example.json
  requirements.txt
  README.md
  bridge.spec        # PyInstaller
```

## Codificación (caracteres españoles)
La capa de codificación está centralizada en `escpos.CodificacionEspanyol`
(mapa a CP-850 por defecto). Si la TP95 real no interpreta esos bytes, cambiá
el mapa o la tabla de caracteres (`SELECT_CHARMAP`) desde ese único lugar.