"""
dashboard.py — CMT Backend
Genera JSON con todos los KPIs y datos para el dashboard.
Soporta filtros via argumentos de línea de comandos.
"""

import sys
import json
import os
import argparse

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

try:
    import mysql.connector
except ImportError:
    print(json.dumps({"success": False, "message": "mysql-connector-python no instalado."}))
    sys.exit(1)

DB_CONFIG = {
    "host":     "localhost",
    "user":     "root",
    "password": "",
    "database": "cmt_costura",
    "charset":  "utf8mb4"
}

parser = argparse.ArgumentParser()
parser.add_argument('--fecha_desde', default='')
parser.add_argument('--fecha_hasta', default='')
parser.add_argument('--id_cliente',  default='')
parser.add_argument('--estado',      default='')
args, _ = parser.parse_known_args()


def op_filters(alias='op'):
    """Devuelve (where_clause, params_list) para filtrar orden_pedido."""
    conds, params = [], []
    if args.fecha_desde:
        conds.append(f"{alias}.fecha_ingreso >= %s")
        params.append(args.fecha_desde)
    if args.fecha_hasta:
        conds.append(f"{alias}.fecha_ingreso <= %s")
        params.append(args.fecha_hasta)
    if args.id_cliente:
        try:
            conds.append(f"{alias}.id_cliente = %s")
            params.append(int(args.id_cliente))
        except ValueError:
            pass
    if args.estado:
        conds.append(f"{alias}.estado = %s")
        params.append(args.estado)
    where = ('WHERE ' + ' AND '.join(conds)) if conds else ''
    return where, params


def op_filters_and(alias='op'):
    """Devuelve (and_clause, params_list) para usar en WHERE ya existente."""
    conds, params = [], []
    if args.fecha_desde:
        conds.append(f"{alias}.fecha_ingreso >= %s")
        params.append(args.fecha_desde)
    if args.fecha_hasta:
        conds.append(f"{alias}.fecha_ingreso <= %s")
        params.append(args.fecha_hasta)
    if args.id_cliente:
        try:
            conds.append(f"{alias}.id_cliente = %s")
            params.append(int(args.id_cliente))
        except ValueError:
            pass
    if args.estado:
        conds.append(f"{alias}.estado = %s")
        params.append(args.estado)
    clause = (' AND ' + ' AND '.join(conds)) if conds else ''
    return clause, params


def run():
    try:
        conn   = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        result = {}

        where_base, params_base = op_filters('op')
        and_base,   params_and  = op_filters_and('op')

        cursor.execute("SELECT id_cliente, nombre_cliente FROM cliente ORDER BY nombre_cliente")
        result["clientes_lista"] = list(cursor.fetchall())

        cursor.execute(f"""
            SELECT
                COUNT(*)                                              AS total_ops,
                SUM(CASE WHEN estado='Pendiente'  THEN 1 ELSE 0 END) AS ops_pendientes,
                SUM(CASE WHEN estado='Completado' THEN 1 ELSE 0 END) AS ops_completadas,
                COALESCE(SUM(cantidad_prendas), 0)                   AS total_prendas,
                COALESCE(AVG(tasa_cumplimiento), 0)                  AS tasa_promedio
            FROM orden_pedido op
            {where_base}
        """, params_base)
        kpi_ops = cursor.fetchone()

        cursor.execute("SELECT COUNT(*) AS total FROM cliente")
        kpi_clientes = cursor.fetchone()

        cursor.execute("""
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN estado='Activa' THEN 1 ELSE 0 END) AS activas
            FROM linea
        """)
        kpi_lineas = cursor.fetchone()

        cursor.execute(f"""
            SELECT COUNT(*) AS total
            FROM orden_corte oc
            INNER JOIN orden_pedido op ON oc.id_op = op.id_op
            {where_base}
        """, params_base)
        kpi_oc = cursor.fetchone()

        cursor.execute(f"""
            SELECT COALESCE(SUM(oc.cantidad), 0) AS prendas_proceso
            FROM orden_corte oc
            INNER JOIN orden_pedido op ON oc.id_op = op.id_op
            WHERE op.estado = 'Pendiente' AND oc.id_linea IS NOT NULL
            {and_base}
        """, params_and)
        prendas_proceso = cursor.fetchone()

        result["kpis"] = {
            "total_ops":          int(kpi_ops["total_ops"]         or 0),
            "ops_pendientes":     int(kpi_ops["ops_pendientes"]    or 0),
            "ops_completadas":    int(kpi_ops["ops_completadas"]   or 0),
            "total_prendas":      float(kpi_ops["total_prendas"]   or 0),
            "total_clientes":     int(kpi_clientes["total"]        or 0),
            "total_lineas":       int(kpi_lineas["total"]          or 0),
            "lineas_activas":     int(kpi_lineas["activas"]        or 0),
            "total_oc":           int(kpi_oc["total"]              or 0),
            "prendas_en_proceso": float(prendas_proceso["prendas_proceso"] or 0),
            "tasa_promedio":      round(float(kpi_ops["tasa_promedio"] or 0), 2),
        }

        cursor.execute(f"""
            SELECT
                COALESCE(AVG(tasa_cumplimiento), 0)  AS promedio,
                COALESCE(MIN(tasa_cumplimiento), 0)  AS minimo,
                COALESCE(MAX(tasa_cumplimiento), 0)  AS maximo,
                SUM(CASE WHEN tasa_cumplimiento >= 85 THEN 1 ELSE 0 END) AS sobre_meta,
                COUNT(*)                              AS total_con_tasa
            FROM orden_pedido op
            {where_base}
        """, params_base)
        ef = cursor.fetchone()
        result["eficiencia"] = {
            "promedio":       round(float(ef["promedio"]    or 0), 2),
            "minimo":         round(float(ef["minimo"]      or 0), 2),
            "maximo":         round(float(ef["maximo"]      or 0), 2),
            "sobre_meta":     int(ef["sobre_meta"]          or 0),
            "total_con_tasa": int(ef["total_con_tasa"]      or 0),
        }

        where_tend = 'WHERE op.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)'
        if and_base:
            where_tend += and_base
        cursor.execute(f"""
            SELECT DATE_FORMAT(op.fecha_ingreso,'%Y-%m') AS mes,
                   DATE_FORMAT(op.fecha_ingreso,'%b %Y') AS mes_label,
                   COUNT(*)                              AS total,
                   SUM(CASE WHEN op.estado='Pendiente'  THEN 1 ELSE 0 END) AS pendientes,
                   SUM(CASE WHEN op.estado='Completado' THEN 1 ELSE 0 END) AS completadas,
                   COALESCE(SUM(op.cantidad_prendas), 0) AS prendas
            FROM orden_pedido op
            {where_tend}
            GROUP BY DATE_FORMAT(op.fecha_ingreso,'%Y-%m')
            ORDER BY mes ASC
        """, params_and)
        result["tendencia_mensual"] = [
            {**r, "total": int(r["total"]), "pendientes": int(r["pendientes"]),
             "completadas": int(r["completadas"]), "prendas": float(r["prendas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT c.nombre_cliente, c.id_cliente,
                   COUNT(op.id_op)                      AS total_ops,
                   COALESCE(SUM(op.cantidad_prendas),0) AS total_prendas,
                   SUM(CASE WHEN op.estado='Pendiente' THEN 1 ELSE 0 END) AS pendientes
            FROM cliente c
            LEFT JOIN orden_pedido op ON op.id_cliente = c.id_cliente
            {where_base}
            GROUP BY c.id_cliente
            ORDER BY total_prendas DESC
            LIMIT 8
        """, params_base)
        result["top_clientes"] = [
            {**r, "total_ops":     int(r["total_ops"]),
                  "total_prendas": float(r["total_prendas"]),
                  "pendientes":    int(r["pendientes"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT l.num_linea, l.estado,
                   COUNT(oc.id_oc)                AS total_oc,
                   COALESCE(SUM(oc.cantidad), 0)  AS total_prendas,
                   COUNT(DISTINCT oc.id_op)        AS ops_distintas
            FROM linea l
            LEFT JOIN orden_corte oc ON oc.id_linea = l.id_linea
            LEFT JOIN orden_pedido op ON op.id_op = oc.id_op
            {where_base}
            GROUP BY l.id_linea
            ORDER BY l.num_linea ASC
        """, params_base)
        result["distribucion_lineas"] = [
            {**r, "total_oc":      int(r["total_oc"]),
                  "total_prendas": float(r["total_prendas"]),
                  "ops_distintas": int(r["ops_distintas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT estado,
                   COUNT(*) AS cantidad,
                   COALESCE(SUM(cantidad_prendas),0) AS prendas
            FROM orden_pedido op
            {where_base}
            GROUP BY estado
        """, params_base)
        result["estado_ops"] = [
            {**r, "cantidad": int(r["cantidad"]), "prendas": float(r["prendas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT estilo,
                   COUNT(*) AS total_ops,
                   COALESCE(SUM(cantidad_prendas),0) AS total_prendas
            FROM orden_pedido op
            {where_base}
            GROUP BY estilo
            ORDER BY total_prendas DESC
            LIMIT 8
        """, params_base)
        result["top_estilos"] = [
            {**r, "total_ops":     int(r["total_ops"]),
                  "total_prendas": float(r["total_prendas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT op.id_op, op.estilo, op.descripcion, op.cantidad_prendas,
                   op.fecha_ingreso, op.estado, op.tasa_cumplimiento,
                   c.nombre_cliente,
                   COUNT(oc.id_oc) AS total_oc
            FROM orden_pedido op
            INNER JOIN cliente c ON op.id_cliente = c.id_cliente
            LEFT  JOIN orden_corte oc ON oc.id_op = op.id_op
            {where_base}
            GROUP BY op.id_op
            ORDER BY op.fecha_ingreso DESC
            LIMIT 10
        """, params_base)
        result["ops_recientes"] = [
            {**r, "cantidad_prendas":    float(r["cantidad_prendas"]),
                  "tasa_cumplimiento":   float(r["tasa_cumplimiento"] or 0),
                  "total_oc":            int(r["total_oc"]),
                  "fecha_ingreso":       str(r["fecha_ingreso"])}
            for r in cursor.fetchall()
        ]

        cursor.execute("""
            SELECT l.num_linea, l.id_linea, l.estado AS estado_linea,
                   l.num_operarios,
                   COUNT(DISTINCT oc.id_oc)      AS ocs_activas,
                   COALESCE(SUM(oc.cantidad), 0) AS carga_actual,
                   COUNT(DISTINCT oc.id_op)      AS ops_activas
            FROM linea l
            LEFT JOIN orden_corte oc ON oc.id_linea = l.id_linea
            LEFT JOIN orden_pedido op ON op.id_op = oc.id_op
                  AND op.estado = 'Pendiente'
            GROUP BY l.id_linea
            ORDER BY l.num_linea ASC
        """)
        result["carga_lineas"] = [
            {**r, "ocs_activas":  int(r["ocs_activas"]),
                  "carga_actual": float(r["carga_actual"]),
                  "ops_activas":  int(r["ops_activas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT DATE_FORMAT(op.fecha_ingreso,'%b')    AS mes_corto,
                   DATE_FORMAT(op.fecha_ingreso,'%Y-%m') AS mes_ord,
                   COALESCE(SUM(op.cantidad_prendas),0)  AS prendas
            FROM orden_pedido op
            WHERE op.fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            {and_base}
            GROUP BY DATE_FORMAT(op.fecha_ingreso,'%Y-%m')
            ORDER BY mes_ord ASC
        """, params_and)
        result["prendas_por_mes"] = [
            {**r, "prendas": float(r["prendas"])}
            for r in cursor.fetchall()
        ]

        cursor.execute(f"""
            SELECT l.num_linea,
                   COALESCE(AVG(op.tasa_cumplimiento), 0) AS ef_promedio,
                   COUNT(DISTINCT op.id_op)               AS ops_count
            FROM linea l
            LEFT JOIN orden_corte oc ON oc.id_linea = l.id_linea
            LEFT JOIN orden_pedido op ON op.id_op = oc.id_op
            {where_base}
            GROUP BY l.id_linea
            ORDER BY l.num_linea ASC
        """, params_base)
        result["ef_por_linea"] = [
            {**r, "ef_promedio": round(float(r["ef_promedio"] or 0), 2),
                  "ops_count":   int(r["ops_count"] or 0)}
            for r in cursor.fetchall()
        ]

        cursor.close()
        conn.close()

        result["success"] = True
        result["filtros_activos"] = {
            "fecha_desde": args.fecha_desde,
            "fecha_hasta": args.fecha_hasta,
            "id_cliente":  args.id_cliente,
            "estado":      args.estado,
        }
        print(json.dumps(result, ensure_ascii=False, default=str))

    except mysql.connector.Error as e:
        print(json.dumps({"success": False, "message": f"DB Error: {str(e)}"}))
    except Exception as e:
        print(json.dumps({"success": False, "message": f"Error: {str(e)}"}))

if __name__ == "__main__":
    run()