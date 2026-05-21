# main.py - Punto de entrada del backend :V

from flask import Flask, jsonify, request
from flask_cors import CORS
import mysql.connector
import os
from dotenv import load_dotenv

from dashboard import blueprint_dashboard
from alertas import blueprint_alertas
from importar import blueprint_importar


# Configuración inicial

load_dotenv()

app = Flask(__name__)
CORS(app)  # Permite peticiones  del frontend PHP

app.config["JSON_SORT_KEYS"] = False  # Mantiene orden de campos en respuestas


# Configuración de base de datos

DB_CONFIG = {
    "host":     os.getenv("DB_HOST", "localhost"),
    "port":     int(os.getenv("DB_PORT", 3306)), #verificar porfavor
    "user":     os.getenv("DB_USER", "root"),
    "password": os.getenv("DB_PASSWORD", ""),
    "database": os.getenv("DB_NAME", "textil_db"),
    "charset":  "utf8mb4",
}

def get_db_connection():

    try:
        conn = mysql.connector.connect(**DB_CONFIG)
        return conn
    except mysql.connector.Error as e:
        raise ConnectionError(f"No se pudo conectar a la base de datos!!!: {e}")


# Registro de  módulos

app.register_blueprint(blueprint_dashboard, url_prefix="/api/dashboard")
app.register_blueprint(blueprint_alertas,   url_prefix="/api/alertas")
app.register_blueprint(blueprint_importar,  url_prefix="/api/importar")

# Rutas base

@app.route("/")
def index():
    return jsonify({
        "sistema": "Gestión Textil",
        "version": "1.0.0",
        "estado":  "activo",
        "modulos": [
            "/api/dashboard",
            "/api/alertas",
            "/api/importar",
            "/api/clientes",
            "/api/pedidos",
            "/api/ordenes_corte",
            "/api/lineas",
        ]
    })


@app.route("/api/health")
def health_check():
    """Verifica que el servidor y la DB estén operativos."""
    try:
        conn = get_db_connection()
        conn.close()
        db_status = "conectada"
    except ConnectionError:
        db_status = "sin conexión"

    return jsonify({
        "servidor": "activo",
        "base_de_datos": db_status
    })



# Rutas de recursos principales y Operaciones CRUD  por entidad

# ── Clientes ─────────────────────────────────────────────────────────────────
@app.route("/api/clientes", methods=["GET"])
def get_clientes():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM clientes ORDER BY id DESC")
        clientes = cursor.fetchall()
        cursor.close()
        conn.close()
        return jsonify({"ok": True, "data": clientes})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/api/clientes/<int:cliente_id>", methods=["GET"])
def get_cliente(cliente_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM clientes WHERE id = %s", (cliente_id,))
        cliente = cursor.fetchone()
        cursor.close()
        conn.close()
        if not cliente:
            return jsonify({"ok": False, "error": "Cliente no encontrado"}), 404
        return jsonify({"ok": True, "data": cliente})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


# ── Pedidos ───────────────────────────────────────────────────────────────────
@app.route("/api/pedidos", methods=["GET"])
def get_pedidos():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT p.*, c.nombre AS cliente_nombre
            FROM pedidos p
            LEFT JOIN clientes c ON p.cliente_id = c.id
            ORDER BY p.id DESC
        """)
        pedidos = cursor.fetchall()
        cursor.close()
        conn.close()
        return jsonify({"ok": True, "data": pedidos})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/api/pedidos/<int:pedido_id>", methods=["GET"])
def get_pedido(pedido_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT p.*, c.nombre AS cliente_nombre
            FROM pedidos p
            LEFT JOIN clientes c ON p.cliente_id = c.id
            WHERE p.id = %s
        """, (pedido_id,))
        pedido = cursor.fetchone()
        cursor.close()
        conn.close()
        if not pedido:
            return jsonify({"ok": False, "error": "Pedido no encontrado"}), 404
        return jsonify({"ok": True, "data": pedido})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


# ── Órdenes de Corte ─────────────────────────────────────────────────────────
@app.route("/api/ordenes_corte", methods=["GET"])
def get_ordenes_corte():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT oc.*, p.descripcion AS pedido_descripcion
            FROM ordenes_corte oc
            LEFT JOIN pedidos p ON oc.pedido_id = p.id
            ORDER BY oc.id DESC
        """)
        ordenes = cursor.fetchall()
        cursor.close()
        conn.close()
        return jsonify({"ok": True, "data": ordenes})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


@app.route("/api/ordenes_corte/<int:orden_id>", methods=["GET"])
def get_orden_corte(orden_id):
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("""
            SELECT oc.*, p.descripcion AS pedido_descripcion
            FROM ordenes_corte oc
            LEFT JOIN pedidos p ON oc.pedido_id = p.id
            WHERE oc.id = %s
        """, (orden_id,))
        orden = cursor.fetchone()
        cursor.close()
        conn.close()
        if not orden:
            return jsonify({"ok": False, "error": "Orden de corte no encontrada"}), 404
        return jsonify({"ok": True, "data": orden})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500

# ── Líneas de producción ──────────────────────────────────────────────────────
@app.route("/api/lineas", methods=["GET"])
def get_lineas():
    try:
        conn = get_db_connection()
        cursor = conn.cursor(dictionary=True)
        cursor.execute("SELECT * FROM lineas ORDER BY id DESC")
        lineas = cursor.fetchall()
        cursor.close()
        conn.close()
        return jsonify({"ok": True, "data": lineas})
    except Exception as e:
        return jsonify({"ok": False, "error": str(e)}), 500


# Manejadores de errores globales

@app.errorhandler(404)
def not_found(e):
    return jsonify({"ok": False, "error": "Ruta no encontrada"}), 404


@app.errorhandler(405)
def method_not_allowed(e):
    return jsonify({"ok": False, "error": "Método HTTP no permitido"}), 405


@app.errorhandler(500)
def internal_error(e):
    return jsonify({"ok": False, "error": "Error interno del servidor"}), 500


# Inicio del servidor

if __name__ == "__main__":
    app.run(
        host="0.0.0.0",
        port=int(os.getenv("PORT", 5000)), #revisar porfavor
        debug=os.getenv("FLASK_DEBUG", "true").lower() == "true"
    )