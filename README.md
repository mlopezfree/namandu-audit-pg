# 🔍 Sistema de Auditoría Automatizada para PostgreSQL

## 📋 Descripción

Sistema completo de auditoría automatizada que replica y sincroniza automáticamente la estructura de las tablas de PostgreSQL para mantener un historial completo de cambios (INSERT, UPDATE, DELETE).

## ✨ Características Principales

### 1. **Creación Automática de Esquema**
- Crea el esquema `auditoria` si no existe
- Esquema configurable (puede ser otro nombre)

### 2. **Replicación de Tablas**
- Detecta todas las tablas del esquema origen (`public` por defecto)
- Crea réplicas exactas en el esquema de auditoría
- Agrega 5 columnas especiales de auditoría:
  - `auditoria_id` (SERIAL PRIMARY KEY)
  - `auditoria_operacion` (INSERT/UPDATE/DELETE)
  - `auditoria_usuario` (usuario de PostgreSQL)
  - `auditoria_fecha` (timestamp automático)
  - `auditoria_ip` (dirección IP del cliente)

### 3. **Sincronización Inteligente de Columnas**
- **Agregar**: Detecta columnas nuevas y las agrega automáticamente
- **Modificar**: Cambia tipos de datos cuando difieren
- **Eliminar (Modo Seguro)**: Renombra columnas eliminadas a `_deleted` en lugar de eliminarlas
- **Preservar Historial**: Nunca elimina datos históricos

### 4. **Triggers Automáticos**
- Crea 3 triggers por tabla (INSERT, UPDATE, DELETE)
- Funciones PL/pgSQL optimizadas
- Captura automática de todos los cambios
- Registra usuario, fecha e IP en cada operación

## 🚀 Uso

### Acceso
1. Navegar a: `auditoria-automatica.php`
2. Solo usuarios con rol **ADMIN** tienen acceso

### Configuración

#### Esquema a Auditar
```
Default: public
Personalizable: cualquier esquema válido
```

#### Esquema de Auditoría
```
Default: auditoria
Personalizable: cualquier nombre válido
```

#### Opciones

**✓ Crear/Actualizar Triggers Automáticamente**
- Activa la captura automática de cambios
- Recomendado: **ACTIVADO**

**✓ Modo Seguro**
- Renombra columnas eliminadas en lugar de borrarlas
- Recomendado: **ACTIVADO**

### Ejecución

1. **Configurar** el esquema origen y destino
2. **Revisar** las opciones (triggers y modo seguro)
3. Click en **"Ejecutar Auditoría Automatizada"**
4. **Confirmar** en el diálogo de seguridad
5. **Monitorear** el progreso en tiempo real en el log

## 📊 Interfaz

### Panel de Configuración
- Inputs para esquema origen/destino
- Checkboxes para opciones avanzadas
- Botón de ejecución con confirmación

### Panel de Estadísticas
- **Tablas Procesadas**: Total de tablas sincronizadas
- **Columnas Sincronizadas**: Columnas agregadas/modificadas
- **Triggers Creados**: Total de triggers activos

### Log de Ejecución
- Consola estilo terminal con colores
- Mensajes clasificados por tipo:
  - 🟢 **SUCCESS**: Operaciones exitosas
  - 🔴 **ERROR**: Errores críticos
  - 🟡 **WARNING**: Advertencias importantes
  - 🔵 **INFO**: Información general
  - 🟣 **STEP**: Separadores de pasos

## 🔧 Arquitectura Técnica

### Backend (`inc/auditoria-automatica-data.php`)

#### Flujo de Ejecución

```
1. Validación de datos de entrada
   ↓
2. Crear esquema de auditoría
   ↓
3. Obtener lista de tablas del esquema origen
   ↓
4. Para cada tabla:
   ├─ Obtener estructura (columnas, tipos, longitudes)
   ├─ ¿Existe en auditoría?
   │  ├─ NO → Crear tabla completa con columnas de auditoría
   │  └─ SÍ → Sincronizar columnas
   │     ├─ Agregar nuevas
   │     ├─ Modificar tipos diferentes
   │     └─ Renombrar eliminadas (_deleted)
   ├─ Crear función PL/pgSQL
   └─ Crear 3 triggers (INSERT/UPDATE/DELETE)
   ↓
5. Commit de transacción
   ↓
6. Retornar log y estadísticas
```

#### Queries SQL Principales

**Listar tablas:**
```sql
SELECT table_name 
FROM information_schema.tables 
WHERE table_schema = 'public' 
AND table_type = 'BASE TABLE'
```

**Obtener estructura:**
```sql
SELECT column_name, data_type, character_maximum_length, 
       is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'public' AND table_name = 'funcionarios'
ORDER BY ordinal_position
```

**Agregar columna:**
```sql
ALTER TABLE auditoria.funcionarios 
ADD COLUMN nueva_columna VARCHAR(100)
```

**Modificar tipo:**
```sql
ALTER TABLE auditoria.funcionarios 
ALTER COLUMN salario TYPE NUMERIC(10,2) 
USING salario::NUMERIC(10,2)
```

**Renombrar columna:**
```sql
ALTER TABLE auditoria.funcionarios 
RENAME COLUMN columna_vieja TO columna_vieja_deleted
```

#### Función de Trigger (Ejemplo)

```sql
CREATE OR REPLACE FUNCTION auditoria.fn_auditoria_funcionarios()
RETURNS TRIGGER AS $$
BEGIN
    IF (TG_OP = 'DELETE') THEN
        INSERT INTO auditoria.funcionarios 
        (id_funcionario, funcionario, ci, ..., auditoria_operacion, auditoria_usuario, auditoria_ip)
        SELECT OLD.*, 'DELETE', current_user, inet_client_addr();
        RETURN OLD;
    ELSIF (TG_OP = 'UPDATE') THEN
        INSERT INTO auditoria.funcionarios 
        (id_funcionario, funcionario, ci, ..., auditoria_operacion, auditoria_usuario, auditoria_ip)
        SELECT NEW.*, 'UPDATE', current_user, inet_client_addr();
        RETURN NEW;
    ELSIF (TG_OP = 'INSERT') THEN
        INSERT INTO auditoria.funcionarios 
        (id_funcionario, funcionario, ci, ..., auditoria_operacion, auditoria_usuario, auditoria_ip)
        SELECT NEW.*, 'INSERT', current_user, inet_client_addr();
        RETURN NEW;
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
```

#### Creación de Triggers

```sql
CREATE TRIGGER trg_auditoria_insert
    AFTER INSERT ON public.funcionarios
    FOR EACH ROW EXECUTE FUNCTION auditoria.fn_auditoria_funcionarios();

CREATE TRIGGER trg_auditoria_update
    AFTER UPDATE ON public.funcionarios
    FOR EACH ROW EXECUTE FUNCTION auditoria.fn_auditoria_funcionarios();

CREATE TRIGGER trg_auditoria_delete
    AFTER DELETE ON public.funcionarios
    FOR EACH ROW EXECUTE FUNCTION auditoria.fn_auditoria_funcionarios();
```

### Frontend (`js/pages/auditoria-automatica.js`)

#### Funcionalidades

- **Validación**: Verifica que el esquema origen no esté vacío
- **Confirmación**: SweetAlert2 con resumen de acciones
- **Comunicación AJAX**: POST con JSON al backend
- **Renderizado de Log**: Función `appendLog()` con colores
- **Actualización de Stats**: Contadores animados
- **Manejo de Errores**: Captura errores de conexión y servidor

## 📝 Casos de Uso

### 1. Primera Ejecución (Setup Inicial)
```
Resultado: 
- Crea esquema auditoria
- Crea 45 tablas (ejemplo)
- Agrega 320 columnas
- Crea 135 triggers
```

### 2. Agregar Nueva Columna en Producción
```
Escenario: Se agrega "resolucion_asignada" a tabla funcionarios
Resultado:
- Detecta columna nueva
- Agrega columna en auditoria.funcionarios
- Recrea triggers con la nueva columna
- 0 downtime
```

### 3. Cambio de Tipo de Dato
```
Escenario: salario cambia de INTEGER a NUMERIC(10,2)
Resultado:
- Detecta diferencia de tipo
- Ejecuta ALTER COLUMN con USING para conversión segura
- Actualiza triggers
- Datos históricos preservados
```

### 4. Eliminación de Columna (Modo Seguro)
```
Escenario: Se elimina columna "fax" de tabla funcionarios
Resultado:
- Detecta columna faltante en origen
- Renombra fax → fax_deleted en auditoría
- Datos históricos preservados
- No afecta consultas existentes
```

## ⚠️ Consideraciones Importantes

### Rendimiento
- **Tiempo estimado**: 2-5 minutos para 50 tablas
- **Transaccional**: Todo o nada (rollback en caso de error)
- **Bloqueos**: Adquiere locks durante ALTER TABLE

### Seguridad
- Solo usuarios **ADMIN** pueden ejecutar
- Transacciones con rollback automático
- Validación de nombres de esquemas

### Mantenimiento
- **Ejecutar periódicamente** cuando cambia el esquema
- **Re-ejecutable**: Puede ejecutarse múltiples veces sin problemas
- **Idempotente**: Detecta y sincroniza solo cambios

## 🔍 Consultas Útiles de Auditoría

### Ver todos los cambios de un funcionario
```sql
SELECT auditoria_operacion, auditoria_fecha, auditoria_usuario, 
       funcionario, ci, salario
FROM auditoria.funcionarios
WHERE ci = '12345678'
ORDER BY auditoria_fecha DESC;
```

### Cambios en las últimas 24 horas
```sql
SELECT table_name, auditoria_operacion, COUNT(*) as total
FROM (
    SELECT 'funcionarios' as table_name, auditoria_operacion 
    FROM auditoria.funcionarios 
    WHERE auditoria_fecha >= NOW() - INTERVAL '24 hours'
) sub
GROUP BY table_name, auditoria_operacion;
```

### Quién modificó un registro específico
```sql
SELECT auditoria_usuario, auditoria_fecha, auditoria_ip, 
       auditoria_operacion
FROM auditoria.funcionarios
WHERE id_funcionario = 123
ORDER BY auditoria_fecha DESC
LIMIT 10;
```

### Registros eliminados (recuperación)
```sql
SELECT * 
FROM auditoria.funcionarios
WHERE auditoria_operacion = 'DELETE'
AND ci = '12345678'
ORDER BY auditoria_fecha DESC
LIMIT 1;
```

## 📦 Archivos del Sistema

```
auditoria-automatica.php              # Vista principal
inc/auditoria-automatica-data.php     # Backend (PHP + PostgreSQL)
js/pages/auditoria-automatica.js      # Controlador frontend
```

---

**Versión**: 1.0.0  
**Licencia**: Uso Interno
