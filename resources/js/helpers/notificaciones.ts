import Swal, { type SweetAlertOptions } from 'sweetalert2';

type OpcionesConfirmacion = {
    titulo: string;
    texto: string;
    textoConfirmar?: string;
    textoCancelar?: string;
    icono?: SweetAlertOptions['icon'];
    peligro?: boolean;
};

export const notificarExito = (mensaje: string, titulo = 'Listo'): void => {
    void Swal.fire({
        icon: 'success',
        title: titulo,
        text: mensaje,
        toast: true,
        position: 'top-end',
        timer: 3500,
        timerProgressBar: true,
        showConfirmButton: false,
    });
};

export const notificarError = (mensaje: string, titulo = 'Error'): void => {
    void Swal.fire({
        icon: 'error',
        title: titulo,
        text: mensaje,
        confirmButtonColor: '#16a34a',
    });
};

export const confirmarAccion = async (
    opciones: OpcionesConfirmacion,
): Promise<boolean> => {
    const resultado = await Swal.fire({
        icon: opciones.icono ?? (opciones.peligro ? 'warning' : 'question'),
        title: opciones.titulo,
        text: opciones.texto,
        showCancelButton: true,
        confirmButtonText: opciones.textoConfirmar ?? 'Confirmar',
        cancelButtonText: opciones.textoCancelar ?? 'Cancelar',
        confirmButtonColor: opciones.peligro ? '#dc2626' : '#16a34a',
        cancelButtonColor: '#6b7280',
        reverseButtons: true,
    });

    return resultado.isConfirmed;
};
