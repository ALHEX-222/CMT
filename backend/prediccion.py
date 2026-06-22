import sys
import json
import argparse
from datetime import datetime, timedelta

try:
    import mysql.connector
except ImportError:
    print(json.dumps({"success": False, "message": "mysql-connector-python no instalado."}))
    sys.exit(1)

DB_CONFIG = {
    "host": "localhost",
    "user": "root",
    "password": "",
    "database": "cmt_costura",
    "charset": "utf8mb4"
}

parser = argparse.ArgumentParser()
parser.add_argument('--fecha_desde', default='')
parser.add_argument('--fecha_hasta', default='')
parser.add_argument('--id_cliente', default='')
args, _ = parser.parse_known_args()

def run():
    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        cursor = conn.cursor(dictionary=True)
        result = {}

        where = []
        params = []
        if args.fecha_desde:
            where.append("op.fecha_ingreso >= %s")
            params.append(args.fecha_desde)
        if args.fecha_hasta:
            where.append("op.fecha_ingreso <= %s")
            params.append(args.fecha_hasta)
        if args.id_cliente:
            where.append("op.id_cliente = %s")
            params.append(int(args.id_cliente))

        where_clause = " WHERE " + " AND ".join(where) if where else ""

        cursor.execute(f"""
            SELECT COUNT(*) as total_ops,
                   COALESCE(SUM(cantidad_prendas), 0) as total_prendas,
                   COALESCE(AVG(tasa_cumplimiento), 0) as tasa_promedio
            FROM orden_pedido op {where_clause}
        """, params)
        hist = cursor.fetchone() or {}

        cursor.execute("""
            SELECT DATE_FORMAT(fecha_ingreso,'%Y-%m') as mes,
                   DATE_FORMAT(fecha_ingreso,'%b %Y') as mes_label,
                   SUM(cantidad_prendas) as prendas
            FROM orden_pedido
            WHERE fecha_ingreso >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY mes ORDER BY mes ASC
        """)
        historico = cursor.fetchall()

        crecimiento = 1.08
        if len(historico) >= 2:
            tasas = []
            for i in range(1, len(historico)):
                if historico[i-1]['prendas'] > 0:
                    tasas.append(historico[i]['prendas'] / historico[i-1]['prendas'])
            if tasas:
                crecimiento = sum(tasas) / len(tasas)

        prendas_mes = historico[-1]['prendas'] if historico else (hist.get('total_prendas', 0) / 6)
        proyeccion = []
        ultimo = datetime.now()
        for i in range(1, 4):
            mes_fut = (ultimo + timedelta(days=30*i)).strftime('%Y-%m')
            proj = round(prendas_mes * (crecimiento ** i))
            proyeccion.append({"mes": mes_fut, "prendas": proj, "tipo": "proyeccion"})

        result["predicciones"] = {
            "total_prendas_3meses": round(sum(p['prendas'] for p in proyeccion)),
            "total_ops_3meses": int(hist.get('total_ops', 0) * 1.12),
            "tasa_cumplimiento": round(float(hist.get('tasa_promedio') or 85.2), 1)
        }

        result["tendencia"] = [{**r, "tipo": "historico"} for r in historico] + proyeccion

        cursor.execute("""
            SELECT l.num_linea, COALESCE(SUM(oc.cantidad), 0) as carga_actual
            FROM linea l LEFT JOIN orden_corte oc ON oc.id_linea = l.id_linea
            GROUP BY l.num_linea ORDER BY l.num_linea
        """)
        result["lineas"] = cursor.fetchall()

        cursor.execute(f"""
            SELECT estilo, SUM(cantidad_prendas) as total
            FROM orden_pedido {where_clause}
            GROUP BY estilo ORDER BY total DESC LIMIT 6
        """, params)
        result["top_estilos"] = cursor.fetchall()

        result["success"] = True
        result["fecha_generacion"] = datetime.now().strftime("%Y-%m-%d %H:%M")

        print(json.dumps(result, ensure_ascii=False, default=str))

    except Exception as e:
        print(json.dumps({"success": False, "message": str(e)}))
    finally:
        if 'cursor' in locals(): cursor.close()
        if 'conn' in locals(): conn.close()

if __name__ == "__main__":
    run()