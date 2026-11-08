# Matriz de cumplimiento del enunciado AutoAudit

Fuente: PDF entregado, *AutoAudit — Sistema de auditoría automatizada*, septiembre 2026 (6 páginas).

| Requisito del documento | Implementación | Archivo / demostración | Estado |
|---|---|---|---|
| Entidad-relación | 7 entidades de auditoría más 2 de laboratorio | `diagramas/07_entidad_relacion.*` | Implementado |
| Configurar tablas por diccionario | Consulta `pg_catalog` y UI de selección | `app/src/Consultas.php`, `app/public/index.php` | Implementado |
| Elegir columnas granulares | Checkboxes independientes por columna y operación | Pantalla Configuración | Implementado |
| Activar/desactivar INSERT/UPDATE/DELETE | Reglas `id_columna + operacion` | Tabla `reglas_auditoria` | Implementado |
| Generación **automática** de triggers | `DROP TRIGGER IF EXISTS` + `CREATE TRIGGER AFTER ...` | `03_funciones.sql` | Implementado |
| Un trigger pertenece a una sola tabla | Trigger nombrado `tr_autoaudit_{id}_{evento}` | `03_funciones.sql` | Implementado |
| Guardar old/new y usuario, fecha, IP | Cabecera + detalles + `set_config` | `02_estructura.sql`, `registrar_cambio()` | Implementado |
| Estación de trabajo | Entidad de estaciones y contexto web | `obtener_o_crear_estacion` y `Seguridad.php` | Implementado con huella orientativa |
| Consultas por usuario/columna | Filtros SQL parametrizados | Historial | Implementado |
| Visualización temporal | Lista ordenada por fecha y ID (más reciente primero) | Historial | Implementado como tabla cronológica |
| Reporte estándar | Exportación CSV UTF-8 hasta 10,000 eventos | Página Historial -> Exportar CSV | Implementado |
| Conservar registros borrados | `registro_anterior` JSONB no depende de FK origen | `bitacora_eventos` | Implementado |
| Seguridad para configurar | Admin autenticado en PHP y CSRF | `Seguridad.php`, `index.php` | Implementado a nivel web |
| Restringir escritura directa bitácora | Rol de la web sin INSERT/UPDATE/DELETE en bitácora | `04_permisos.sql` | Implementado |
| Diagramas | UML casos, clases, secuencias, actividad, componentes + ER | `diagramas/` | Implementado |
| Diccionario y manual | Datos y guía con explicación por bloques | `documentacion/` | Implementado |
| Pruebas INSERT/UPDATE/DELETE | DO PL/pgSQL con ASSERT y ROLLBACK | `01_pruebas_funcionales.sql` | Incluidas para ejecutar |
| Pruebas de volumen | EXPLAIN ANALYZE con 100 y 1,000 filas | `02_prueba_rendimiento.sql` | Incluidas para ejecutar y medir |
| GitHub / GitLab | Código y guía de comandos para publicar | `README.md` y Manual | **Pendiente de subir con tu cuenta** |
| PostgreSQL 18 + Ubuntu 26.04 LTS | Imagen PostgreSQL 18, despliegue Docker en Ubuntu | `compose.yaml`, `app/Dockerfile` | Preparado; requiere equipo compatible |

## Decisiones y límites que el enunciado no define

1. El documento dice **PL/SQL**, pero PostgreSQL ejecuta **PL/pgSQL** como su lenguaje procedural. Se emplea PL/pgSQL.
2. El documento permite que la clase elija tecnología de interfaz. Se ha elegido **PHP 8.3 + Bootstrap 5.3.8**, porque el usuario lo solicitó.
3. Se auditan **tablas ordinarias `public`**. No hay soporte deliberado para particiones, esquemas arbitrarios, `TRUNCATE`, DDL, operaciones SELECT ni migración automática de columnas renombradas.
4. Los reportes son **CSV**, formato estándar; no se agregó PDF/Excel porque no se especifican formatos obligatorios.
5. Un cambio hecho directamente por psql tendrá `usuario_base_datos`, pero no el nombre de usuario web ni la estación del navegador. Estos solo se transmiten con la aplicación PHP.
6. La validación de rendimiento es contextual. Se incluyen scripts para medir pero no se afirma un porcentaje sin ejecutarlos en el hardware del usuario.
7. El despliegue usa PHP/Debian dentro del contenedor web, PostgreSQL dentro del contenedor DB y Ubuntu 26.04 como sistema anfitrión previsto.
8. La creación de usuarios adicionales y el restablecimiento de contraseña por interfaz no son módulos del enunciado.
9. No sustituye una solución empresarial endurecida: el rol compartido de aplicación confía en la autorización PHP para configurar, y un administrador directo de PostgreSQL puede modificar la auditoría; se requerirían controles extra en producción.
10. Las credenciales del archivo `.env` deben tratarse como secretos; no incluirlas en repositorios públicos.
