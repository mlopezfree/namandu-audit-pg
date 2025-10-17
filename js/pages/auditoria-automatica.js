$(document).ready(function() {
    
    // Verificar estado inicial de auditoría DDL
    verificarEstadoAuditoria();
    
    // Activar auditoría DDL
    $('#btn-activar-ddl').click(function() {
        Swal.fire({
            title: '¿Activar Auditoría Automática DDL?',
            html: `
                <div class="text-left">
                    <p><strong>Una vez activada:</strong></p>
                    <ul>
                        <li>Los ALTER TABLE se replicarán automáticamente en auditoría</li>
                        <li>No necesitarás ejecutar sincronización manual</li>
                        <li>Las columnas eliminadas se renombrarán a "_deleted"</li>
                    </ul>
                    <p class="text-info mt-3">
                        <i class="fas fa-lightbulb"></i>
                        <strong>Nota:</strong> Puedes desactivarla en cualquier momento.
                    </p>
                    <hr>
                    <div class="form-group text-left">
                        <label for="password-ddl"><strong>Contraseña de Administrador:</strong></label>
                        <input type="password" class="form-control" id="password-ddl" placeholder="Ingrese su contraseña">
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-check"></i> Activar',
            cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#6c757d',
            width: 600,
            preConfirm: () => {
                const password = document.getElementById('password-ddl').value;
                if (!password) {
                    Swal.showValidationMessage('Debe ingresar su contraseña');
                    return false;
                }
                return password;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                verificarPasswordYActivar(result.value);
            }
        });
    });
    
    // Desactivar auditoría DDL
    $('#btn-desactivar-ddl').click(function() {
        Swal.fire({
            title: '¿Desactivar Auditoría DDL?',
            html: `
                <div class="text-left">
                    <p>Los cambios en la estructura NO se replicarán automáticamente.</p>
                    <p class="text-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Deberás ejecutar la sincronización manual cuando realices cambios.
                    </p>
                    <hr>
                    <div class="form-group text-left">
                        <label for="password-ddl-des"><strong>Contraseña de Administrador:</strong></label>
                        <input type="password" class="form-control" id="password-ddl-des" placeholder="Ingrese su contraseña">
                    </div>
                </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-power-off"></i> Desactivar',
            cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
            confirmButtonColor: '#dc3545',
            cancelButtonColor: '#6c757d',
            preConfirm: () => {
                const password = document.getElementById('password-ddl-des').value;
                if (!password) {
                    Swal.showValidationMessage('Debe ingresar su contraseña');
                    return false;
                }
                return password;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                verificarPasswordYDesactivar(result.value);
            }
        });
    });
    
    // Ejecutar auditoría
    $('#btn_ejecutar_auditoria').click(function() {
        Swal.fire({
            title: '¿Ejecutar Auditoría Automatizada?',
            html: `
                <div class="text-left">
                    <p><strong>Este proceso realizará:</strong></p>
                    <ul>
                        <li>Creación/verificación del esquema de auditoría</li>
                        <li>Sincronización de todas las tablas</li>
                        <li>Actualización de columnas (agregar/modificar)</li>
                        <li>Creación de triggers automáticos</li>
                    </ul>
                    <p class="text-warning mt-3">
                        <i class="fas fa-exclamation-triangle"></i>
                        <strong>Nota:</strong> Este proceso puede tardar varios minutos.
                    </p>
                    <hr>
                    <div class="form-group text-left">
                        <label for="password-sync"><strong>Contraseña de Administrador:</strong></label>
                        <input type="password" class="form-control" id="password-sync" placeholder="Ingrese su contraseña">
                    </div>
                </div>
            `,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: '<i class="fas fa-play"></i> Ejecutar Ahora',
            cancelButtonText: '<i class="fas fa-times"></i> Cancelar',
            confirmButtonColor: '#28a745',
            cancelButtonColor: '#dc3545',
            width: 600,
            preConfirm: () => {
                const password = document.getElementById('password-sync').value;
                if (!password) {
                    Swal.showValidationMessage('Debe ingresar su contraseña');
                    return false;
                }
                return password;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                verificarPasswordYEjecutar(result.value);
            }
        });
    });
    
    function verificarEstadoAuditoria() {
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=verificar_estado_auditoria',
            type: 'GET',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    actualizarEstadoUI(response.activo);
                }
            },
            error: function() {
                $('#badge-estado').removeClass('badge-secondary')
                    .addClass('badge-danger')
                    .html('<i class="fas fa-times"></i> Error al verificar');
                $('#estado-descripcion').text('No se pudo verificar el estado');
            }
        });
    }
    
    function actualizarEstadoUI(activo) {
        if (activo) {
            $('#badge-estado').removeClass('badge-secondary badge-danger')
                .addClass('badge-success')
                .html('<i class="fas fa-check-circle"></i> ACTIVA');
            $('#estado-descripcion').html(
                '<strong>La auditoría DDL está activa.</strong> Los cambios en la estructura se replican automáticamente.'
            );
            $('#btn-activar-ddl').hide();
            $('#btn-desactivar-ddl').show();
            $('#info-auditoria-ddl').show();
            $('#card-estado-auditoria').removeClass('border-warning').addClass('border-success');
        } else {
            $('#badge-estado').removeClass('badge-secondary badge-success')
                .addClass('badge-warning')
                .html('<i class="fas fa-exclamation-circle"></i> INACTIVA');
            $('#estado-descripcion').html(
                '<strong>La auditoría DDL está desactivada.</strong> Debes ejecutar la sincronización manual cuando hagas cambios.'
            );
            $('#btn-activar-ddl').show();
            $('#btn-desactivar-ddl').hide();
            $('#info-auditoria-ddl').hide();
            $('#card-estado-auditoria').removeClass('border-success').addClass('border-warning');
        }
    }
    
    function verificarPasswordYActivar(password) {
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=verificar_admin',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ password: password }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success' && response.es_admin) {
                    activarAuditoriaDDL();
                } else {
                    Swal.fire('Error', response.mensaje || 'Credenciales inválidas', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'No se pudo verificar las credenciales', 'error');
            }
        });
    }
    
    function verificarPasswordYDesactivar(password) {
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=verificar_admin',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ password: password }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success' && response.es_admin) {
                    desactivarAuditoriaDDL();
                } else {
                    Swal.fire('Error', response.mensaje || 'Credenciales inválidas', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'No se pudo verificar las credenciales', 'error');
            }
        });
    }
    
    function verificarPasswordYEjecutar(password) {
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=verificar_admin',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ password: password }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success' && response.es_admin) {
                    ejecutarAuditoria();
                } else {
                    Swal.fire('Error', response.mensaje || 'Credenciales inválidas', 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'No se pudo verificar las credenciales', 'error');
            }
        });
    }
    
    function activarAuditoriaDDL() {
        const esquema_origen = $('#esquema_origen').val().trim() || 'public';
        const esquema_auditoria = $('#esquema_auditoria').val().trim() || 'auditoria';
        const modo_seguro = $('#modo_seguro').is(':checked');
        
        Swal.fire({
            title: 'Activando...',
            html: 'Configurando auditoría automática DDL',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=activar_auditoria_automatica',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                esquema_origen: esquema_origen,
                esquema_auditoria: esquema_auditoria,
                modo_seguro: modo_seguro
            }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Mostrar log
                    if (response.log && response.log.length > 0) {
                        $('#log-output').empty().show();
                        $('#placeholder-log').hide();
                        response.log.forEach(function(entry) {
                            appendLog(entry.type, entry.message);
                        });
                    }
                    
                    Swal.fire({
                        title: '¡Activada!',
                        html: '<strong>La auditoría DDL está ahora activa.</strong><br>Los cambios se replicarán automáticamente.',
                        icon: 'success',
                        confirmButtonText: 'Entendido'
                    });
                    
                    actualizarEstadoUI(true);
                } else {
                    Swal.fire('Error', response.mensaje, 'error');
                }
            },
            error: function(xhr, status, error) {
                Swal.fire('Error', 'No se pudo activar la auditoría DDL: ' + error, 'error');
            }
        });
    }
    
    function desactivarAuditoriaDDL() {
        Swal.fire({
            title: 'Desactivando...',
            html: 'Removiendo auditoría automática DDL',
            allowOutsideClick: false,
            didOpen: () => {
                Swal.showLoading();
            }
        });
        
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=desactivar_auditoria_automatica',
            type: 'POST',
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    Swal.fire({
                        title: 'Desactivada',
                        text: 'La auditoría DDL ha sido desactivada correctamente.',
                        icon: 'success',
                        confirmButtonText: 'Entendido'
                    });
                    
                    actualizarEstadoUI(false);
                } else {
                    Swal.fire('Error', response.mensaje, 'error');
                }
            },
            error: function() {
                Swal.fire('Error', 'No se pudo desactivar la auditoría DDL', 'error');
            }
        });
    }
    
    
    function ejecutarAuditoria() {
        // Obtener configuración
        const esquema_origen = $('#esquema_origen').val().trim() || 'public';
        const esquema_auditoria = $('#esquema_auditoria').val().trim() || 'auditoria';
        const crear_triggers = $('#crear_triggers').is(':checked');
        const modo_seguro = $('#modo_seguro').is(':checked');
        
        // Validaciones
        if (!esquema_origen) {
            Swal.fire('Error', 'Debe especificar el esquema origen', 'error');
            return;
        }
        
        // Preparar UI
        $('#btn_ejecutar_auditoria').prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Ejecutando...');
        $('#log-output').empty().show();
        $('#placeholder-log').hide();
        $('#stats-container').hide();
        $('#placeholder-stats').show();
        
        // Mostrar inicio
        appendLog('step', '╔════════════════════════════════════════════════════════════╗');
        appendLog('step', '║     AUDITORÍA AUTOMATIZADA - SISTEMA RRHH MP              ║');
        appendLog('step', '╚════════════════════════════════════════════════════════════╝');
        appendLog('info', `\n→ Esquema Origen: ${esquema_origen}`);
        appendLog('info', `→ Esquema Auditoría: ${esquema_auditoria}`);
        appendLog('info', `→ Triggers: ${crear_triggers ? 'Activados' : 'Desactivados'}`);
        appendLog('info', `→ Modo Seguro: ${modo_seguro ? 'Activado' : 'Desactivado'}`);
        appendLog('info', `→ Fecha/Hora: ${new Date().toLocaleString('es-PY')}\n`);
        
        // Ejecutar AJAX
        $.ajax({
            url: 'inc/auditoria-automatica-data.php?action=ejecutar_auditoria',
            type: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({
                esquema_origen: esquema_origen,
                esquema_auditoria: esquema_auditoria,
                crear_triggers: crear_triggers,
                modo_seguro: modo_seguro
            }),
            dataType: 'json',
            success: function(response) {
                if (response.status === 'success') {
                    // Mostrar log
                    if (response.log && response.log.length > 0) {
                        response.log.forEach(function(entry) {
                            appendLog(entry.type, entry.message);
                        });
                    }
                    
                    // Actualizar estadísticas
                    if (response.stats) {
                        $('#stat_tablas').text(response.stats.tablas);
                        $('#stat_columnas').text(response.stats.columnas);
                        $('#stat_triggers').text(response.stats.triggers);
                        $('#placeholder-stats').hide();
                        $('#stats-container').fadeIn();
                    }
                    
                    // Mensaje final
                    Swal.fire({
                        title: '¡Éxito!',
                        html: `
                            <div class="text-left">
                                <p><strong>Auditoría completada correctamente:</strong></p>
                                <ul>
                                    <li><strong>${response.stats.tablas}</strong> tablas procesadas</li>
                                    <li><strong>${response.stats.columnas}</strong> columnas sincronizadas</li>
                                    <li><strong>${response.stats.triggers}</strong> triggers creados</li>
                                </ul>
                            </div>
                        `,
                        icon: 'success',
                        confirmButtonText: 'Entendido'
                    });
                    
                } else {
                    // Error en respuesta
                    if (response.log && response.log.length > 0) {
                        response.log.forEach(function(entry) {
                            appendLog(entry.type, entry.message);
                        });
                    }
                    
                    appendLog('error', '\n✗ PROCESO TERMINADO CON ERRORES');
                    
                    Swal.fire({
                        title: 'Error',
                        text: response.mensaje || 'Error al ejecutar la auditoría',
                        icon: 'error',
                        confirmButtonText: 'Cerrar'
                    });
                }
            },
            error: function(xhr, status, error) {
                appendLog('error', `\n✗ ERROR DE CONEXIÓN: ${error}`);
                appendLog('error', `Estado HTTP: ${xhr.status}`);
                
                Swal.fire({
                    title: 'Error de Conexión',
                    text: 'No se pudo conectar con el servidor. Verifique su conexión.',
                    icon: 'error',
                    confirmButtonText: 'Cerrar'
                });
            },
            complete: function() {
                $('#btn_ejecutar_auditoria').prop('disabled', false).html('<i class="fas fa-play-circle"></i> Ejecutar Auditoría Automatizada');
            }
        });
    }
    
    function appendLog(type, message) {
        const logContainer = $('#log-output');
        const typeClass = `log-${type}`;
        
        // Mapear iconos
        let icon = '';
        switch(type) {
            case 'success': icon = '✓'; break;
            case 'error': icon = '✗'; break;
            case 'warning': icon = '⚠'; break;
            case 'info': icon = '→'; break;
            case 'step': icon = ''; break;
        }
        
        const line = $('<div>').addClass(typeClass).text(icon ? `${icon} ${message}` : message);
        logContainer.append(line);
        
        // Auto-scroll al final
        logContainer.scrollTop(logContainer[0].scrollHeight);
    }
    
});
