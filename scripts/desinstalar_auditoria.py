import psycopg2

# Configuración de conexión
# Configuración de Base de Datos
DB_NAME="rrhh-ministeriopublico"
DB_SCHEMA="public"
DB_USER="postgres"
DB_PASSWORD=""
DB_HOST="190.104.183.209"
DB_PORT="21732"
conn = psycopg2.connect(
    dbname=DB_NAME,  # Cambia por tu base
    user=DB_USER,                 # Cambia por tu usuario
    password=DB_PASSWORD,          # Cambia por tu password
    host=DB_HOST,
    port=DB_PORT
)
conn.autocommit = True
cur = conn.cursor()

# Obtener todas las tablas del esquema public
cur.execute(f"""
    SELECT table_name FROM information_schema.tables
    WHERE table_schema = '{DB_SCHEMA}' AND table_type = 'BASE TABLE'
""")
tablas = [row[0] for row in cur.fetchall()]

for tabla in tablas:
    # Eliminar triggers de auditoría
    for tipo in ['insert', 'update', 'delete']:
        trigger = f"trg_auditoria_{tipo}"
        try:
            cur.execute(f"DROP TRIGGER IF EXISTS {trigger} ON public.\"{tabla}\"")
        except Exception as e:
            print(f"Error eliminando trigger {trigger} en {tabla}: {e}")
    # Eliminar función de auditoría
    func = f"fn_auditoria_{tabla}"
    try:
        cur.execute(f"DROP FUNCTION IF EXISTS auditoria.\"{func}\"() CASCADE")
    except Exception as e:
        print(f"Error eliminando función {func}: {e}")

# Eliminar event trigger global
try:
    cur.execute("DROP EVENT TRIGGER IF EXISTS trg_ddl_auditoria_automatica")
except Exception as e:
    print(f"Error eliminando event trigger global: {e}")

cur.close()
conn.close()
print("Auditoría desinstalada correctamente.")
