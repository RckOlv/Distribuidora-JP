"""Integración específica con el SCM de Windows (pywin32).

Importado únicamente en Windows y de forma opcional; el resto del bridge no
depende de este módulo.
"""

from __future__ import annotations

import win32serviceutil  # pyright: ignore[reportMissingImports]  (solo Windows)

NOMBRE_SERVICIO = "VerduleriaPrintBridge"
NOMBRE_MOSTRAR = "Verduleria Print Bridge"
DESCRIPCION = "Imprime tickets de Verduleria en una impresora termica por Ethernet."


def instalar_servicio() -> None:
    win32serviceutil.InstallService(
        None,
        NOMBRE_SERVICIO,
        NOMBRE_MOSTRAR,
        startType=win32serviceutil.SERVICE_AUTO_START,
        description=DESCRIPCION,
    )
    print(f"Servicio {NOMBRE_SERVICIO} instalado. Inicialo con: sc start {NOMBRE_SERVICIO}")


def remover_servicio() -> None:
    win32serviceutil.RemoveService(NOMBRE_SERVICIO)
    print(f"Servicio {NOMBRE_SERVICIO} removido.")


class ServicioWindows(win32serviceutil.ServiceFramework):  # type: ignore[name-defined]
    _svc_name_ = NOMBRE_SERVICIO
    _svc_display_name_ = NOMBRE_MOSTRAR
    _svc_description_ = DESCRIPCION

    def __init__(self, args) -> None:
        super().__init__(args)
        self._bridge = None

    def _crear_bridge(self):
        from app.bridge import PrintBridge
        from app.config import Config
        from app.logging_setup import configurar_logging

        config = Config.cargar()
        config.validar()
        logger = configurar_logging(config.logs_dir)
        return PrintBridge(config, logger)

    def SvcStop(self) -> None:  # noqa: N802
        if self._bridge:
            self._bridge.detener()

    def SvcDoRun(self) -> None:  # noqa: N802
        import servicemanager

        servicemanager.LogInfoMsg(f"{NOMBRE_MOSTRAR} iniciando.")
        self._bridge = self._crear_bridge()
        self._bridge.ejecutar()


def ejecutar_servicio_windows() -> None:
    win32serviceutil.HandleCommandLine(ServicioWindows)