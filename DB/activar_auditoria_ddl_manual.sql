-- ╔════════════════════════════════════════════════════════════╗
-- ║  ACTIVACIÓN MANUAL - AUDITORÍA DDL AUTOMÁTICA             ║
-- ║  Ejecutar como SUPERUSUARIO (postgres)                    ║
-- ╚════════════════════════════════════════════════════════════╝

-- ⚠️ IMPORTANTE: Este script debe ejecutarse con un usuario SUPERUSER
-- Conectarse como: psql -U postgres -d rrhh_ministeriopublico

-- ═══════════════════════════════════════════════════════════
-- CONFIGURACIÓN (ajustar si es necesario)
-- ═══════════════════════════════════════════════════════════
DO $$
DECLARE
    v_esquema_origen TEXT := 'public';
    v_esquema_auditoria TEXT := 'auditoria';
    v_modo_seguro BOOLEAN := TRUE;
BEGIN
    RAISE NOTICE 'Configuración:';
    RAISE NOTICE '  Esquema Origen: %', v_esquema_origen;
    RAISE NOTICE '  Esquema Auditoría: %', v_esquema_auditoria;
    RAISE NOTICE '  Modo Seguro: %', v_modo_seguro;
END $$;

-- ═══════════════════════════════════════════════════════════
-- PASO 1: Crear esquema de auditoría (si no existe)
-- ═══════════════════════════════════════════════════════════
CREATE SCHEMA IF NOT EXISTS auditoria;

-- ═══════════════════════════════════════════════════════════
-- PASO 2: Eliminar trigger anterior (si existe)
-- ═══════════════════════════════════════════════════════════
DROP EVENT TRIGGER IF EXISTS trg_ddl_auditoria_automatica CASCADE;

-- ═══════════════════════════════════════════════════════════
-- PASO 3: Crear/Reemplazar función de auditoría
-- ═══════════════════════════════════════════════════════════
CREATE OR REPLACE FUNCTION auditoria.fn_ddl_auditoria_automatica()
RETURNS event_trigger
LANGUAGE plpgsql
AS $$
DECLARE
    obj record;
    esquema_origen TEXT := 'public';
    esquema_aud TEXT := 'auditoria';
    modo_seguro BOOLEAN := TRUE;
    tabla_nombre TEXT;
    tabla_completa TEXT;
    col_nombre TEXT;
    col_tipo TEXT;
    comando TEXT;
    nuevo_nombre TEXT;
BEGIN
    -- Log de inicio
    RAISE NOTICE '═══ EVENT TRIGGER DISPARADO ═══';
    RAISE NOTICE 'Comando: %', current_query();
    
    -- Obtener información del objeto afectado
    FOR obj IN SELECT * FROM pg_event_trigger_ddl_commands()
    LOOP
        RAISE NOTICE 'Objeto detectado: schema=%, tipo=%, identidad=%', 
            obj.schema_name, obj.object_type, obj.object_identity;
        
        -- Solo procesar si es una tabla del esquema origen
        IF obj.schema_name = esquema_origen AND obj.object_type = 'table' THEN
            tabla_completa := obj.object_identity;
            
            -- Extraer solo el nombre de la tabla (sin esquema)
            tabla_nombre := regexp_replace(tabla_completa, '^.*\."?([^"]+)"?$', '\1');
            
            RAISE NOTICE 'Tabla procesada: % (completa: %)', tabla_nombre, tabla_completa;
            
            -- Obtener el comando SQL ejecutado
            comando := current_query();
            
            -- Verificar que la tabla existe en auditoría
            IF NOT EXISTS (
                SELECT 1 FROM information_schema.tables 
                WHERE table_schema = esquema_aud 
                AND table_name = tabla_nombre
            ) THEN
                RAISE WARNING 'AUDITORIA: La tabla %.% no existe en auditoría. Ejecute sincronización manual primero.', 
                    esquema_aud, tabla_nombre;
                RETURN;
            END IF;
            
            -- Detectar ADD COLUMN
            IF comando ILIKE '%ADD COLUMN%' OR comando ILIKE '%ADD%COLUMN%' THEN
                col_nombre := substring(comando from 'ADD\s+COLUMN\s+"?([A-Za-z0-9_]+)"?');
                col_tipo := substring(comando from 'ADD\s+COLUMN\s+"?[A-Za-z0-9_]+"?\s+([A-Z]+[^,;\)\s]*)');
                
                RAISE NOTICE 'ADD COLUMN detectado: columna=%, tipo=%', col_nombre, col_tipo;
                
                IF col_nombre IS NOT NULL AND col_tipo IS NOT NULL THEN
                    EXECUTE format(
                        'ALTER TABLE %I.%I ADD COLUMN IF NOT EXISTS %I %s',
                        esquema_aud, tabla_nombre, col_nombre, col_tipo
                    );
                    
                    RAISE NOTICE '✓ AUDITORIA: Columna "%" agregada a %.%', col_nombre, esquema_aud, tabla_nombre;
                ELSE
                    RAISE WARNING 'No se pudo extraer nombre o tipo de columna';
                END IF;
                
            -- Detectar DROP COLUMN
            ELSIF comando ILIKE '%DROP COLUMN%' AND modo_seguro THEN
                col_nombre := substring(comando from 'DROP\s+COLUMN\s+"?([A-Za-z0-9_]+)"?');
                
                RAISE NOTICE 'DROP COLUMN detectado: columna=%', col_nombre;
                
                IF col_nombre IS NOT NULL THEN
                    IF EXISTS (
                        SELECT 1 FROM information_schema.columns 
                        WHERE table_schema = esquema_aud 
                        AND table_name = tabla_nombre 
                        AND column_name = col_nombre
                    ) THEN
                        EXECUTE format(
                            'ALTER TABLE %I.%I RENAME COLUMN %I TO %I',
                            esquema_aud, tabla_nombre, col_nombre, col_nombre || '_deleted'
                        );
                        
                        RAISE NOTICE '✓ AUDITORIA: Columna "%" renombrada a "%_deleted"', col_nombre, col_nombre;
                    ELSE
                        RAISE NOTICE 'Columna % no existe en auditoría, omitiendo', col_nombre;
                    END IF;
                END IF;
                
            -- Detectar ALTER COLUMN TYPE
            ELSIF comando ILIKE '%ALTER COLUMN%TYPE%' OR comando ILIKE '%ALTER%TYPE%' THEN
                col_nombre := substring(comando from 'ALTER\s+COLUMN\s+"?([A-Za-z0-9_]+)"?');
                col_tipo := substring(comando from 'TYPE\s+([A-Z]+[^,;\s]*)');
                
                RAISE NOTICE 'ALTER COLUMN TYPE detectado: columna=%, tipo=%', col_nombre, col_tipo;
                
                IF col_nombre IS NOT NULL AND col_tipo IS NOT NULL THEN
                    BEGIN
                        EXECUTE format(
                            'ALTER TABLE %I.%I ALTER COLUMN %I TYPE %s USING %I::%s',
                            esquema_aud, tabla_nombre, col_nombre, col_tipo, col_nombre, col_tipo
                        );
                        
                        RAISE NOTICE '✓ AUDITORIA: Tipo de columna "%" modificado a %', col_nombre, col_tipo;
                    EXCEPTION
                        WHEN OTHERS THEN
                            RAISE WARNING 'Error al cambiar tipo de columna: %', SQLERRM;
                    END;
                END IF;
                
            -- Detectar RENAME COLUMN
            ELSIF comando ILIKE '%RENAME COLUMN%' THEN
                col_nombre := substring(comando from 'RENAME\s+COLUMN\s+"?([A-Za-z0-9_]+)"?');
                nuevo_nombre := substring(comando from 'TO\s+"?([A-Za-z0-9_]+)"?');
                
                RAISE NOTICE 'RENAME COLUMN detectado: de=% a=%', col_nombre, nuevo_nombre;
                
                IF col_nombre IS NOT NULL AND nuevo_nombre IS NOT NULL THEN
                    EXECUTE format(
                        'ALTER TABLE %I.%I RENAME COLUMN %I TO %I',
                        esquema_aud, tabla_nombre, col_nombre, nuevo_nombre
                    );
                    
                    RAISE NOTICE '✓ AUDITORIA: Columna "%" renombrada a "%"', col_nombre, nuevo_nombre;
                END IF;
            ELSE
                RAISE NOTICE 'Operación no reconocida o no soportada';
            END IF;
        ELSE
            RAISE NOTICE 'Objeto ignorado (no es tabla del esquema origen)';
        END IF;
    END LOOP;
    
    RAISE NOTICE '═══ EVENT TRIGGER FINALIZADO ═══';
EXCEPTION
    WHEN OTHERS THEN
        RAISE WARNING '✗ Error en auditoría automática: %', SQLERRM;
        RAISE NOTICE 'SQLSTATE: %, CONTEXT: %', SQLSTATE, pg_exception_context();
END;
$$;

-- ═══════════════════════════════════════════════════════════
-- PASO 4: Crear Event Trigger
-- ═══════════════════════════════════════════════════════════
CREATE EVENT TRIGGER trg_ddl_auditoria_automatica
ON ddl_command_end
WHEN TAG IN ('ALTER TABLE')
EXECUTE FUNCTION auditoria.fn_ddl_auditoria_automatica();

-- ═══════════════════════════════════════════════════════════
-- PASO 5: Verificar instalación
-- ═══════════════════════════════════════════════════════════
SELECT 
    '✓ EVENT TRIGGER INSTALADO' as resultado,
    evtname as nombre,
    CASE evtenabled 
        WHEN 'O' THEN '✓ ACTIVO' 
        ELSE '✗ INACTIVO'
    END as estado
FROM pg_event_trigger
WHERE evtname = 'trg_ddl_auditoria_automatica';

-- ═══════════════════════════════════════════════════════════
-- PASO 6: Habilitar mensajes de debug
-- ═══════════════════════════════════════════════════════════
SET client_min_messages = 'NOTICE';

-- ═══════════════════════════════════════════════════════════
-- PASO 7: PRUEBA
-- ═══════════════════════════════════════════════════════════
-- Ahora prueba agregar una columna a una tabla existente:
-- ALTER TABLE public.categorias ADD COLUMN test_columna VARCHAR(100);
-- Deberías ver NOTICES en la salida

RAISE NOTICE '═══════════════════════════════════════════════════';
RAISE NOTICE '✓ AUDITORÍA DDL AUTOMÁTICA ACTIVADA CORRECTAMENTE';
RAISE NOTICE '═══════════════════════════════════════════════════';
RAISE NOTICE 'Ahora ejecuta: ALTER TABLE public.categorias ADD COLUMN test_col VARCHAR(50);';
RAISE NOTICE 'Y verifica que se agregó en auditoria.categorias';
