// frontend/js/mocks/handlers/indicadores.js
// Mock de GET /api/indicadores (docs/api/indicadores.md): misma forma y mismos errores del contrato.
// Las cifras son fijas y salen solo de (anio, mes, sede): la misma consulta da siempre lo mismo (RN-10).
// No guarda nada. Las sedes válidas son las de la semilla (bd.sedes).
import { Errores } from '../servidor.js';
import { PERMISO } from '../../nucleo/dominio.js';

const REPUESTOS = ['Disco SSD', 'Fuente de poder', 'Memoria RAM', 'Cable de red'];
const ESTADOS = ['registrada', 'asignada', 'en_atencion', 'en_espera'];

// Hora de Lima = UTC-5 todo el año (sin horario de verano).
function mesActualLima() {
  const d = new Date(Date.now() - 5 * 3600 * 1000);
  return { anio: d.getUTCFullYear(), mes: d.getUTCMonth() + 1 };
}

const dos = (n) => String(n).padStart(2, '0');

// Fin de mes en Lima, como lo entrega el contrato: "2026-10-31T23:59:59-05:00".
function finDeMes(anio, mes) {
  const ultimoDia = new Date(Date.UTC(anio, mes, 0)).getUTCDate();
  return `${anio}-${dos(mes)}-${dos(ultimoDia)}T23:59:59-05:00`;
}

// Número estable a partir de un texto (para que las cifras no cambien entre consultas).
function semilla(texto) {
  let h = 0;
  for (const c of texto) h = (h * 31 + c.charCodeAt(0)) % 100003;
  return h;
}

export default {
  id: 'indicadores',
  rutas: [
    {
      metodo: 'GET',
      ruta: '/indicadores',
      permiso: PERMISO.INDICADORES_VER,
      atender: ({ query, bd }) => {
        const actual = mesActualLima();
        const campos = {};

        // anio y mes van juntos o ninguno (por defecto, el mes en curso).
        const hayAnio = query.anio !== undefined && query.anio !== '';
        const hayMes = query.mes !== undefined && query.mes !== '';
        let anio = actual.anio;
        let mes = actual.mes;
        if (hayAnio !== hayMes) {
          campos[hayAnio ? 'mes' : 'anio'] = 'Indica año y mes juntos.';
        } else if (hayAnio) {
          anio = /^\d{4}$/.test(query.anio) ? Number(query.anio) : NaN;
          mes = /^\d{1,2}$/.test(query.mes) ? Number(query.mes) : NaN;
          if (Number.isNaN(anio)) campos.anio = 'Indica un año válido.';
          else if (Number.isNaN(mes) || mes < 1 || mes > 12) campos.mes = 'El mes debe estar entre 1 y 12.';
          else if (anio * 12 + mes > actual.anio * 12 + actual.mes) campos.mes = 'No se puede consultar un mes futuro.';
        }

        let sedeId = null;
        if (query.sede !== undefined && query.sede !== '') {
          if (!/^\d+$/.test(query.sede) || !bd.sedes.some((s) => String(s.id) === query.sede)) {
            campos.sede = 'La sede no existe.';
          } else {
            sedeId = Number(query.sede);
          }
        }

        if (Object.keys(campos).length > 0) throw Errores.invalido(campos);

        const cerrado = anio * 12 + mes < actual.anio * 12 + actual.mes;
        const corte = cerrado ? finDeMes(anio, mes) : new Date().toISOString();
        const base = { anio, mes, sede_id: sedeId, cerrado, corte };
        const s = semilla(`${anio}-${mes}|${sedeId ?? 'todas'}`);

        // Un mes sin incidencias: «sin datos», no ceros (promedio_horas = null).
        if (s % 7 === 0) {
          return {
            ...base,
            tiempo_atencion: { promedio_horas: null, muestras: 0 },
            pendientes: { total: 0, por_estado: {} },
            fallas_repetidas: [],
            repuestos: { atenciones_con_repuestos: 0, items: [] },
          };
        }

        const porEstado = {};
        ESTADOS.forEach((e, i) => {
          const n = (s + i * 3) % 5;
          if (n > 0) porEstado[e] = n;
        });

        return {
          ...base,
          tiempo_atencion: {
            promedio_horas: (60 + (s % 400)) / 10,   // horas decimales (una cifra), tal cual
            muestras: 5 + (s % 20),
          },
          pendientes: {
            total: Object.values(porEstado).reduce((a, b) => a + b, 0),
            por_estado: porEstado,
          },
          fallas_repetidas: Array.from({ length: s % 4 }, (_, i) => ({
            equipo_id: 10 + i,
            codigo: `PC-${dos(i + 1)}`,
            incidencias: 6 - i - (s % 2),
          })),
          repuestos: {
            atenciones_con_repuestos: 1 + (s % 6),
            items: REPUESTOS.slice(0, 1 + (s % 4)).map((nombre, i) => ({ nombre, cantidad: 1 + ((s + i) % 3) })),
          },
        };
      },
    },
  ],
};