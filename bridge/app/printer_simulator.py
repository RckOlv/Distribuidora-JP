"""Simulador de impresora térmica TCP ESC/POS para desarrollo y testing.

Sirve como "impresora virtual" mientras no exista la Unnion TP95 física.
Recibe los bytes ESC/POS que genera el Print Bridge, los registra en memoria y
permite inspeccionarlos en los tests (sin depender de archivos temporales).

No sustituye la integración real: ``PrinterEthernet`` sigue apuntando al equipo
físico; este simulador es exclusivamente una herramienta de desarrollo que
escucha en el mismo protocolo (TCP 9100 raw) para validar el circuito
``print_job -> printer -> socket -> impresora simulada``.

Se usa con los concurrentes del módulo ``PrinterEthernet.imprimir``: el bridge
abre una conexión, envía el ticket y la cierra (EOF). Cada conexión = 1 trabajo.
"""

from __future__ import annotations

import socket
import threading
import time
from dataclasses import dataclass
from typing import Callable, Optional

# Puerto raw estándar de la mayoría de las térmicas Ethernet (TP95 entre ellas).
PUERTO_POR_DEFECTO = 9100


@dataclass
class TrabajoCapturado:
    """Un trabajo de impresión recibido por el simulador."""

    numero: int
    recibido_en: float
    cliente: str
    datos: bytes

    @property
    def tamano(self) -> int:
        return len(self.datos)


class PrinterSimulator:
    """Servidor TCP mínimo que se comporta como una impresora ESC/POS.

    Cada conexión entrante representa un ticket. Captura los bytes recibidos y
    los expone vía :meth:`trabajos` para que los tests puedan inspeccionarlos.

    Modos de falla (para simular condiciones de red):
      * ``delay_lectura``: tras aceptar la conexión, espera este tiempo antes de
        empezar a leer. Con un payload más grande que los buffers del socket
        provoca timeout en el emisor.
      * ``leer_parcial_y_cerrar``: lee a lo sumo esta cantidad de bytes y cierra
        la conexión abruptamente (desconexión durante la impresión).
      * ``recibir_callback``: hook opcional por conexión para inyectar
        comportamientos personalizados (por ejemplo, no leer nunca).
    """

    def __init__(
        self,
        host: str = "127.0.0.1",
        puerto: int = PUERTO_POR_DEFECTO,
        *,
        delay_lectura: float = 0.0,
        leer_parcial_y_cerrar: Optional[int] = None,
        recibir_callback: Optional[Callable[[socket.socket], None]] = None,
    ) -> None:
        self.host = host
        self.puerto_solicitado = puerto
        self.delay_lectura = delay_lectura
        self.leer_parcial_y_cerrar = leer_parcial_y_cerrar
        self.recibir_callback = recibir_callback

        self.puerto: Optional[int] = None  # puerto real (si se pidió 0 → auto)
        self._sock: Optional[socket.socket] = None
        self._hilo: Optional[threading.Thread] = None
        self._corriendo = False
        self._lock = threading.Lock()
        self._trabajos: list[TrabajoCapturado] = []
        self._contador = 0

    # --- ciclo de vida ---

    def iniciar(self) -> "PrinterSimulator":
        """Levanta el socket y arranca el hilo de aceptación."""
        if self._sock is not None:
            return self
        sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        sock.setsockopt(socket.SOL_SOCKET, socket.SO_REUSEADDR, 1)
        sock.bind((self.host, self.puerto_solicitado or 0))
        sock.listen(5)
        sock.settimeout(0.5)
        self.puerto = sock.getsockname()[1]
        self._sock = sock
        self._corriendo = True
        self._hilo = threading.Thread(target=self._bucle_aceptar, daemon=True)
        self._hilo.start()
        return self

    def detener(self) -> None:
        """Detiene el servidor y cierra el socket limpio."""
        self._corriendo = False
        sock = self._sock
        self._sock = None
        if sock is not None:
            try:
                sock.shutdown(socket.SHUT_RDWR)
            except OSError:
                pass
            try:
                sock.close()
            except OSError:
                pass
        if self._hilo is not None:
            self._hilo.join(timeout=2)
            self._hilo = None

    def __enter__(self) -> "PrinterSimulator":
        return self.iniciar()

    def __exit__(self, *exc: object) -> None:
        self.detener()

    # --- internos ---

    def _bucle_aceptar(self) -> None:
        assert self._sock is not None
        while self._corriendo:
            try:
                conexion, addr = self._sock.accept()
            except socket.timeout:
                continue
            except OSError:
                break
            hilo = threading.Thread(
                target=self._atender_conexion, args=(conexion, addr), daemon=True
            )
            hilo.start()

    def _atender_conexion(self, conexion: socket.socket, addr) -> None:  # type: ignore[no-untyped-def]
        cliente = f"{addr[0]}:{addr[1]}" if len(addr) >= 2 else str(addr)
        try:
            conexion.settimeout(5.0)

            if self.recibir_callback is not None:
                self.recibir_callback(conexion)
                return

            if self.delay_lectura:
                time.sleep(self.delay_lectura)

            if self.leer_parcial_y_cerrar is not None:
                # Desconexión durante la impresión: lee una parte y cierra.
                datos = conexion.recv(self.leer_parcial_y_cerrar)
                self._registrar(cliente, datos)
                return

            # Lectura normal hasta EOF (el cliente cierra al terminar).
            bloques: list[bytes] = []
            while True:
                trozo = conexion.recv(4096)
                if not trozo:
                    break
                bloques.append(trozo)
            self._registrar(cliente, b"".join(bloques))
        except socket.timeout:
            self._registrar(cliente, b"")
        except OSError:
            # Conexión rota por el cliente: registra lo que haya llegado.
            self._registrar(cliente, b"")
        finally:
            try:
                conexion.close()
            except OSError:
                pass

    def _registrar(self, cliente: str, datos: bytes) -> None:
        with self._lock:
            self._contador += 1
            self._trabajos.append(
                TrabajoCapturado(
                    numero=self._contador,
                    recibido_en=time.time(),
                    cliente=cliente,
                    datos=datos,
                )
            )

    # --- inspección ---

    def trabajos(self) -> list[TrabajoCapturado]:
        """Snapshot de los trabajos capturados hasta el momento."""
        with self._lock:
            return list(self._trabajos)

    def cantidad_trabajos(self) -> int:
        return len(self.trabajos())

    def ultimo_trabajo(self) -> Optional[TrabajoCapturado]:
        jobs = self.trabajos()
        return jobs[-1] if jobs else None


def esperar_hasta(
    condicion: Callable[[], bool], timeout: float = 5.0, paso: float = 0.02
) -> bool:
    """Sondea una condición hasta que se cumple o expira el tiempo."""
    fin = time.time() + timeout
    while time.time() < fin:
        if condicion():
            return True
        time.sleep(paso)
    return condicion()


if __name__ == "__main__":
    print("Simulador de impresora ESC/POS (Ctrl+C para salir)")
    print("Escuchando en 127.0.0.1:9100 ...")
    sim = PrinterSimulator().iniciar()
    try:
        while True:
            time.sleep(1)
    except KeyboardInterrupt:
        pass
    finally:
        sim.detener()
        print("Simulador detenido.")
