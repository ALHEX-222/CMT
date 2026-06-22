import sys
import json
import openpyxl
import mysql.connector as mysql
from datetime import datetime

def conectar_bd():
    return mysql.connect(
        host="localhost",
        user="root",        
        password="",        
        database="cmt_costura"
    )

def procesar_archivo(ruta_archivo, modo="load"):
    resultado = {"success": False, "message": "", "ordenes": 0, "cortes": 0, "preview_data": []}
    conn = None
    
    try:
        wb = openpyxl.load_workbook(ruta_archivo, data_only=True)
        sheet = wb.active 
        
        datos_orden_pedido = {}
        datos_orden_corte = []
        
        conteo_oc_por_op = {} 
        orden_aparicion_op = []
        
        cabeceras = [str(cell.value).strip() if cell.value else "" for cell in sheet[1]]
        
        try:
            idx_op = cabeceras.index("OP")
            idx_corte = cabeceras.index("Corte")
            idx_fecha = cabeceras.index("Fecha")
            idx_tipo = cabeceras.index("Tipo Prenda")
            idx_produccion = cabeceras.index("Producción USD Real")
            idx_tiempo = cabeceras.index("Tiempo Estandar")
            idx_estilo = cabeceras.index("Estilo Cliente")
            idx_cliente = cabeceras.index("Cliente")
            
            idx_eficiencia = cabeceras.index("Eficiencia Production") if "Eficiencia Production" in cabeceras else (
                cabeceras.index("Eficiencia Production") if "Eficiencia Production" in cabeceras else cabeceras.index("Eficiencia Produccion")
            )
            idx_linea = cabeceras.index("Linea") if "Linea" in cabeceras else cabeceras.index("Línea")
        except ValueError as e:
            resultado["message"] = f"No se encontró la columna requerida en el Excel: {str(e)}"
            print(json.dumps(resultado))
            return

        for row in sheet.iter_rows(min_row=2, values_only=True):
            if not row[idx_op]: 
                continue 
            
            id_op = int(row[idx_op])
            id_oc = int(row[idx_corte])
            
            fecha_raw = row[idx_fecha]
            if isinstance(fecha_raw, datetime):
                fecha_str = fecha_raw.strftime('%Y-%m-%d')
            else:
                fecha_str = str(fecha_raw).split(" ")[0]
            
            tipo_prenda = str(row[idx_tipo])
            cantidad = float(row[idx_produccion] or 0)
            tiempo_estandar = float(row[idx_tiempo] or 0)
            estilo = str(row[idx_estilo])
            eficiencia = float(row[idx_eficiencia] or 0)
            nom_cliente = str(row[idx_cliente])
            linea = int(row[idx_linea])
            
            if modo == "preview":
                if id_op not in conteo_oc_por_op:
                    conteo_oc_por_op[id_op] = {
                        "op": id_op,
                        "cliente": nom_cliente,
                        "estilo": estilo,
                        "descripcion": tipo_prenda,
                        "cantidad": 0,
                        "fecha": fecha_str,
                        "divisiones": 0
                    }
                    orden_aparicion_op.append(id_op)
                
                conteo_oc_por_op[id_op]["cantidad"] += cantidad
                conteo_oc_por_op[id_op]["divisiones"] += 1
            else:
                if id_op not in datos_orden_pedido:
                    datos_orden_pedido[id_op] = {
                        "id_op": id_op,
                        "cantidad_prendas": 0,
                        "fecha_ingreso": fecha_str,
                        "descripcion": tipo_prenda,
                        "tiempo_estandar": tiempo_estandar,
                        "estilo": estilo,
                        "tasa_cumplimiento": eficiencia,
                        "nom_cliente": nom_cliente
                    }
                
                datos_orden_pedido[id_op]["cantidad_prendas"] += cantidad
                
                datos_orden_corte.append({
                    "id_oc": id_oc,
                    "fecha_corte": fecha_str,
                    "observacion": tipo_prenda,
                    "cantidad": cantidad,
                    "id_op": id_op,
                    "id_linea": linea
                })

        if modo == "preview":
            resultado["success"] = True
            resultado["preview_data"] = [conteo_oc_por_op[id] for id in orden_aparicion_op]
            resultado["message"] = "Vista previa generada con éxito."
            print(json.dumps(resultado))
            return

        conn = conectar_bd()
        cursor = conn.cursor()
        ordenes_subidas = 0
        cortes_subidos = 0
        
        for op in datos_orden_pedido.values():
            cursor.execute("SELECT id_cliente FROM CLIENTE WHERE nombre_cliente = %s", (op['nom_cliente'],))
            res_cliente = cursor.fetchone()
            
            if not res_cliente:
                raise ValueError(f"El cliente '{op['nom_cliente']}' de la OP {op['id_op']} no está registrado en la tabla CLIENTE.")
            
            id_cliente_bd = res_cliente[0]
            
            sql_op = """
                INSERT INTO ORDEN_PEDIDO (id_op, cantidad_prendas, fecha_ingreso, fecha_salida, estado, descripcion, tiempo_estandar, estilo, tasa_cumplimiento, id_cliente)
                VALUES (%s, %s, %s, NULL, 'Pendiente', %s, %s, %s, %s, %s)
                ON DUPLICATE KEY UPDATE cantidad_prendas = VALUES(cantidad_prendas);
            """
            cursor.execute(sql_op, (
                op['id_op'], op['cantidad_prendas'], op['fecha_ingreso'], 
                op['descripcion'], op['tiempo_estandar'], op['estilo'], op['tasa_cumplimiento'], id_cliente_bd
            ))
            ordenes_subidas += 1

        for oc in datos_orden_corte:
            cursor.execute("SELECT id_linea FROM LINEA WHERE num_linea = %s", (oc['id_linea'],))
            res_linea = cursor.fetchone()
            
            if res_linea:
                id_linea_bd = res_linea[0]
                sql_oc = """
                    INSERT INTO ORDEN_CORTE (id_oc, fecha_corte, observacion, cantidad, id_op, id_linea)
                    VALUES (%s, %s, %s, %s, %s, %s)
                    ON DUPLICATE KEY UPDATE cantidad = cantidad + VALUES(cantidad);
                """
                cursor.execute(sql_oc, (
                    oc['id_oc'], oc['fecha_corte'], oc['observacion'], oc['cantidad'], oc['id_op'], id_linea_bd
                ))
                cortes_subidos += 1
        
        conn.commit()
        resultado["success"] = True
        resultado["message"] = "Migración completada con éxito."
        resultado["ordenes"] = ordenes_subidas
        resultado["cortes"] = cortes_subidos

    except Exception as e:
        if conn:
            conn.rollback()
        resultado["success"] = False
        resultado["message"] = str(e)
    finally:
        if conn:
            cursor.close()
            conn.close()
            
    print(json.dumps(resultado))

if __name__ == "__main__":
    if len(sys.argv) > 1:
        ruta = sys.argv[1]
        modo_ejecucion = sys.argv[2] if len(sys.argv) > 2 else "load"
        procesar_archivo(ruta, modo_ejecucion)
    else:
        print(json.dumps({"success": False, "message": "No se proporcionaron argumentos suficientes."}))