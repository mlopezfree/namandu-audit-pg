<?php
require_once __DIR__ . '/../vendor/autoload.php';
include "funciones.php";
include "funciones/Auditoria.php";

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'verificar_admin':
        verificarAdmin();
        break;
        
    case 'ejecutar_auditoria':
        ejecutarAuditoriaAutomatica();
        break;
    
    case 'activar_auditoria_automatica':
        activarAuditoriaAutomatica();
        break;
        
    case 'desactivar_auditoria_automatica':
        desactivarAuditoriaAutomatica();
        break;
        
    case 'verificar_estado_auditoria':
        verificarEstadoAuditoria();
        break;
    
    default:
        echo json_encode(['status' => 'error', 'mensaje' => 'Acción no válida']);
        break;
}

function verificarAdmin() {
    global $auth;
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        $password = $data['password'] ?? '';
        
        if (empty($password)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'Contraseña requerida']);
            return;
        }
        
        // Verificar que es admin
        if (!$auth->hasRole(\Delight\Auth\Role::ADMIN)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No tiene permisos de administrador']);
            return;
        }
        
        // Obtener email del usuario actual
        $userId = $auth->getUserId();
        $email = $auth->getEmail();
        
        // Verificar la contraseña
        try {
            $auth->login($email, $password);
            
            echo json_encode([
                'status' => 'success',
                'mensaje' => 'Contraseña verificada correctamente',
                'es_admin' => true
            ]);
            
        } catch (\Delight\Auth\InvalidPasswordException $e) {
            echo json_encode([
                'status' => 'error',
                'mensaje' => 'Contraseña incorrecta'
            ]);
        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'mensaje' => 'Error al verificar contraseña: ' . $e->getMessage()
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'Error en verificación: ' . $e->getMessage()
        ]);
    }
}

function verificarEstadoAuditoria() {
    $db = DataBase::conectar();
    
    try {
        // Verificar si existe el event trigger
        $db->setQuery("
            SELECT COUNT(*) as existe
            FROM pg_event_trigger
            WHERE evtname = 'trg_ddl_auditoria_automatica'
        ");
        
        $result = $db->loadObject();
        $activo = $result && $result->existe > 0;
        
        echo json_encode([
            'status' => 'success',
            'activo' => $activo
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'Error al verificar estado: ' . $e->getMessage()
        ]);
    }
}

function activarAuditoriaAutomatica() {
    global $auth;
    $db = DataBase::conectar();
    
    try {
        // Verificar permisos de admin
        if (!$auth->hasRole(\Delight\Auth\Role::ADMIN)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No tiene permisos de administrador']);
            return;
        }
        
        $data = json_decode(file_get_contents('php://input'), true);
        $esquema_origen = $data['esquema_origen'] ?? 'public';
        $esquema_auditoria = $data['esquema_auditoria'] ?? 'auditoria';
        $modo_seguro = $data['modo_seguro'] ?? true;
        
        $log = [];
        
        $db->beginTransaction();
        
        $log[] = ['type' => 'step', 'message' => '═══ ACTIVANDO AUDITORÍA AUTOMÁTICA DDL ═══'];
        
        // Crear función que manejará los eventos DDL
        $function_ddl = "
CREATE OR REPLACE FUNCTION {$esquema_auditoria}.fn_ddl_auditoria_automatica()
RETURNS event_trigger
LANGUAGE plpgsql
AS \$\$
DECLARE
    obj record;
    esquema_origen TEXT := '{$esquema_origen}';
    esquema_aud TEXT := '{$esquema_auditoria}';
    modo_seguro BOOLEAN := " . ($modo_seguro ? 'TRUE' : 'FALSE') . ";
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
            -- Si tiene comillas: \"public\".\"categorias\" -> categorias
            -- Si no: public.categorias -> categorias
            tabla_nombre := regexp_replace(tabla_completa, '^.*\\.\"?([^\"]+)\"?$', '\\1');
            
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
            
            -- Detectar tipo de operación
            IF comando ILIKE '%ADD COLUMN%' THEN
                -- Extraer nombre y tipo de columna del comando
                -- Soporta: ADD COLUMN nombre tipo, ADD COLUMN \"nombre\" tipo
                col_nombre := substring(comando from 'ADD\\s+COLUMN\\s+\"?([A-Za-z0-9_]+)\"?');
                col_tipo := substring(comando from 'ADD\\s+COLUMN\\s+\"?[A-Za-z0-9_]+\"?\\s+([A-Z]+[^,;\\)\\s]*)');
                
                RAISE NOTICE 'ADD COLUMN detectado: columna=%, tipo=%', col_nombre, col_tipo;
                
                IF col_nombre IS NOT NULL AND col_tipo IS NOT NULL THEN
                    -- Agregar columna en tabla de auditoría si no existe
                    EXECUTE format(
                        'ALTER TABLE %I.%I ADD COLUMN IF NOT EXISTS %I %s',
                        esquema_aud, tabla_nombre, col_nombre, col_tipo
                    );
                    
                    RAISE NOTICE '✓ AUDITORIA: Columna \"%\" agregada a %.%', col_nombre, esquema_aud, tabla_nombre;
                ELSE
                    RAISE WARNING 'No se pudo extraer nombre o tipo de columna del comando';
                END IF;
                
            ELSIF comando ILIKE '%DROP COLUMN%' AND modo_seguro THEN
                -- Extraer nombre de columna
                col_nombre := substring(comando from 'DROP\\s+COLUMN\\s+\"?([A-Za-z0-9_]+)\"?');
                
                RAISE NOTICE 'DROP COLUMN detectado: columna=%', col_nombre;
                
                IF col_nombre IS NOT NULL THEN
                    -- Verificar que la columna existe antes de renombrar
                    IF EXISTS (
                        SELECT 1 FROM information_schema.columns 
                        WHERE table_schema = esquema_aud 
                        AND table_name = tabla_nombre 
                        AND column_name = col_nombre
                    ) THEN
                        -- Renombrar en lugar de eliminar
                        EXECUTE format(
                            'ALTER TABLE %I.%I RENAME COLUMN %I TO %I',
                            esquema_aud, tabla_nombre, col_nombre, col_nombre || '_deleted'
                        );
                        
                        RAISE NOTICE '✓ AUDITORIA: Columna \"%\" renombrada a \"%_deleted\" en %.%', 
                            col_nombre, col_nombre, esquema_aud, tabla_nombre;
                    ELSE
                        RAISE NOTICE 'Columna % no existe en %.%, omitiendo', col_nombre, esquema_aud, tabla_nombre;
                    END IF;
                END IF;
                
            ELSIF comando ILIKE '%ALTER COLUMN%TYPE%' OR comando ILIKE '%ALTER%TYPE%' THEN
                -- Extraer nombre y nuevo tipo
                col_nombre := substring(comando from 'ALTER\\s+COLUMN\\s+\"?([A-Za-z0-9_]+)\"?');
                col_tipo := substring(comando from 'TYPE\\s+([A-Z]+[^,;\\s]*)');
                
                RAISE NOTICE 'ALTER COLUMN TYPE detectado: columna=%, tipo=%', col_nombre, col_tipo;
                
                IF col_nombre IS NOT NULL AND col_tipo IS NOT NULL THEN
                    BEGIN
                        -- Cambiar tipo en auditoría
                        EXECUTE format(
                            'ALTER TABLE %I.%I ALTER COLUMN %I TYPE %s USING %I::%s',
                            esquema_aud, tabla_nombre, col_nombre, col_tipo, col_nombre, col_tipo
                        );
                        
                        RAISE NOTICE '✓ AUDITORIA: Tipo de columna \"%\" modificado a % en %.%', 
                            col_nombre, col_tipo, esquema_aud, tabla_nombre;
                    EXCEPTION
                        WHEN OTHERS THEN
                            RAISE WARNING 'Error al cambiar tipo de columna: %', SQLERRM;
                    END;
                END IF;
                
            ELSIF comando ILIKE '%RENAME COLUMN%' THEN
                -- Extraer nombres viejo y nuevo
                col_nombre := substring(comando from 'RENAME\\s+COLUMN\\s+\"?([A-Za-z0-9_]+)\"?');
                nuevo_nombre := substring(comando from 'TO\\s+\"?([A-Za-z0-9_]+)\"?');
                
                RAISE NOTICE 'RENAME COLUMN detectado: de=% a=%', col_nombre, nuevo_nombre;
                
                IF col_nombre IS NOT NULL AND nuevo_nombre IS NOT NULL THEN
                    EXECUTE format(
                        'ALTER TABLE %I.%I RENAME COLUMN %I TO %I',
                        esquema_aud, tabla_nombre, col_nombre, nuevo_nombre
                    );
                    
                    RAISE NOTICE '✓ AUDITORIA: Columna \"%\" renombrada a \"%\" en %.%', 
                        col_nombre, nuevo_nombre, esquema_aud, tabla_nombre;
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
\$\$;
        ";
        
        $db->setQuery($function_ddl);
        $db->alter();
        $log[] = ['type' => 'success', 'message' => '✓ Función DDL creada'];
        
        // Eliminar event trigger anterior si existe
        $db->setQuery("DROP EVENT TRIGGER IF EXISTS trg_ddl_auditoria_automatica");
        $db->alter();
        
        // Crear event trigger que se dispara DESPUÉS de comandos DDL
        $trigger_ddl = "
CREATE EVENT TRIGGER trg_ddl_auditoria_automatica
ON ddl_command_end
WHEN TAG IN ('ALTER TABLE')
EXECUTE FUNCTION {$esquema_auditoria}.fn_ddl_auditoria_automatica();
        ";
        
        $db->setQuery($trigger_ddl);
        $db->alter();
        $log[] = ['type' => 'success', 'message' => '✓ Event Trigger DDL creado'];
        
        $db->commit();
        
        $log[] = ['type' => 'step', 'message' => '\n═══════════════════════════════════════'];
        $log[] = ['type' => 'success', 'message' => '✓ AUDITORÍA AUTOMÁTICA ACTIVADA'];
        $log[] = ['type' => 'info', 'message' => '→ Los cambios DDL en "' . $esquema_origen . '" se replicarán automáticamente'];
        $log[] = ['type' => 'step', 'message' => '═══════════════════════════════════════'];
        
        echo json_encode([
            'status' => 'success',
            'mensaje' => 'Auditoría automática DDL activada correctamente',
            'log' => $log
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'Error al activar auditoría automática: ' . $e->getMessage(),
            'log' => $log ?? []
        ]);
    }
}

function desactivarAuditoriaAutomatica() {
    global $auth;
    $db = DataBase::conectar();
    
    try {
        // Verificar permisos de admin
        if (!$auth->hasRole(\Delight\Auth\Role::ADMIN)) {
            echo json_encode(['status' => 'error', 'mensaje' => 'No tiene permisos de administrador']);
            return;
        }
        
        $db->setQuery("DROP EVENT TRIGGER IF EXISTS trg_ddl_auditoria_automatica");
        $db->alter();
        
        echo json_encode([
            'status' => 'success',
            'mensaje' => 'Auditoría automática DDL desactivada correctamente'
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'Error al desactivar auditoría automática: ' . $e->getMessage()
        ]);
    }
}

function ejecutarAuditoriaAutomatica() {
    $db    = DataBase::conectar();
    
    try {
        $data = json_decode(file_get_contents('php://input'), true);
        
        $esquema_origen = $data['esquema_origen'] ?? 'public';
        $esquema_auditoria = $data['esquema_auditoria'] ?? 'auditoria';
        $crear_triggers = $data['crear_triggers'] ?? true;
        $modo_seguro = $data['modo_seguro'] ?? true;
        
        $log = [];
        $stats = [
            'tablas' => 0,
            'columnas' => 0,
            'triggers' => 0
        ];
        
        $db->beginTransaction();
        
        // **PASO 1: Crear esquema de auditoría**
        $log[] = ['type' => 'step', 'message' => "═══ PASO 1: Creando esquema de auditoría ═══"];
        
        $db->setQuery("CREATE SCHEMA IF NOT EXISTS {$esquema_auditoria}");
        $db->alter();
        $log[] = ['type' => 'success', 'message' => "✓ Esquema '{$esquema_auditoria}' verificado/creado"];
        
        // **PASO 2: Obtener todas las tablas del esquema origen**
        $log[] = ['type' => 'step', 'message' => "\n═══ PASO 2: Obteniendo tablas del esquema '{$esquema_origen}' ═══"];
        
        $db->setQuery("
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = :esquema 
            AND table_type = 'BASE TABLE'
            ORDER BY table_name
        ", [':esquema' => $esquema_origen]);
        
        $tablas = $db->loadObjectList();
        $log[] = ['type' => 'info', 'message' => "→ Encontradas " . count($tablas) . " tablas para procesar"];
        
        // **PASO 3: Procesar cada tabla**
        foreach ($tablas as $tabla) {
            $tabla_nombre = $tabla->table_name;
            $log[] = ['type' => 'step', 'message' => "\n--- Procesando tabla: {$tabla_nombre} ---"];
            
            // 3.1: Obtener estructura de la tabla origen
            $db->setQuery("
                SELECT 
                    column_name,
                    data_type,
                    character_maximum_length,
                    is_nullable,
                    column_default
                FROM information_schema.columns
                WHERE table_schema = :esquema
                AND table_name = :tabla
                ORDER BY ordinal_position
            ", [':esquema' => $esquema_origen, ':tabla' => $tabla_nombre]);
            
            $columnas_origen = $db->loadObjectList();
            
            // 3.2: Verificar si la tabla existe en auditoría
            $db->setQuery("
                SELECT EXISTS (
                    SELECT 1 
                    FROM information_schema.tables 
                    WHERE table_schema = :esquema_aud 
                    AND table_name = :tabla
                ) as existe
            ", [':esquema_aud' => $esquema_auditoria, ':tabla' => $tabla_nombre]);
            
            $result = $db->loadObject();
            $existe_en_auditoria = $result ? $result->existe : false;
            
            if (!$existe_en_auditoria) {
                // **Crear tabla en auditoría**
                $log[] = ['type' => 'warning', 'message' => "  ⚠ Tabla no existe en auditoría, creando..."];
                
                $columnas_sql = [];
                foreach ($columnas_origen as $col) {
                    $tipo = $col->data_type;
                    if ($col->character_maximum_length) {
                        $tipo .= "({$col->character_maximum_length})";
                    }
                    // Escapar nombres de columnas con comillas dobles para evitar palabras reservadas
                    $columnas_sql[] = "\"{$col->column_name}\" {$tipo}";
                }
                
                // Agregar columnas de auditoría
                $columnas_sql[] = "auditoria_id SERIAL PRIMARY KEY";
                $columnas_sql[] = "auditoria_operacion VARCHAR(10)";
                $columnas_sql[] = "auditoria_usuario VARCHAR(100)";
                $columnas_sql[] = "auditoria_fecha TIMESTAMP DEFAULT CURRENT_TIMESTAMP";
                $columnas_sql[] = "auditoria_ip VARCHAR(50)";
                
                $create_sql = "CREATE TABLE {$esquema_auditoria}.\"{$tabla_nombre}\" (\n  " . 
                              implode(",\n  ", $columnas_sql) . "\n)";
                
                $db->setQuery($create_sql);
                $db->alter();
                
                $log[] = ['type' => 'success', 'message' => "  ✓ Tabla creada con " . count($columnas_origen) . " columnas"];
                $stats['columnas'] += count($columnas_origen);
                
            } else {
                // **Sincronizar columnas existentes**
                $log[] = ['type' => 'info', 'message' => "  → Tabla existe, sincronizando columnas..."];
                
                // Obtener columnas actuales en auditoría
                $db->setQuery("
                    SELECT column_name, data_type, character_maximum_length
                    FROM information_schema.columns
                    WHERE table_schema = :esquema_aud
                    AND table_name = :tabla
                    AND column_name NOT LIKE 'auditoria_%'
                ", [':esquema_aud' => $esquema_auditoria, ':tabla' => $tabla_nombre]);
                
                $columnas_auditoria = $db->loadObjectList();
                $columnas_aud_map = [];
                foreach ($columnas_auditoria as $col) {
                    $columnas_aud_map[$col->column_name] = $col;
                }
                
                $columnas_agregadas = 0;
                $columnas_modificadas = 0;
                
                // Comparar y agregar nuevas columnas
                foreach ($columnas_origen as $col_origen) {
                    $nombre_col = $col_origen->column_name;
                    
                    if (!isset($columnas_aud_map[$nombre_col])) {
                        // **Columna nueva - agregar**
                        $tipo = $col_origen->data_type;
                        if ($col_origen->character_maximum_length) {
                            $tipo .= "({$col_origen->character_maximum_length})";
                        }
                        
                        $alter_sql = "ALTER TABLE {$esquema_auditoria}.\"{$tabla_nombre}\" " .
                                    "ADD COLUMN \"{$nombre_col}\" {$tipo}";
                        
                        $db->setQuery($alter_sql);
                        $db->alter();
                        
                        $log[] = ['type' => 'success', 'message' => "    ✓ Columna agregada: {$nombre_col} ({$tipo})"];
                        $columnas_agregadas++;
                        
                    } else {
                        // **Columna existe - verificar tipo**
                        $col_aud = $columnas_aud_map[$nombre_col];
                        $tipo_origen = $col_origen->data_type;
                        $tipo_aud = $col_aud->data_type;
                        
                        if ($tipo_origen !== $tipo_aud) {
                            $tipo_completo = $tipo_origen;
                            if ($col_origen->character_maximum_length) {
                                $tipo_completo .= "({$col_origen->character_maximum_length})";
                            }
                            
                            try {
                                $alter_sql = "ALTER TABLE {$esquema_auditoria}.\"{$tabla_nombre}\" " .
                                            "ALTER COLUMN \"{$nombre_col}\" TYPE {$tipo_completo} " .
                                            "USING \"{$nombre_col}\"::{$tipo_completo}";
                                
                                $db->setQuery($alter_sql);
                                $db->alter();
                                
                                $log[] = ['type' => 'warning', 'message' => "    ⚠ Tipo modificado: {$nombre_col} ({$tipo_aud} → {$tipo_origen})"];
                                $columnas_modificadas++;
                                
                            } catch (Exception $e) {
                                $log[] = ['type' => 'error', 'message' => "    ✗ Error al modificar {$nombre_col}: " . $e->getMessage()];
                            }
                        }
                    }
                    
                    unset($columnas_aud_map[$nombre_col]);
                }
                
                // **Columnas eliminadas en origen**
                if ($modo_seguro && count($columnas_aud_map) > 0) {
                    foreach ($columnas_aud_map as $nombre_col => $col_aud) {
                        if (!str_ends_with($nombre_col, '_deleted')) {
                            $nuevo_nombre = $nombre_col . '_deleted';
                            
                            $db->setQuery("ALTER TABLE {$esquema_auditoria}.\"{$tabla_nombre}\" " .
                                         "RENAME COLUMN \"{$nombre_col}\" TO \"{$nuevo_nombre}\"");
                            $db->alter();
                            
                            $log[] = ['type' => 'warning', 'message' => "    ⚠ Columna eliminada renombrada: {$nombre_col} → {$nuevo_nombre}"];
                        }
                    }
                }
                
                $stats['columnas'] += $columnas_agregadas + $columnas_modificadas;
                
                if ($columnas_agregadas > 0 || $columnas_modificadas > 0) {
                    $log[] = ['type' => 'success', 'message' => "  ✓ Sincronización: +{$columnas_agregadas} nuevas, ~{$columnas_modificadas} modificadas"];
                } else {
                    $log[] = ['type' => 'success', 'message' => "  ✓ Tabla ya sincronizada"];
                }
            }
            
            // **PASO 4: Crear/actualizar triggers**
            if ($crear_triggers) {
                $log[] = ['type' => 'info', 'message' => "  → Creando triggers..."];
                
                // Eliminar triggers existentes
                $db->setQuery("DROP TRIGGER IF EXISTS trg_auditoria_insert ON {$esquema_origen}.\"{$tabla_nombre}\"");
                $db->alter();
                $db->setQuery("DROP TRIGGER IF EXISTS trg_auditoria_update ON {$esquema_origen}.\"{$tabla_nombre}\"");
                $db->alter();
                $db->setQuery("DROP TRIGGER IF EXISTS trg_auditoria_delete ON {$esquema_origen}.\"{$tabla_nombre}\"");
                $db->alter();
                
                // Crear función de trigger (común para todas las tablas)
                $function_name = "fn_auditoria_{$tabla_nombre}";
                
                $db->setQuery("DROP FUNCTION IF EXISTS {$esquema_auditoria}.\"{$function_name}\"() CASCADE");
                $db->alter();
                
                $columnas_lista = [];
                $columnas_new_old = [];
                foreach ($columnas_origen as $col) {
                    $col_name = $col->column_name;
                    // Escapar nombres de columnas con comillas dobles
                    $columnas_lista[] = "\"{$col_name}\"";
                    $columnas_new_old[] = "NEW.\"{$col_name}\"";
                }
                $columnas_str = implode(', ', $columnas_lista);
                $columnas_new_str = implode(', ', $columnas_new_old);
                
                // Para OLD (DELETE) necesitamos construir la lista con OLD en lugar de NEW
                $columnas_old = [];
                foreach ($columnas_origen as $col) {
                    $columnas_old[] = "OLD.\"{$col->column_name}\"";
                }
                $columnas_old_str = implode(', ', $columnas_old);
                
                $function_sql = "
CREATE OR REPLACE FUNCTION {$esquema_auditoria}.\"{$function_name}\"()
RETURNS TRIGGER AS \$\$
BEGIN
    IF (TG_OP = 'DELETE') THEN
        INSERT INTO {$esquema_auditoria}.\"{$tabla_nombre}\" ({$columnas_str}, auditoria_operacion, auditoria_usuario, auditoria_ip)
        VALUES ({$columnas_old_str}, 'DELETE', current_user, inet_client_addr());
        RETURN OLD;
    ELSIF (TG_OP = 'UPDATE') THEN
        INSERT INTO {$esquema_auditoria}.\"{$tabla_nombre}\" ({$columnas_str}, auditoria_operacion, auditoria_usuario, auditoria_ip)
        VALUES ({$columnas_new_str}, 'UPDATE', current_user, inet_client_addr());
        RETURN NEW;
    ELSIF (TG_OP = 'INSERT') THEN
        INSERT INTO {$esquema_auditoria}.\"{$tabla_nombre}\" ({$columnas_str}, auditoria_operacion, auditoria_usuario, auditoria_ip)
        VALUES ({$columnas_new_str}, 'INSERT', current_user, inet_client_addr());
        RETURN NEW;
    END IF;
    RETURN NULL;
END;
\$\$ LANGUAGE plpgsql;
                ";
                
                $db->setQuery($function_sql);
                $db->alter();
                
                // Crear triggers
                $trigger_sql_insert = "
CREATE TRIGGER trg_auditoria_insert
    AFTER INSERT ON {$esquema_origen}.\"{$tabla_nombre}\"
    FOR EACH ROW EXECUTE FUNCTION {$esquema_auditoria}.\"{$function_name}\"();
";
                $trigger_sql_update = "
CREATE TRIGGER trg_auditoria_update
    AFTER UPDATE ON {$esquema_origen}.\"{$tabla_nombre}\"
    FOR EACH ROW EXECUTE FUNCTION {$esquema_auditoria}.\"{$function_name}\"();
";
                $trigger_sql_delete = "
CREATE TRIGGER trg_auditoria_delete
    AFTER DELETE ON {$esquema_origen}.\"{$tabla_nombre}\"
    FOR EACH ROW EXECUTE FUNCTION {$esquema_auditoria}.\"{$function_name}\"();
                ";
                
                $db->setQuery($trigger_sql_insert);
                $db->alter();
                $db->setQuery($trigger_sql_update);
                $db->alter();
                $db->setQuery($trigger_sql_delete);
                $db->alter();
                
                $log[] = ['type' => 'success', 'message' => "  ✓ 3 triggers creados (INSERT, UPDATE, DELETE)"];
                $stats['triggers'] += 3;
            }
            
            $stats['tablas']++;
        }
        
        $db->commit();
        
        $log[] = ['type' => 'step', 'message' => "\n═══════════════════════════════════════════════"];
        $log[] = ['type' => 'success', 'message' => "✓ PROCESO COMPLETADO EXITOSAMENTE"];
        $log[] = ['type' => 'info', 'message' => "→ Tablas: {$stats['tablas']} | Columnas: {$stats['columnas']} | Triggers: {$stats['triggers']}"];
        $log[] = ['type' => 'step', 'message' => "═══════════════════════════════════════════════"];
        
        echo json_encode([
            'status' => 'success',
            'mensaje' => 'Auditoría ejecutada correctamente',
            'log' => $log,
            'stats' => $stats
        ]);
        
    } catch (Exception $e) {
        if (isset($db)) {
            $db->rollback();
        }
        
        $log[] = ['type' => 'error', 'message' => "✗ ERROR FATAL: " . $e->getMessage()];
        
        echo json_encode([
            'status' => 'error',
            'mensaje' => 'Error al ejecutar auditoría: ' . $e->getMessage(),
            'log' => $log ?? [],
            'stats' => $stats ?? ['tablas' => 0, 'columnas' => 0, 'triggers' => 0]
        ]);
    }
}
