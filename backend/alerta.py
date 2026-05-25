"""
alerta.py — CMT Backend v3
Motor de alertas inteligentes 100% automático.
Se adapta a la estructura real de la BD: orden_pedido + orden_corte.

COLUMNAS REALES CONFIRMADAS:
  orden_pedido : id_op, cantidad_prendas, fecha_ingreso, fecha_salida,
                 estado, descripcion, tiempo_estandar, estilo,
                 tasa_cumplimiento, id_cliente
  orden_corte  : id_oc, fecha_corte, observacion, cantidad, id_op, id_linea
  alerta       : id_alerta, mensaje, tipo, fecha, estado, id_op

MODOS:
  python alerta.py --accion listar
  python alerta.py --accion marcar_leida    --id_alerta 5
  python alerta.py --accion marcar_atendida --id_alerta 5
  python alerta.py --accion eliminar        --id_alerta 5
  python alerta.py --accion monitor         <- motor automático
"""

import sys
import json
import os
import argparse
from datetime import datetime

if sys.stdout.encoding != 'utf-8':
    try:
        sys.stdout.reconfigure(encoding='utf-8')
    except Exception:
        pass

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    import mysql.connector
except ImportError:
    print(json.dumps({"success": False, "message": "mysql-connector-python no instalado. Ejecuta: pip install mysql-connector-python"}))
    sys.exit(1)

DB_CONFIG = {
    "host":     "localhost",
    "user":     "root",
    "password": "",
    "database": "cmt_costura",
    "charset":  "utf8mb4"
}
parser = argparse.ArgumentParser()
parser.add_argument('--accion', default='listar',
                    choices=['listar', 'marcar_leida', 'marcar_atendida',
                             'eliminar', 'contar', 'monitor'])
parser.add_argument('--id_alerta', default='')
parser.add_argument('--estado',    default='')
args, _ = parser.parse_known_args()


def get_conn():
    return mysql.connector.connect(**DB_CONFIG)

class MotorAlertas:

    def __init__(self, conn, cursor):
        self.conn    = conn
        self.cursor  = cursor
        self.creadas = 0
        self.log     = []

    def _existe(self, clave, id_op=None):
        """Evita duplicados: busca alertas activas con esa clave en el mensaje."""
        try:
            if id_op:
                self.cursor.execute(
                    "SELECT COUNT(*) AS n FROM alerta "
                    "WHERE estado IN ('pendiente','leida') AND id_op=%s AND mensaje LIKE %s",
                    (id_op, f"%{clave}%"))
            else:
                self.cursor.execute(
                    "SELECT COUNT(*) AS n FROM alerta "
                    "WHERE estado IN ('pendiente','leida') AND mensaje LIKE %s",
                    (f"%{clave}%",))
            return self.cursor.fetchone()["n"] > 0
        except Exception:
            return False

    def _crear(self, mensaje, tipo, id_op=None, clave=None):
        try:
            if clave and self._existe(clave, id_op):
                return
            self.cursor.execute(
                "INSERT INTO alerta (mensaje, tipo, fecha, estado, id_op) "
                "VALUES (%s, %s, NOW(), 'pendiente', %s)",
                (mensaje, tipo, id_op))
            self.creadas += 1
            self.log.append(f"[{tipo.upper()}] {mensaje[:90]}")
        except Exception as e:
            self.log.append(f"[ERROR _crear] {e}")

    def _col_existe(self, tabla, col):
        try:
            self.cursor.execute(
                "SELECT COUNT(*) AS n FROM information_schema.columns "
                "WHERE table_schema=DATABASE() AND table_name=%s AND column_name=%s",
                (tabla, col))
            return self.cursor.fetchone()["n"] > 0
        except Exception:
            return False

    def _tabla_existe(self, tabla):
        try:
            self.cursor.execute(
                "SELECT COUNT(*) AS n FROM information_schema.tables "
                "WHERE table_schema=DATABASE() AND table_name=%s", (tabla,))
            return self.cursor.fetchone()["n"] > 0
        except Exception:
            return False

    def r_auto_resolver(self):
        try:
            self.cursor.execute("""
                UPDATE alerta a
                JOIN orden_pedido op ON op.id_op = a.id_op
                SET a.estado = 'atendida'
                WHERE a.estado IN ('pendiente','leida')
                  AND LOWER(op.estado) IN ('completada','completado','cancelada','cancelado')
            """)
            n = self.cursor.rowcount
            if n > 0:
                self.log.append(f"[AUTO-CIERRE] {n} alertas resueltas por OP completada/cancelada.")
        except Exception as e:
            self.log.append(f"[SKIP] auto_resolver: {e}")

    def r_ops_sin_salida(self):
        try:
            self.cursor.execute("""
                SELECT
                    op.id_op,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op,
                    op.cantidad_prendas,
                    op.fecha_ingreso,
                    DATEDIFF(CURDATE(), op.fecha_ingreso) AS dias_espera,
                    c.nombre_cliente
                FROM orden_pedido op
                LEFT JOIN cliente c ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND op.fecha_salida IS NULL
                  AND op.fecha_ingreso IS NOT NULL
                  AND DATEDIFF(CURDATE(), op.fecha_ingreso) >= 15
                ORDER BY dias_espera DESC
            """)
            for op in self.cursor.fetchall():
                dias    = int(op["dias_espera"] or 0)
                cliente = op["nombre_cliente"] or "Sin cliente"
                nombre  = op["nombre_op"]
                prendas = int(op["cantidad_prendas"] or 0)
                tipo    = "critica" if dias >= 30 else "advertencia"
                clave   = "##SIN_SALIDA_OP##"
                msg = (f"OP #{op['id_op']} - {nombre} ({cliente}) "
                       f"lleva {dias} dias sin fecha de salida registrada "
                       f"({prendas:,} prendas pendientes). "
                       f"{'Verificar urgente.' if dias >= 30 else 'Revisar estado.'}")
                self._crear(msg, tipo, op["id_op"], clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_ops_sin_salida: {e}")

    def r_baja_tasa_cumplimiento(self):
        if not self._col_existe("orden_pedido", "tasa_cumplimiento"):
            return
        try:
            self.cursor.execute("""
                SELECT
                    op.id_op,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op,
                    op.tasa_cumplimiento,
                    op.cantidad_prendas,
                    c.nombre_cliente
                FROM orden_pedido op
                LEFT JOIN cliente c ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND op.tasa_cumplimiento IS NOT NULL
                  AND op.tasa_cumplimiento < 70
                ORDER BY op.tasa_cumplimiento ASC
            """)
            for op in self.cursor.fetchall():
                tasa    = float(op["tasa_cumplimiento"] or 0)
                cliente = op["nombre_cliente"] or "Sin cliente"
                nombre  = op["nombre_op"]
                prendas = int(op["cantidad_prendas"] or 0)
                tipo    = "critica" if tasa < 50 else "advertencia"
                clave   = "##BAJA_TASA##"
                msg = (f"OP #{op['id_op']} - {nombre} ({cliente}) "
                       f"tiene tasa de cumplimiento de {tasa:.0f}% "
                       f"({prendas:,} prendas en produccion). "
                       f"{'Nivel critico - intervencion requerida.' if tasa < 50 else 'Por debajo del umbral minimo (70%).'}")
                self._crear(msg, tipo, op["id_op"], clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_baja_tasa_cumplimiento: {e}")

    def r_carga_lineas_corte(self):
        if not self._tabla_existe("orden_corte"):
            return
        try:
            self.cursor.execute("""
                SELECT
                    oc.id_linea,
                    COALESCE(lp.nombre_linea, CONCAT('Linea ', oc.id_linea)) AS nombre_linea,
                    COUNT(DISTINCT oc.id_op)     AS num_ops,
                    SUM(oc.cantidad)             AS total_prendas
                FROM orden_corte oc
                JOIN orden_pedido op ON op.id_op = oc.id_op
                LEFT JOIN linea_produccion lp ON lp.id_linea = oc.id_linea
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                GROUP BY oc.id_linea
                ORDER BY total_prendas DESC
            """)
            lineas = self.cursor.fetchall()
            if len(lineas) < 2:
                return

            cargas   = [int(r["total_prendas"] or 0) for r in lineas]
            promedio = sum(cargas) / len(cargas)
            maximo   = max(cargas)

            for linea in lineas:
                carga   = int(linea["total_prendas"] or 0)
                nombre  = linea["nombre_linea"]
                num_ops = int(linea["num_ops"] or 0)

                if promedio == 0:
                    continue
                pct = ((carga - promedio) / promedio) * 100

                if pct >= 60:
                    clave = f"##SOBRECARGA_LINEA_{linea['id_linea']}##"
                    msg = (f"Sobrecarga en {nombre}: {carga:,} prendas en corte "
                           f"({num_ops} ordenes activas, {pct:+.0f}% sobre el promedio). "
                           f"Riesgo de cuello de botella.")
                    self._crear(msg, "critica", None, clave)
                elif pct >= 30:
                    clave = f"##ALTA_CARGA_LINEA_{linea['id_linea']}##"
                    msg = (f"Alta carga en {nombre}: {carga:,} prendas en proceso "
                           f"({pct:+.0f}% sobre el promedio de planta). Monitorear.")
                    self._crear(msg, "advertencia", None, clave)
                elif carga > 0 and pct <= -50 and maximo > 200:
                    clave = f"##BAJA_CARGA_LINEA_{linea['id_linea']}##"
                    msg = (f"Baja carga en {nombre}: solo {carga:,} prendas activas "
                           f"({abs(pct):.0f}% bajo el promedio). "
                           f"Verificar disponibilidad de trabajo.")
                    self._crear(msg, "advertencia", None, clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_carga_lineas_corte: {e}")

    def r_ops_sin_cortes(self):
        if not self._tabla_existe("orden_corte"):
            return
        try:
            self.cursor.execute("""
                SELECT
                    op.id_op,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op,
                    op.cantidad_prendas,
                    op.fecha_ingreso,
                    DATEDIFF(CURDATE(), op.fecha_ingreso) AS dias,
                    c.nombre_cliente
                FROM orden_pedido op
                LEFT JOIN cliente c ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND op.cantidad_prendas >= 200
                  AND op.fecha_ingreso IS NOT NULL
                  AND DATEDIFF(CURDATE(), op.fecha_ingreso) >= 3
                  AND NOT EXISTS (
                      SELECT 1 FROM orden_corte oc WHERE oc.id_op = op.id_op
                  )
                ORDER BY op.cantidad_prendas DESC
            """)
            for op in self.cursor.fetchall():
                prendas = int(op["cantidad_prendas"] or 0)
                dias    = int(op["dias"] or 0)
                cliente = op["nombre_cliente"] or "Sin cliente"
                nombre  = op["nombre_op"]
                tipo    = "critica" if prendas >= 500 and dias >= 7 else "advertencia"
                clave   = "##SIN_CORTES_OP##"
                msg = (f"OP #{op['id_op']} - {nombre} ({cliente}) "
                       f"tiene {prendas:,} prendas y {dias} dias ingresada "
                       f"sin ningun corte registrado. "
                       f"Verificar inicio de produccion.")
                self._crear(msg, tipo, op["id_op"], clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_ops_sin_cortes: {e}")

    def r_clientes_con_acumulacion(self):
        try:
            self.cursor.execute("""
                SELECT
                    c.id_cliente,
                    c.nombre_cliente,
                    COUNT(op.id_op)                       AS ops_activas,
                    COALESCE(SUM(op.cantidad_prendas), 0) AS prendas_total
                FROM orden_pedido op
                JOIN cliente c ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                GROUP BY c.id_cliente, c.nombre_cliente
                HAVING COUNT(op.id_op) >= 5
                ORDER BY ops_activas DESC
            """)
            for c in self.cursor.fetchall():
                ops     = int(c["ops_activas"])
                prendas = int(c["prendas_total"])
                tipo    = "critica" if ops >= 10 else "advertencia"
                clave   = f"##CLIENTE_ACUMULADO_{c['id_cliente']}##"
                msg = (f"Cliente {c['nombre_cliente']} acumula {ops} ordenes activas "
                       f"({prendas:,} prendas en total). "
                       f"{'Acumulacion critica - revisar prioridades urgente.' if ops >= 10 else 'Revisar capacidad de entrega.'}")
                self._crear(msg, tipo, None, clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_clientes_con_acumulacion: {e}")

    def r_volumen_global(self):
        try:
            self.cursor.execute("""
                SELECT
                    COUNT(*)                             AS total_ops,
                    COALESCE(SUM(cantidad_prendas), 0)  AS total_prendas
                FROM orden_pedido
                WHERE LOWER(estado) NOT IN ('completada','completado','cancelada','cancelado')
            """)
            r       = self.cursor.fetchone()
            ops     = int(r["total_ops"]    or 0)
            prendas = int(r["total_prendas"] or 0)

            if prendas > 80_000:
                clave = "##VOLUMEN_CRITICO##"
                msg   = (f"Volumen critico: {prendas:,} prendas en {ops} ordenes activas. "
                         f"Capacidad de planta en riesgo - evaluacion urgente.")
                self._crear(msg, "critica", None, clave)
            elif prendas > 40_000:
                clave = "##VOLUMEN_ALTO##"
                msg   = (f"Volumen elevado: {prendas:,} prendas en {ops} ordenes activas. "
                         f"Monitorear capacidad y planificar recursos.")
                self._crear(msg, "advertencia", None, clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_volumen_global: {e}")

    def r_tiempo_estandar_alto(self):
        if not self._col_existe("orden_pedido", "tiempo_estandar"):
            return
        try:
            self.cursor.execute("""
                SELECT AVG(tiempo_estandar) AS promedio
                FROM orden_pedido
                WHERE LOWER(estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND tiempo_estandar IS NOT NULL AND tiempo_estandar > 0
            """)
            r = self.cursor.fetchone()
            promedio = float(r["promedio"] or 0)
            if promedio < 1:
                return

            umbral = promedio * 1.8

            self.cursor.execute("""
                SELECT
                    op.id_op,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op,
                    op.tiempo_estandar,
                    op.cantidad_prendas,
                    c.nombre_cliente
                FROM orden_pedido op
                LEFT JOIN cliente c ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND op.tiempo_estandar > %s
                ORDER BY op.tiempo_estandar DESC
                LIMIT 10
            """, (umbral,))
            for op in self.cursor.fetchall():
                te        = float(op["tiempo_estandar"])
                prendas   = int(op["cantidad_prendas"] or 0)
                cliente   = op["nombre_cliente"] or "Sin cliente"
                clave     = "##TIEMPO_ALTO_OP##"
                pct_sobre = ((te - promedio) / promedio) * 100
                msg = (f"OP #{op['id_op']} - {op['nombre_op']} ({cliente}): "
                       f"tiempo estandar de {te:.2f} min/prenda "
                       f"({pct_sobre:.0f}% sobre el promedio de planta). "
                       f"{prendas:,} prendas en proceso - riesgo de demora.")
                self._crear(msg, "advertencia", op["id_op"], clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_tiempo_estandar_alto: {e}")

    def r_cortes_anomalos(self):
        if not self._tabla_existe("orden_corte"):
            return
        try:
            self.cursor.execute("""
                SELECT AVG(cantidad) AS promedio
                FROM orden_corte
                WHERE fecha_corte >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                  AND cantidad > 0
            """)
            r = self.cursor.fetchone()
            promedio = float(r["promedio"] or 0)
            if promedio < 5:
                return

            umbral = promedio * 0.10
            self.cursor.execute("""
                SELECT
                    oc.id_oc,
                    oc.id_op,
                    oc.cantidad,
                    oc.fecha_corte,
                    oc.observacion,
                    COALESCE(lp.nombre_linea, CONCAT('Linea ', oc.id_linea)) AS nombre_linea,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op
                FROM orden_corte oc
                LEFT JOIN orden_pedido op ON op.id_op = oc.id_op
                LEFT JOIN linea_produccion lp ON lp.id_linea = oc.id_linea
                WHERE oc.fecha_corte >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                  AND oc.cantidad < %s
                  AND oc.cantidad > 0
                ORDER BY oc.cantidad ASC
                LIMIT 5
            """, (umbral,))
            cortes = self.cursor.fetchall()
            if len(cortes) >= 3:
                clave = "##CORTES_ANOMALOS##"
                msg = (f"Se detectaron {len(cortes)} cortes con cantidades inusualmente bajas "
                       f"en los ultimos 7 dias (promedio normal: {promedio:.0f} prendas/corte). "
                       f"Posible interrupcion en el proceso de corte.")
                self._crear(msg, "advertencia", None, clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_cortes_anomalos: {e}")

    def r_ops_con_corte_insuficiente(self):
        if not self._tabla_existe("orden_corte"):
            return
        try:
            self.cursor.execute("""
                SELECT
                    op.id_op,
                    COALESCE(op.estilo, op.descripcion, CONCAT('OP #', op.id_op)) AS nombre_op,
                    op.cantidad_prendas,
                    COALESCE(SUM(oc.cantidad), 0)   AS prendas_cortadas,
                    c.nombre_cliente
                FROM orden_pedido op
                LEFT JOIN orden_corte oc ON oc.id_op = op.id_op
                LEFT JOIN cliente c      ON c.id_cliente = op.id_cliente
                WHERE LOWER(op.estado) NOT IN ('completada','completado','cancelada','cancelado')
                  AND op.cantidad_prendas >= 500
                GROUP BY op.id_op, op.estilo, op.descripcion, op.cantidad_prendas, c.nombre_cliente
                HAVING prendas_cortadas < (op.cantidad_prendas * 0.30)
                ORDER BY (op.cantidad_prendas - prendas_cortadas) DESC
                LIMIT 8
            """)
            for op in self.cursor.fetchall():
                total     = int(op["cantidad_prendas"] or 0)
                cortadas  = int(op["prendas_cortadas"] or 0)
                faltantes = total - cortadas
                pct_avance = (cortadas / total * 100) if total > 0 else 0
                cliente   = op["nombre_cliente"] or "Sin cliente"
                tipo      = "critica" if pct_avance < 10 else "advertencia"
                clave     = "##CORTE_INSUFICIENTE_OP##"
                msg = (f"OP #{op['id_op']} - {op['nombre_op']} ({cliente}): "
                       f"solo {pct_avance:.0f}% cortado "
                       f"({cortadas:,} de {total:,} prendas). "
                       f"Faltan {faltantes:,} prendas por cortar.")
                self._crear(msg, tipo, op["id_op"], clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_ops_con_corte_insuficiente: {e}")

    def r_caida_actividad_corte(self):
        if not self._tabla_existe("orden_corte"):
            return
        try:
            self.cursor.execute("""
                SELECT
                    SUM(CASE WHEN fecha_corte >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                             THEN cantidad ELSE 0 END) AS esta_semana,
                    SUM(CASE WHEN fecha_corte >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                              AND fecha_corte <  DATE_SUB(CURDATE(), INTERVAL 7 DAY)
                             THEN cantidad ELSE 0 END) AS semana_anterior
                FROM orden_corte
                WHERE fecha_corte >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
            """)
            r = self.cursor.fetchone()
            esta     = int(r["esta_semana"]    or 0)
            anterior = int(r["semana_anterior"] or 0)

            if anterior < 100:
                return

            caida_pct = ((anterior - esta) / anterior) * 100
            if caida_pct >= 40:
                clave = "##CAIDA_CORTE_SEMANAL##"
                tipo  = "critica" if caida_pct >= 60 else "advertencia"
                msg = (f"Caida de actividad de corte: esta semana se cortaron "
                       f"{esta:,} prendas vs {anterior:,} la semana anterior "
                       f"({caida_pct:.0f}% menos). "
                       f"Verificar operaciones de corte.")
                self._crear(msg, tipo, None, clave)
        except Exception as e:
            self.log.append(f"[SKIP] r_caida_actividad_corte: {e}")

    def ejecutar(self):
        self.r_auto_resolver()
        self.r_ops_sin_salida()
        self.r_baja_tasa_cumplimiento()
        self.r_carga_lineas_corte()
        self.r_ops_sin_cortes()
        self.r_clientes_con_acumulacion()
        self.r_volumen_global()
        self.r_tiempo_estandar_alto()
        self.r_cortes_anomalos()
        self.r_ops_con_corte_insuficiente()
        self.r_caida_actividad_corte()
        self.conn.commit()
        return {
            "success":   True,
            "creadas":   self.creadas,
            "log":       self.log,
            "timestamp": datetime.now().isoformat()
        }

def run():
    try:
        conn   = get_conn()
        cursor = conn.cursor(dictionary=True)
        result = {}

        if args.accion in ('monitor', 'contar'):
            motor = MotorAlertas(conn, cursor)
            res_m = motor.ejecutar()

            if args.accion == 'contar':
                cursor.execute("""
                    SELECT
                        COUNT(*) AS total,
                        SUM(estado='pendiente')   AS pendientes,
                        SUM(estado='leida')       AS leidas,
                        SUM(estado='atendida')    AS atendidas,
                        SUM(tipo='critica')       AS criticas,
                        SUM(tipo='advertencia')   AS advertencias
                    FROM alerta
                """)
                r = cursor.fetchone()
                result = {
                    "success":      True,
                    "total":        int(r["total"]        or 0),
                    "pendientes":   int(r["pendientes"]   or 0),
                    "leidas":       int(r["leidas"]       or 0),
                    "atendidas":    int(r["atendidas"]    or 0),
                    "criticas":     int(r["criticas"]     or 0),
                    "advertencias": int(r["advertencias"] or 0),
                    "motor":        res_m,
                }
            else:
                result = res_m

        elif args.accion == 'listar':
            motor = MotorAlertas(conn, cursor)
            motor.ejecutar()

            where  = ''
            params = []
            if args.estado:
                where = 'WHERE a.estado = %s'
                params.append(args.estado)

            cursor.execute(f"""
                SELECT
                    a.id_alerta,
                    a.mensaje,
                    a.tipo,
                    a.fecha,
                    a.estado,
                    a.id_op,
                    op.estilo        AS op_estilo,
                    op.descripcion   AS op_descripcion,
                    c.nombre_cliente AS cliente
                FROM alerta a
                LEFT JOIN orden_pedido op ON op.id_op = a.id_op
                LEFT JOIN cliente      c  ON c.id_cliente = op.id_cliente
                {where}
                ORDER BY
                    FIELD(a.estado,  'pendiente','leida','atendida'),
                    FIELD(a.tipo,    'critica','advertencia','info'),
                    a.fecha DESC
            """, params)

            rows = cursor.fetchall()
            result = {
                "success":    True,
                "alertas":    [{**r, "fecha": str(r["fecha"]) if r["fecha"] else ""}
                               for r in rows],
                "total":      len(rows),
                "pendientes": sum(1 for r in rows if r["estado"] == "pendiente"),
            }

        elif args.accion == 'marcar_leida':
            if not args.id_alerta:
                result = {"success": False, "message": "Se requiere id_alerta."}
            else:
                cursor.execute(
                    "UPDATE alerta SET estado='leida' "
                    "WHERE id_alerta=%s AND estado='pendiente'",
                    (int(args.id_alerta),))
                conn.commit()
                result = {"success": True, "afectadas": cursor.rowcount}

        elif args.accion == 'marcar_atendida':
            if not args.id_alerta:
                result = {"success": False, "message": "Se requiere id_alerta."}
            else:
                cursor.execute(
                    "UPDATE alerta SET estado='atendida' WHERE id_alerta=%s",
                    (int(args.id_alerta),))
                conn.commit()
                result = {"success": True, "afectadas": cursor.rowcount}

        elif args.accion == 'eliminar':
            if not args.id_alerta:
                result = {"success": False, "message": "Se requiere id_alerta."}
            else:
                cursor.execute("DELETE FROM alerta WHERE id_alerta=%s",
                               (int(args.id_alerta),))
                conn.commit()
                result = {"success": True, "afectadas": cursor.rowcount}

        cursor.close()
        conn.close()
        print(json.dumps(result, ensure_ascii=False, default=str))

    except mysql.connector.Error as e:
        print(json.dumps({"success": False, "message": f"DB Error: {str(e)}"}))
    except Exception as e:
        print(json.dumps({"success": False, "message": f"Error: {str(e)}"}))


if __name__ == "__main__":
    run()