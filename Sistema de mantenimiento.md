# Caso A · Sistema de gestión de mantenimiento y soporte técnico de equipos

> Guía de lo que el sistema debe resolver: el problema, el entorno, los usuarios, las reglas y los datos. **No define la arquitectura, la base de datos ni los módulos**; esas decisiones se toman a partir de este documento.

**Leyenda:** **[H]** hecho dado por el caso o el cliente · **[D]** decisión de negocio ya cerrada · **[S]** supuesto a confirmar · **[P]** pregunta pendiente al cliente.

## Índice

1. [El problema](#1-el-problema)
2. [Escala y entorno](#2-escala-y-entorno)
3. [Alcance de la primera versión](#3-alcance-de-la-primera-versión)
4. [Los 6 roles y sus permisos](#4-los-6-roles-y-sus-permisos)
5. [Flujos principales](#5-flujos-principales)
6. [Reglas de negocio](#6-reglas-de-negocio)
7. [Requerimientos funcionales](#7-requerimientos-funcionales)
8. [Requerimientos no funcionales](#8-requerimientos-no-funcionales)
9. [Datos, estados e indicadores](#9-datos-estados-e-indicadores)
10. [Decisiones de negocio tomadas](#10-decisiones-de-negocio-tomadas)
11. [Condiciones técnicas que el diseño debe respetar](#11-condiciones-técnicas-que-el-diseño-debe-respetar)
12. [Supuestos y preguntas pendientes](#12-supuestos-y-preguntas-pendientes)
13. [Criterios de éxito](#13-criterios-de-éxito)

---

## 1. El problema

### 1.1 La institución [H]

Es un instituto de formación técnica **grande y repartido en dos sedes**. Tiene **12 laboratorios de informática y electrónica**, oficinas administrativas y aulas con proyectores. Cada día docentes, administrativos y estudiantes dependen de computadoras, impresoras, proyectores, dispositivos de red y equipos de laboratorio para dictar clases, rendir evaluaciones y administrar la institución. El área de soporte (técnicos de soporte técnico y de informática, con sus jefes) es la que mantiene todo ese parque de equipos.

Por el tamaño y la distribución, una falla no es un problema aislado: un laboratorio caído durante una evaluación afecta a varios estudiantes a la vez, los técnicos se desplazan entre sedes y algunas aulas tienen conectividad intermitente. Por eso importan la **criticidad del equipo** y el **impacto académico**, y la institución no puede suspender sus actividades mientras se implanta una solución.

### 1.2 Cómo funciona hoy [H]

- **Cadena de reporte:** el estudiante avisa al docente, el docente avisa al encargado de laboratorio y este avisa a soporte técnico. El docente es quien tiene la responsabilidad de reportar.
- **Canales:** llamadas, mensajes y formularios impresos (antes también correo). Los canales anteriores produjeron malos reportes y mala comunicación.
- **Atención:** un técnico evalúa el equipo, intenta repararlo o pide componentes. En casos complejos se necesita autorización para reparación externa o reemplazo.
- **Seguimiento:** anotaciones individuales de cada técnico y un archivo compartido.
- **Preventivo:** se hace mantenimiento preventivo, pero no existe un calendario consolidado.
- **Reportes:** la dirección pide indicadores de tiempos de atención, trabajos pendientes y uso de recursos; los reportes mensuales se arman a mano.

### 1.3 Problemas a resolver [H]

- Un mismo incidente reportado por canales distintos se **atiende dos veces**.
- Hay incidentes **sin responsable asignado**, que quedan sin seguimiento.
- Los usuarios **no saben el estado** de su solicitud y llaman repetidamente.
- Los técnicos **no encuentran antecedentes** de averías de un equipo.
- Las **autorizaciones de compra demoran**.
- Es difícil saber **qué equipos fallan con frecuencia** y cuáles siguen fuera de servicio (hoy hay 10 equipos no funcionales).
- No hay **calendario de mantenimiento preventivo** (queda fuera de la v1, ver sección 3).
- Los **reportes mensuales** son manuales y dan cifras distintas según quién los prepare.

### 1.4 Qué debe lograr el sistema

- **Unificar registro y seguimiento:** cada problema se registra una sola vez sin importar el canal, con el equipo identificado, los duplicados detectados y un responsable hasta el cierre.
- **Priorizar e informar:** la prioridad sale de la criticidad del equipo y del impacto académico; el usuario ve el estado de su solicitud y el técnico ve el historial del equipo.
- **Controlar y medir:** registrar las autorizaciones y entregar indicadores con las mismas cifras para todos.

**Principio:** primero resolver el problema crítico (que ninguna incidencia se pierda ni se atienda dos veces); lo demás viene después.

## 2. Escala y entorno

| Aspecto | Dato |
| --- | --- |
| Sedes [H] | 2 |
| Laboratorios [H] | 12 (informática y electrónica), más oficinas y aulas con proyectores |
| Tipos de equipo [H] | computadoras, impresoras, proyectores, dispositivos de red, equipos de laboratorio |
| Cantidad total de equipos | Desconocida [P]; los inventarios son parciales. Hoy hay 10 fuera de servicio [H] |
| Usuarios [H] | 8 fijos (soporte y TI) y unos 50 variables (docentes y administrativos); 6 conectados a la vez |
| Volumen de incidencias | Sin dato [P]; hay más uso en temporada de exámenes [H] |
| Horario [H] | Lunes a sábado, de 8:00 a 20:00 |
| Servidor [H] | La institución tiene servidor propio; falta confirmar que sea accesible desde las dos sedes [S] |
| Dispositivos de acceso [H] | Computadoras y teléfonos institucionales |
| Conectividad [H] | Intermitente en algunas aulas; el personal técnico se desplaza entre sedes |
| Administrador del sistema [H] | Oficina de tecnología |
| Seguridad [H] | Proteger datos personales, credenciales e información técnica sensible |
| Inventarios [H] | Parciales; no se ha decidido si el sistema se integra con ellos (se decidió que no, D11) |

## 3. Alcance de la primera versión

**Dentro:**
- Registro único de incidencias con equipo identificado y aviso de duplicados.
- Asignación de responsable y seguimiento hasta el cierre.
- Prioridad por criticidad e impacto académico.
- Consulta de estado dentro del sistema.
- Historial por equipo y lista de equipos fuera de servicio.
- Registro de autorizaciones.
- Indicadores básicos y reporte mensual.
- Acceso con login y 6 roles.

**Fuera:**
- Integración con inventarios.
- Gestión contable de activos y pagos.
- Mantenimiento predictivo o monitoreo automático.
- Portal para estudiantes.
- Acceso de proveedores, compras y dirección como usuarios.
- Modo totalmente sin conexión.
- Correo o SMS.
- Niveles de servicio (SLA) formales.
- Avisos automáticos de cambio de estado (el usuario consulta el estado de su solicitud, RF-08).
- Programación y registro de mantenimiento preventivo.

## 4. Los 6 roles y sus permisos

Roles definidos por el cliente [H]: docente, admin, personal de soporte técnico, personal de soporte informática, jefe de soporte y jefe de informática.

| Función | Docente | Admin | Soporte técnico | Soporte informática | Jefe de soporte | Jefe de informática |
| --- | :---: | :---: | :---: | :---: | :---: | :---: |
| Registrar incidencia propia (RF-01) | ✔ | ✔ | — | — | — | — |
| Registrar a nombre de un docente (RF-02) | — | — | ✔ | ✔ | ✔ | — |
| Ver incidencias (RF-08) | propias | propias | asignadas | asignadas | todas | todas |
| Buscar / registrar equipos (RF-04) | — | — | ✔ | ✔ | ✔ | ✔ |
| Cambiar prioridad (RF-05) | — | — | — | — | ✔ | — |
| Asignar / reasignar (RF-06) | — | — | — | — | ✔ | — |
| Atender y marcar Resuelta (RF-07) | — | — | ✔ | ✔ | — | — |
| Confirmar solución | ✔ | ✔ | — | — | — | — |
| Cerrar sin confirmación (3 días) | — | — | — | — | ✔ | — |
| Historial del equipo (RF-09) | — | — | ✔ | ✔ | ✔ | ✔ |
| Solicitar autorización (RF-10) | — | — | ✔ | ✔ | ✔ | — |
| Aprobar / rechazar autorización (RF-10) | — | — | — | — | — | ✔ |
| Indicadores y reporte (RF-11) | — | — | — | — | ✔ | ✔ |
| Usuarios y roles (RF-12) | — | — | — | — | — | ✔ |

## 5. Flujos principales

**Vida de una incidencia**
1. Un docente o administrativo reporta una falla (o soporte la registra a su nombre si llegó por llamada, mensaje o formulario). Se indica equipo, ubicación, descripción y si afecta una clase o evaluación.
2. Si el equipo ya tiene una incidencia abierta, el sistema avisa y permite vincular el reporte a esa. Si no, se crea una incidencia nueva en estado **Registrada**.
3. El sistema asigna la prioridad inicial (RN-04); el jefe de soporte puede cambiarla con motivo.
4. El jefe de soporte asigna un técnico: estado **Asignada**.
5. El técnico atiende: estado **En atención**. Registra diagnóstico, acciones, repuestos si hubo y resultado.
6. Si necesita reparación externa, compra o reemplazo, solicita autorización y la incidencia pasa a **En espera**. El jefe de informática aprueba o rechaza con motivo y la atención continúa.
7. El técnico la marca **Resuelta**. Quien reportó confirma que el equipo funciona y queda **Cerrada**; si no confirma en 3 días hábiles, la cierra el jefe de soporte.
8. Cada paso queda en el historial del equipo y de la incidencia, con usuario, fecha y hora.

**Indicadores**
1. Los jefes consultan los indicadores del mes por sede, calculados solo con lo registrado y con la misma fórmula para todos.

## 6. Reglas de negocio

| ID | Regla |
| --- | --- |
| RN-01 | Un mismo problema reportado por varios canales es **una sola incidencia**. |
| RN-02 | Toda incidencia abierta tiene un **técnico responsable** asignado por el jefe de soporte. |
| RN-03 | Toda incidencia está asociada a **un equipo** con código único. |
| RN-04 | **Prioridad:** Alta si el equipo es crítico o afecta una evaluación; Media si afecta una clase; Baja en los demás casos. |
| RN-05 | Una reparación externa, compra de repuestos o reemplazo necesita **autorización del jefe de informática** antes de ejecutarse; mientras tanto la incidencia queda “En espera”. |
| RN-06 | Cierre: el técnico marca **Resuelta**; el reportante confirma y queda **Cerrada**; sin confirmación en 3 días hábiles la cierra el jefe de soporte. |
| RN-07 | Las incidencias y sus cambios **no se borran**; el historial del equipo se conserva. |
| RN-08 | *(Fuera de la v1)* El mantenimiento preventivo no se gestiona en esta versión (sección 3). El número se conserva para no renumerar las demás reglas. |
| RN-09 | Cada rol ve solo lo que necesita (sección 4); quien reporta ve solo sus solicitudes. |
| RN-10 | Los indicadores se calculan solo con lo registrado y con la misma fórmula para todos. |

## 7. Requerimientos funcionales

Son **12 requerimientos funcionales** (RF-01 a RF-12). Quedaron fuera de la v1 el aviso automático de cambio de estado y el mantenimiento preventivo (sección 3).

| ID | Requerimiento | Prior. | Criterio de aceptación | Cumple |
| --- | --- | :---: | --- | :---: |
| RF-01 | Registrar una incidencia indicando equipo, ubicación, descripción y si afecta una clase o evaluación. | Alta | Con sesión iniciada y datos obligatorios completos, se crea con número único y estado “Registrada”. | ☐ |
| RF-02 | Registrar a nombre de un docente lo recibido por llamada, mensaje o formulario impreso, indicando el canal. | Alta | La incidencia queda guardada con el canal y asociada al docente. | ☐ |
| RF-03 | Avisar si ya hay una incidencia abierta del mismo equipo y permitir vincular el nuevo reporte. | Alta | Con una abierta del equipo X, al reportar X se muestra la abierta; si se vincula, queda una sola incidencia con ambos reportes. | ☐ |
| RF-04 | Buscar equipos por código, tipo o ubicación y registrar los que no existan. | Alta | Un equipo nuevo queda disponible para incidencias; no se permiten dos con el mismo código. | ☐ |
| RF-05 | Asignar prioridad inicial (RN-04) y permitir al jefe de soporte cambiarla con motivo. | Alta | Equipo crítico con evaluación afectada → prioridad Alta y primero en la lista; el cambio guarda el motivo. | ☐ |
| RF-06 | Asignar y reasignar la incidencia a un técnico. | Alta | El técnico la ve en su lista, el estado pasa a “Asignada” y se registra quién asignó y cuándo. | ☐ |
| RF-07 | Registrar diagnóstico, acciones, repuestos (si hubo) y resultado, y avanzar el estado hasta cerrar. | Alta | Al marcar “Resuelta” se guardan fecha, hora, acciones y resultado; no se permite sin acciones ni resultado. | ☐ |
| RF-08 | Consultar el estado de mis incidencias. | Alta | El docente ve el estado actual y la fecha del último cambio sin llamar. | ☐ |
| RF-09 | Consultar el historial de intervenciones de un equipo y la lista de equipos fuera de servicio. | Alta | El equipo muestra cada incidencia con fecha, falla, solución y técnico; la lista solo muestra los no reparados. | ☐ |
| RF-10 | Solicitar autorización para reparación externa, compra o reemplazo; el jefe de informática aprueba o rechaza con motivo. | Media | Al enviar la solicitud la incidencia pasa a “En espera”; al responder se guardan decisión, fecha y motivo. | ☐ |
| RF-11 | Mostrar indicadores: tiempo de atención, pendientes, equipos con fallas repetidas y repuestos usados. | Alta | Para un mes y una sede, todos los que consultan ven las mismas cifras que salen de los registros. | ☐ |
| RF-12 | Crear usuarios y asignarles uno de los 6 roles. | Alta | Un docente que intenta asignar una incidencia o ver las de otra persona es bloqueado. | ☐ |

## 8. Requerimientos no funcionales

Los tiempos son **metas propuestas** para las pruebas, no cifras acordadas con el cliente.

| ID | Categoría | Condición verificable | Cómo se prueba | Cumple |
| --- | --- | --- | --- | :---: |
| RNF-01 | Seguridad | Solo entran usuarios con cuenta; cada rol accede a lo suyo; las contraseñas se guardan de forma no legible; los datos técnicos sensibles solo los ve el personal de soporte y TI. | Un usuario de cada rol intenta abrir lo no permitido; revisar que las contraseñas no sean legibles. | ☐ |
| RNF-02 | Rendimiento | Registrar una incidencia y mostrar la lista de pendientes tarda como máximo 3 s con 6 usuarios simultáneos. | Cronometrar con datos de prueba. | ☐ |
| RNF-03 | Disponibilidad | Disponible lunes a sábado de 8:00 a 20:00; respaldo periódico de los datos (semanal en la v1). | Probar restaurar desde un respaldo. | ☐ |
| RNF-04 | Usabilidad | Una persona sin capacitación registra una incidencia en menos de 2 minutos y encuentra su estado sin ayuda. | Prueba cronometrada con una muestra de usuarios. | ☐ |
| RNF-05 | Compatibilidad | Funciona en computadoras y teléfonos institucionales con un navegador actual, sin instalar nada. Diseño adaptable. | Probar los casos principales en PC y teléfono. | ☐ |
| RNF-06 | Trazabilidad | Cada registro, asignación, cambio de estado y autorización guarda usuario, fecha y hora; nada se borra. | Revisar el historial de una incidencia de prueba que pase por todos los estados. | ☐ |
| RNF-07 | Mala conexión (deseable) | Si falla la red al enviar, se avisa y el formulario conserva lo escrito. Sin modo sin conexión. | Cortar la conexión en un registro de prueba. | ☐ |

## 9. Datos, estados e indicadores

**Datos que maneja el sistema:**

| Elemento | Datos |
| --- | --- |
| Usuario | nombre, usuario, contraseña, rol, sede, activo |
| Sede | nombre |
| Equipo | código único, tipo, sede, ubicación, criticidad, si está fuera de servicio |
| Incidencia | número, equipo, quién reportó, quién la registró, canal, descripción, si afecta clase o evaluación, prioridad, estado, técnico, fechas (registro, resuelta, cierre) |
| Reporte vinculado | incidencia, quién reportó, canal, descripción, fecha |
| Atención | incidencia, técnico, diagnóstico, acciones, repuestos, resultado, fecha |
| Autorización | incidencia, tipo (reparación externa / compra / reemplazo), solicitante, decisión, quién decidió, motivo, fechas |
| Historial | incidencia, usuario, acción, estado anterior y nuevo, motivo, fecha |

**Relaciones principales:**
- Una sede tiene muchos equipos y muchos usuarios.
- Un equipo tiene muchas incidencias.
- Una incidencia pertenece a un equipo, tiene un reportante, a lo sumo un técnico responsable a la vez y puede tener varios reportes vinculados, varias atenciones y varias autorizaciones.
- Cada cambio sobre una incidencia genera un registro de historial.
- Cada usuario tiene exactamente un rol.

**Estados de una incidencia:** Registrada → Asignada → En atención → En espera (pendiente de autorización) → Resuelta → Cerrada.

**Valores fijos:**
- Prioridad: Alta, Media, Baja.
- Criticidad del equipo: Alta, Media, Baja.
- Canal de origen: sistema (web), llamada, mensaje, formulario impreso.
- Tipo de equipo: computadora, impresora, proyector, dispositivo de red, equipo de laboratorio.
- Estado de autorización: pendiente, aprobada, rechazada.

**Indicadores (RF-11), por mes y sede:**

| Indicador | Cómo se calcula **[D]** |
| --- | --- |
| Tiempo de atención | Promedio de (fecha Resuelta − fecha Registrada) de las incidencias del mes |
| Pendientes | Incidencias que no están Resuelta ni Cerrada |
| Equipos con fallas repetidas | Equipos con 3 o más incidencias en los últimos 90 días |
| Repuestos usados | Cantidad de atenciones con repuestos registrados, y lista de repuestos |

## 10. Decisiones de negocio tomadas

Estas decisiones cierran las ambigüedades del caso. Si el cliente las contradice, se revisan.

| # | Tema | Decisión **[D]** |
| --- | --- | --- |
| D1 | Quién es “admin” | Personal **administrativo** que reporta incidencias. El administrador del sistema es el **jefe de informática**. |
| D2 | Coordinador / encargado de laboratorio | **No es un rol aparte**: usa el rol Docente. |
| D3 | Compras, dirección académica y proveedores | **No usan el sistema.** La aprobación de autorizaciones la hace el jefe de informática; la dirección recibe el reporte mensual por parte de los jefes. |
| D4 | Soporte técnico vs. soporte informática | Soporte técnico: equipos físicos (impresoras, proyectores, equipos de laboratorio). Soporte informática: computadoras, red y software. El jefe de soporte asigna a cualquiera de los dos. |
| D5 | Quién asigna, prioriza y cierra | **Jefe de soporte.** |
| D6 | Quién aprueba compras, reparaciones externas y reemplazos | **Jefe de informática**, sin límites de monto en la v1. |
| D7 | Quién registra | Docente y administrativo registran sus incidencias. Soporte (técnico, informática o jefe) puede registrar a nombre de un docente lo recibido por llamada, mensaje o formulario. |
| D8 | Estados | 6 estados iguales para todos (sección 9). Se descarta que el docente solo vea “En revisión / Listo”. |
| D9 | Cierre | El técnico marca **Resuelta**; el reportante confirma y pasa a **Cerrada**. Si no confirma en 3 días hábiles, la cierra el jefe de soporte. |
| D10 | Prioridad | Regla RN-04. La criticidad del equipo es un dato del equipo. El jefe de soporte puede cambiarla con motivo. |
| D11 | Identificación del equipo | **Código único** + tipo, sede, ubicación y criticidad. **No se integra** con los inventarios: se cargan los equipos a mano (los 10 fuera de servicio sirven de datos de prueba). |
| D12 | Duplicados | Si se reporta un equipo que ya tiene una incidencia abierta, el sistema avisa y permite **vincular** el nuevo reporte. |
| D13 | Avisos | **Sin avisos automáticos, correo ni SMS** en la v1: el usuario ve el estado actual y la fecha del último cambio al consultar sus incidencias (RF-08). |
| D14 | Conexión | Aplicación web adaptable a teléfono. **Sin modo sin conexión.** Si falla la red, el formulario avisa y no pierde lo escrito (deseable). |
| D15 | Indicadores | 4 indicadores (sección 9). **Sin niveles de servicio (SLA) formales** en la v1. |
| D16 | Datos personales | Solo lo mínimo (nombre, usuario, rol, sede). Contraseñas guardadas de forma no legible. Nada se borra; el historial se conserva sin límite de tiempo en la v1. |
| D17 | Mantenimiento preventivo | **Fuera de la v1.** Se retoma en una versión posterior como lista programada por equipo, sede y fecha (no calendario gráfico). |

## 11. Condiciones técnicas que el diseño debe respetar

Son condiciones del problema, no decisiones de arquitectura. Sirven para elegir la base de datos, los módulos y la estructura.

- **Backend en PHP** (restricción dada). El frontend, la base de datos, la arquitectura y los módulos están **por decidir**.
- **Carga baja:** unos 58 usuarios y 6 conectados a la vez; no hay integraciones con otros sistemas.
- **Datos muy relacionados** (sede, equipo, incidencia, atención, autorización, historial) y que **no se borran**: se necesita integridad entre registros y trazabilidad completa.
- **Un solo conjunto de datos para las dos sedes**, con filtros y reportes por sede.
- **Acceso por 6 roles** en todas las pantallas y acciones (la sección 4 es la matriz de permisos).
- **Uso desde computadora y teléfono institucional**, sin instalar nada: pantallas adaptables.
- **Conectividad intermitente:** no debe perderse lo que el usuario está escribiendo.
- **Servidor propio del instituto**, pendiente de confirmar que sea accesible desde las dos sedes.
- **Seguridad:** contraseñas no legibles, datos técnicos sensibles limitados al personal autorizado y datos personales mínimos.
- **Cifras confiables:** los indicadores salen solo de los registros del sistema, con una fórmula única.
- **Horario de uso:** lunes a sábado, 8:00 a 20:00; respaldo periódico de los datos.

## 12. Supuestos y preguntas pendientes

Ninguna de estas preguntas bloquea el diseño; se trabaja con el supuesto indicado.

| # | Tipo | Tema | Supuesto con el que se trabaja |
| --- | :---: | --- | --- |
| 1 | [S] | El servidor es accesible desde las 2 sedes | Sí. [P] Confirmar sus características. |
| 2 | [S] | Ambas sedes tienen el mismo horario (lunes a sábado, 8:00 a 20:00) | Sí. |
| 3 | [S] | Los 8 usuarios fijos son soporte y TI; los 50 variables son docentes y administrativos | Sí. |
| 4 | [P] | Cantidad total de equipos e incidencias por semana (normal y en exámenes) | Sin dato; se usan datos de prueba. |
| 5 | [P] | Técnicos por turno y sede | Sin dato; no afecta la v1. |
| 6 | [P] | Quién aprueba compras y reparaciones externas | Jefe de informática (D6). |
| 7 | [P] | Política de datos personales y cuánto tiempo se conserva el historial | Datos mínimos y sin borrar (D16). |
| 8 | [P] | Niveles de servicio esperados por la dirección | No se implementan en la v1 (D15). |

## 13. Criterios de éxito

1. Una incidencia se registra una sola vez, con número único, y todos ven su estado (RF-01, RF-03, RF-08). Un segundo reporte del mismo equipo se vincula en vez de duplicarse.
2. Toda incidencia abierta tiene un responsable y se puede seguir hasta el cierre (RF-06, RF-07).
3. Un técnico llega al historial y la criticidad del equipo en **3 pantallas o menos** (RF-09).
4. El reporte mensual muestra las mismas cifras sin importar quién lo consulte (RF-11).
5. Cada rol solo accede a lo que le corresponde (RF-12, RNF-01).