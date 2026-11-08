# AutoAudit — Guía de primera ejecución en Ubuntu 26.04 LTS, sin Docker

Esta variante conserva el mismo código PHP, SQL y los diagramas del ZIP original. Se han agregado cuatro scripts de Ubuntu para que puedas usar el proyecto **sin instalar Docker**. La documentación anterior dentro de `documentacion/` describe el método Docker, por lo que debes utilizar **este documento** para instalar sin Docker.

## Qué significa cada palabra

- **Windows**: tu sistema operativo principal, que no vamos a reemplazar.
- **VirtualBox**: programa para crear una computadora virtual.
- **Ubuntu 26.04 LTS**: sistema operativo Linux que instalarás dentro de VirtualBox.
- **Terminal**: ventana para introducir comandos de Ubuntu.
- **PostgreSQL 18**: servidor de bases de datos del proyecto.
- **SQL**: lenguaje para definir tablas y consultar o modificar información.
- **PL/pgSQL**: lenguaje de funciones y triggers en PostgreSQL.
- **PHP**: código del servidor que se conecta con PostgreSQL.
- **Bootstrap**: librería CSS/JS para la interfaz web; se descarga desde Internet mientras navegas.

## Paso 0: Descargas en Windows

1. Instala VirtualBox desde https://www.oracle.com/virtualization/technologies/vm/downloads/virtualbox-downloads.html usando **Windows Installer**.
2. Descarga **Ubuntu Desktop 26.04.1 LTS** desde https://ubuntu.com/download/desktop; guarda el archivo `.iso`.
3. En VirtualBox crea una máquina virtual: nombre `Ubuntu-AutoAudit`, ISO Ubuntu descargada, tipo Ubuntu 64-bit, RAM 4-6 GB (si tu PC lo permite), procesadores 2-4 y disco virtual dinámico 50 GB. Desactiva / salta la instalación desatendida para seguir los pasos del instalador manualmente.
4. Inicia la máquina, elige instalar Ubuntu, idioma y teclado de preferencia, instalación interactiva y elige usar **solo el disco virtual**. Si seleccionas `Borrar disco e instalar Ubuntu`, asegúrate de que es *dentro de VirtualBox*, nunca en el instalador arrancado directamente desde tu computadora física.
5. Reinicia la máquina virtual cuando el instalador lo indique y entra con la contraseña que elegiste.

## Paso 1: Preparar Ubuntu

Abre la Terminal con **Ctrl+Alt+T**. Si se solicita tu contraseña al ejecutar `sudo`, escríbela aunque los caracteres no aparezcan en pantalla.

```bash
sudo apt update
sudo apt upgrade -y
sudo apt install -y unzip nano
```

Para comprobar tu versión:

```bash
lsb_release -a
```

Debe aparecer la versión Ubuntu 26.04.x LTS.

## Paso 2: Poner el ZIP dentro de Ubuntu

Abre Firefox **dentro de Ubuntu** y descarga `AutoAudit_Proyecto_Sin_Docker.zip` del mensaje de ChatGPT. Ve a Descargas, haz clic derecho sobre el ZIP y **Extraer aquí**. Copia la carpeta `AutoAudit_Proyecto_Sin_Docker` a tu carpeta personal (Inicio / Home). Debe quedar en `~/AutoAudit_Proyecto_Sin_Docker`.

En Ubuntu, ejecuta:

```bash
cd "$HOME/AutoAudit_Proyecto_Sin_Docker"
ls -la
```

Busca los directorios `app`, `base_de_datos`, `instalacion_ubuntu`, `documentacion` y el archivo `.env.ejemplo`.

## Paso 3: Configurar las tres contraseñas

```bash
cp .env.ejemplo .env
nano .env
```

Cambia `POSTGRES_PASSWORD`, `AUTOAUDIT_CLAVE_APP` y `AUTOAUDIT_ADMIN_CLAVE` por contraseñas nuevas. Para este instalador de prácticas usa mínimo 12 caracteres con letras, números, puntos, guiones y guiones bajos, sin espacios ni acentos. `AUTOAUDIT_ADMIN_USUARIO` será tu nombre de usuario para entrar a la web. Deja `PUERTO_WEB=8080`. `POSTGRES_PASSWORD` y `PUERTO_POSTGRES` pertenecen al antiguo método Docker; en la instalación local se administrará PostgreSQL con `sudo -u postgres` en el puerto 5432.

En `nano` guarda con **Ctrl+O**, pulsa **Enter** y sal con **Ctrl+X**.

**No compartas `.env`, no lo subas a GitHub ni muestres tus contraseñas en capturas.** El código trae `.gitignore` para excluirlo.

## Paso 4: Instalar PostgreSQL y crear todas las tablas

En la terminal, desde la raíz del proyecto:

```bash
bash instalacion_ubuntu/01_preparar_base_de_datos.sh
```

Este script ejecuta los pasos siguientes en orden:

1. Instala `postgresql-18`, `php-cli` y `php-pgsql`.
2. Crea el usuario de base de datos `autoaudit_aplicacion`, cuyo password procede de `.env`.
3. Crea la base `autoaudit` y ejecuta la estructura en `02_estructura.sql`.
4. Ejecuta las funciones, los triggers configurables y los permisos (`03_funciones.sql`, `04_permisos.sql`).
5. Crea un administrador web con la contraseña de `.env`, almacenada como hash.

El script **se detiene si ya existe** la base de datos `autoaudit`, para evitar borrar o sobrescribir información.

Comprueba:

```bash
psql --version
php --version
php -m | grep pdo_pgsql
sudo -u postgres psql -d autoaudit -c '\dt auditoria.*'
```

Debes observar PostgreSQL 18, PHP, `pdo_pgsql` y las tablas de auditoría.

## Paso 5: Levantar la aplicación en tu propio Ubuntu

```bash
bash instalacion_ubuntu/02_iniciar_web.sh
```

La terminal quedará ocupada mostrando las peticiones web; eso es **normal**. No la cierres mientras utilices AutoAudit. Abre **Firefox dentro de Ubuntu** y entra a:

http://127.0.0.1:8080

Inicia sesión con `AUTOAUDIT_ADMIN_USUARIO` y `AUTOAUDIT_ADMIN_CLAVE` del archivo `.env`. Para detener el servidor pulsa **Ctrl+C** en esa misma terminal.

> Este servidor PHP sirve para pruebas locales y aprendizaje, no para publicar AutoAudit en Internet. El script 04 permite instalar Apache más adelante.

## Paso 6: Probar triggers con la interfaz

1. En **Configuración**, selecciona `public.clientes` y haz clic en **Ver columnas**.
2. Marca `nombre` y `telefono` para INSERT, UPDATE y DELETE. Presiona **Guardar reglas y generar triggers**.
3. En **Laboratorio**, crea un cliente ficticio, actualiza su teléfono y luego elimínalo.
4. En **Historial**, revisa INSERT, UPDATE y DELETE. En UPDATE comprueba `OLD` y `NEW`; en DELETE comprueba que conserva el registro anterior.

## Paso 7: Probar directamente en PostgreSQL

Abre **otra terminal** con Ctrl+Alt+T. Ve al proyecto:

```bash
cd "$HOME/AutoAudit_Proyecto_Sin_Docker"
```

Para ejecutar las pruebas automáticas:

```bash
bash instalacion_ubuntu/03_ejecutar_pruebas.sh
```

Debes encontrar el mensaje `APROBADO: INSERT, UPDATE, DELETE, sin cambios, histórico y desactivación`. Este test termina en `ROLLBACK`, así que sus cambios no permanecen.

Para abrir la base de datos desde consola:

```bash
sudo -u postgres psql -d autoaudit
```

Ahora puedes ejecutar SQL, por ejemplo:

```sql
SELECT * FROM auditoria.bitacora_eventos ORDER BY id_evento DESC LIMIT 5;
```

Para salir: `\q` y luego Enter.

## Paso 8: Leer el código

- `app/public/index.php`: rutas, formularios y vistas Bootstrap.
- `app/src/BaseDatos.php`: conexión de PHP con PostgreSQL.
- `app/src/Seguridad.php`: inicio de sesión y validación.
- `app/src/Consultas.php`: consultas SQL, filtros e historial.
- `app/src/Presentacion.php`: estructura HTML de la interfaz.
- `base_de_datos/inicializacion/02_estructura.sql`: tablas y relaciones.
- `base_de_datos/inicializacion/03_funciones.sql`: funciones PL/pgSQL y generación de triggers.
- `base_de_datos/inicializacion/04_permisos.sql`: seguridad y permisos.
- `diagramas/`: diagramas PNG, SVG y fuentes editables.

Puedes leerlos con **Editor de texto** de Ubuntu, o instalar VS Code en Ubuntu desde https://code.visualstudio.com/Download (paquete `.deb`). También se pueden abrir con `nano` desde la terminal.

## Paso 9: Apache (opcional, cuando lo anterior funcione)

La primera ejecución utiliza el servidor de desarrollo de PHP. Para pasar a **Apache real** dentro de Ubuntu, primero detén PHP con Ctrl+C y ejecuta:

```bash
bash instalacion_ubuntu/04_instalar_apache_opcional.sh
```

Abre la misma dirección: http://127.0.0.1:8080. Apache copia el código a `/var/www/autoaudit`; si después modificas PHP en tu carpeta personal, deberás copiar los cambios a esa ruta para que Apache los muestre. El archivo de configuración de Apache contiene la contraseña de la BD y se protege con permisos de lectura solo para root: úsalo únicamente en tu máquina virtual de práctica.

## Solución de problemas frecuentes

- `VirtualBox` no inicia la VM: revisa en BIOS/UEFI que Intel VT-x/AMD-V esté habilitado y comprueba incompatibilidades con Hyper-V.
- `cd ... No existe el archivo o el directorio`: probablemente la carpeta se llama diferente o sigue dentro de Descargas; usa el explorador de archivos y verifica el nombre.
- `sudo: ...`: usa la **contraseña de Ubuntu**, no la del administrador web.
- `Connection refused`: ejecuta `sudo systemctl status postgresql` y verifica que la instalación terminó.
- `password authentication failed for user autoaudit_aplicacion`: revisa `AUTOAUDIT_CLAVE_APP` en `.env`; si la cambias después de instalar la base, el rol PostgreSQL no se actualiza por sí solo.
- `Address already in use`: otra aplicación usa 8080; detén la instancia anterior con Ctrl+C, o cambia `PUERTO_WEB` en `.env` si todavía estás usando PHP integrado.
- No aparecen los estilos: revisa la conexión a Internet dentro de Ubuntu (Bootstrap se obtiene por CDN).
- `Script 01` dice que la base ya existe: **no borres la base** automáticamente; revisa si el proceso anterior terminó y consulta el error.

## Precauciones

- Esta guía usa una máquina virtual para evitar modificar particiones de Windows.
- El proyecto es académico y se ejecuta localmente; no publiques la aplicación directamente a Internet.
- El instalador local agrega scripts, pero conserva el proyecto original y sus diagramas.
- El manual PDF del ZIP describe el método Docker y continúa siendo útil para estudiar código; para instalar sin Docker, sigue **este archivo**.
