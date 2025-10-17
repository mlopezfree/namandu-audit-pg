<?php include 'header.php'; ?>

<body class="<?php include 'menu-class.php';?> fixed-layout">
    <?php include "preloader.php"; ?>
    <div id="main-wrapper">
        <?php include 'topbar.php'; include 'leftbar.php' ?>
<style>
    .log-container {
        background: #1e1e1e;
        color: #d4d4d4;
        font-family: 'Consolas', 'Monaco', monospace;
        font-size: 13px;
        padding: 20px;
        border-radius: 8px;
        max-height: 600px;
        overflow-y: auto;
        white-space: pre-wrap;
        word-wrap: break-word;
        margin-top: 20px;
        display: none;
    }
    .log-success { color: #4ec9b0; }
    .log-error { color: #f48771; }
    .log-warning { color: #dcdcaa; }
    .log-info { color: #569cd6; }
    .log-step { color: #c586c0; font-weight: bold; }
    
    .config-card {
        background: #f8f9fa;
        border-left: 4px solid #007bff;
        padding: 15px;
        margin-bottom: 20px;
    }
    
    .stats-card {
        text-align: center;
        padding: 20px;
        border-radius: 8px;
        margin-bottom: 15px;
    }
    .stats-card h3 {
        font-size: 2.5rem;
        margin: 10px 0;
        font-weight: bold;
    }
    .stats-card p {
        margin: 0;
        color: #6c757d;
    }
</style>

<div class="page-wrapper">
    <div class="container-fluid">
        <!-- Título -->
        <div class="row page-titles">
            <div class="col-md-5 align-self-center">
                <h3 class="text-themecolor">
                    <i class="fas fa-database"></i> Auditoría Automatizada
                </h3>
            </div>
            <div class="col-md-7 align-self-center text-right">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="inicio.php">Inicio</a></li>
                    <li class="breadcrumb-item active">Auditoría Automatizada</li>
                </ol>
            </div>
        </div>

        <!-- Alertas -->
        <div class="row">
            <div class="col-12">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Precaución:</strong> Este proceso modificará la estructura de la base de datos. 
                    Se recomienda ejecutar en horarios de bajo tráfico y tener un respaldo reciente.
                </div>
            </div>
        </div>

        <!-- Estado de Auditoría Automática -->
        <div class="row">
            <div class="col-12">
                <div class="card border-info" id="card-estado-auditoria">
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-robot"></i> Auditoría Automática DDL
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="row align-items-center">
                            <div class="col-md-8">
                                <h5 id="estado-titulo">
                                    <span class="badge badge-secondary" id="badge-estado">
                                        <i class="fas fa-sync fa-spin"></i> Verificando...
                                    </span>
                                </h5>
                                <p class="mb-0" id="estado-descripcion">
                                    Comprobando estado del sistema de auditoría automática...
                                </p>
                            </div>
                            <div class="col-md-4 text-right">
                                <button class="btn btn-success" id="btn-activar-ddl" style="display: none;">
                                    <i class="fas fa-power-off"></i> Activar Auditoría DDL
                                </button>
                                <button class="btn btn-danger" id="btn-desactivar-ddl" style="display: none;">
                                    <i class="fas fa-power-off"></i> Desactivar Auditoría DDL
                                </button>
                            </div>
                        </div>
                        <div class="alert alert-info mt-3" style="display: none;" id="info-auditoria-ddl">
                            <strong><i class="fas fa-info-circle"></i> ¿Qué es la Auditoría DDL?</strong>
                            <p class="mb-0 mt-2">
                                Cuando está activa, cualquier cambio en la estructura de las tablas (ALTER TABLE) 
                                se replica automáticamente en el esquema de auditoría sin necesidad de ejecutar la sincronización manual.
                            </p>
                            <ul class="mt-2 mb-0">
                                <li><strong>ADD COLUMN:</strong> Se agrega la columna en auditoría</li>
                                <li><strong>DROP COLUMN:</strong> Se renombra a "_deleted" (modo seguro)</li>
                                <li><strong>ALTER COLUMN TYPE:</strong> Se modifica el tipo</li>
                                <li><strong>RENAME COLUMN:</strong> Se renombra igualmente</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Configuración -->
        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-cog"></i> Configuración del Esquema
                        </h4>
                    </div>
                    <div class="card-body">
                        <div class="config-card">
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="esquema_origen">
                                            <i class="fas fa-table"></i> Esquema a Auditar
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="esquema_origen" 
                                               value="public" 
                                               placeholder="Nombre del esquema">
                                        <small class="form-text text-muted">
                                            Esquema que contiene las tablas a auditar
                                        </small>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="form-group">
                                        <label for="esquema_auditoria">
                                            <i class="fas fa-archive"></i> Esquema de Auditoría
                                        </label>
                                        <input type="text" 
                                               class="form-control" 
                                               id="esquema_auditoria" 
                                               value="auditoria" 
                                               placeholder="Nombre del esquema">
                                        <small class="form-text text-muted">
                                            Esquema donde se almacenarán los registros
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="crear_triggers" checked>
                                <span class="custom-control-label">
                                    <i class="fas fa-bolt"></i> Crear/Actualizar Triggers Automáticamente
                                </span>
                            </label>
                            <small class="form-text text-muted">
                                Los triggers capturarán automáticamente INSERT, UPDATE y DELETE
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="custom-control custom-checkbox">
                                <input type="checkbox" class="custom-control-input" id="modo_seguro" checked>
                                <span class="custom-control-label">
                                    <i class="fas fa-shield-alt"></i> Modo Seguro
                                </span>
                            </label>
                            <small class="form-text text-muted">
                                Renombra columnas eliminadas a "_deleted" en lugar de eliminarlas
                            </small>
                        </div>

                        <hr>

                        <div class="text-center">
                            <button type="button" 
                                    class="btn btn-success btn-lg" 
                                    id="btn_ejecutar_auditoria">
                                <i class="fas fa-play-circle"></i> Ejecutar Auditoría Automatizada
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Estadísticas -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-chart-bar"></i> Estadísticas
                        </h4>
                    </div>
                    <div class="card-body">
                        <div id="stats-container" style="display: none;">
                            <div class="stats-card bg-light-info">
                                <i class="fas fa-table fa-2x text-info"></i>
                                <h3 class="text-info" id="stat_tablas">0</h3>
                                <p>Tablas Procesadas</p>
                            </div>
                            <div class="stats-card bg-light-success">
                                <i class="fas fa-columns fa-2x text-success"></i>
                                <h3 class="text-success" id="stat_columnas">0</h3>
                                <p>Columnas Sincronizadas</p>
                            </div>
                            <div class="stats-card bg-light-warning">
                                <i class="fas fa-bolt fa-2x text-warning"></i>
                                <h3 class="text-warning" id="stat_triggers">0</h3>
                                <p>Triggers Creados</p>
                            </div>
                        </div>
                        <div id="placeholder-stats" class="text-center text-muted py-5">
                            <i class="fas fa-info-circle fa-3x mb-3"></i>
                            <p>Ejecuta el proceso para ver estadísticas</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Log de Ejecución -->
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-dark text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-terminal"></i> Log de Ejecución
                        </h4>
                    </div>
                    <div class="card-body p-0">
                        <div id="log-output" class="log-container"></div>
                        <div id="placeholder-log" class="text-center text-muted py-5">
                            <i class="fas fa-code fa-3x mb-3"></i>
                            <p>El log de ejecución aparecerá aquí...</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include 'footer.php'; ?>
    </div>
    <script type="text/javascript">

    </script>
    <script src="<?php echo $js_pagina; ?>"></script>
</body>
</html>
