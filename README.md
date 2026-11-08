# AutoAudit — Proyecto integral de Bases de Datos I

> **Si vas a utilizar Ubuntu 26.04 sin Docker:** lee primero `LEEME_PRIMERO_SIN_DOCKER.md` y ejecuta los archivos de `instalacion_ubuntu/`. Las instrucciones siguientes corresponden a la modalidad original con Docker.


**AutoAudit** implementa el enunciado recibido de la Universidad Mariano Gálvez: auditoría configurable por tabla, columna y evento; generación **dinámica** de triggers; bitácora histórica y búsqueda/exportación.

## Tecnologías

- **PostgreSQL 18** — motor relacional, disparadores AFTER y funciones **PL/pgSQL**.
- **PHP 8.3 + PDO** — lógica del servidor, autenticación, consultas preparadas.
- **Bootstrap 5.3.8** — interfaz web (se carga desde CDN; para verla correctamente es necesario acceso a Internet).
- **Docker Compose** — ejecución reproducible en Ubuntu 26.04 LTS, Windows con Docker Desktop o macOS.
- **Graphviz + PlantUML** — siete diagramas en SVG, PNG y fuente `.puml`.

## Comenzar en 5 pasos (Ubuntu + Docker instalado)

1. Descomprime el ZIP y entra al directorio `AutoAudit_Proyecto`.
2. Copia `.env.ejemplo` a `.env` y **cambia las tres contraseñas**.
3. Ejecuta `docker compose up -d --build`.
4. Abre `http://localhost:8080` en el navegador.
5. Entra con `AUTOAUDIT_ADMIN_USUARIO` y `AUTOAUDIT_ADMIN_CLAVE` definidos en `.env`. Abre **Configuración**, selecciona `public.clientes`, marca las columnas y eventos deseados, guarda y usa el **Laboratorio**.

> En `compose.yaml`, los puertos web (8080) y PostgreSQL (5433) solo escuchan en `127.0.0.1` para las prácticas locales.

## Qué contiene la carpeta

```text
AutoAudit_Proyecto/
├── .env.ejemplo                    # Variables y contraseñas iniciales
├── compose.yaml                    # PostgreSQL 18 + PHP/Apache
├── app/
│   ├── Dockerfile                  # Imagen web y extensión pdo_pgsql
│   ├── public/index.php            # Todas las rutas y pantallas
│   ├── public/assets/estilos.css   # Estilos propios sobre Bootstrap
│   └── src/                        # Conexión, seguridad, SQL y plantilla
├── base_de_datos/
│   ├── inicializacion/             # Roles, esquema, funciones, privilegios, admin
│   └── pruebas/                    # Funcionales, rendimiento y diccionario
├── diagramas/                      # PlantUML, Graphviz, PNG y SVG
└── documentacion/                  # Manual, diccionario, arquitectura y matriz
```

## Pruebas automáticas

Desde la raíz de este proyecto (en Linux/macOS):

```bash
docker compose exec -T bd psql -U postgres -d autoaudit -v ON_ERROR_STOP=1 < base_de_datos/pruebas/01_pruebas_funcionales.sql
```

Se espera el mensaje `APROBADO: INSERT, UPDATE, DELETE, sin cambios, histórico y desactivación`. **Estas pruebas usan `ROLLBACK`** y no modifican permanentemente los datos ni las reglas.

Ejemplo de rendimiento (en entorno académico):

```bash
docker compose exec -T bd psql -U postgres -d autoaudit -v ON_ERROR_STOP=1 < base_de_datos/pruebas/02_prueba_rendimiento.sql
```

## Administración

- Ver registros de los contenedores: `docker compose logs --tail=100 web bd`
- Detener contenedores: `docker compose down`
- **Peligro: borrar toda la base de datos local:** `docker compose down -v` (nunca usar para preservar trabajos)
- Conectarte por psql: `docker compose exec bd psql -U postgres -d autoaudit`
- Conectarte desde pgAdmin: host `localhost`, puerto `5433`, base `autoaudit`, usuario `postgres`, contraseña `POSTGRES_PASSWORD`.

**IMPORTANTE:** Cambiar `.env` después de iniciar el proyecto **no** modifica automáticamente las contraseñas almacenadas en el volumen de PostgreSQL. En ese caso cambia credenciales mediante SQL o reinicializa solo si puedes perder todos los datos.

## Alcance y límites

Este proyecto incluye una aplicación educativa operativa según el diseño: inicio de sesión, roles `administrador` / `consulta` (la interfaz de creación de otros usuarios no forma parte del proyecto), configuración de triggers para **tablas ordinarias del esquema `public`**, laboratorio de clientes, bitácora, filtros y CSV. El frontend permite trabajar con clientes de demostración, no es un editor universal de todas las tablas.

La generación de triggers funciona para tablas reales de `public`, pero la aplicación web solo puede practicar DML de `public.clientes` y `public.productos` según los privilegios del rol. Para tablas adicionales necesitarás conceder privilegios SQL apropiados o crear una interfaz específica. El auditado de cambios hechos **directamente desde psql** registrará el usuario PostgreSQL, pero no puede adivinar el usuario de una aplicación ajena ni su IP real sin que esa aplicación los transmita. El campo de estación es una huella orientativa, no un identificador físico infalible.

### Documentación en profundidad

Lee **`documentacion/Manual_de_Ejecucion_y_Estudio.pdf`**, y después `documentacion/Diccionario_de_Datos.md` y `documentacion/Matriz_de_Cumplimiento.md`.
