#!/usr/bin/env python3
"""Recrear diagramas SVG y PNG sin necesidad de PlantUML; usa Graphviz (dot)."""
from pathlib import Path
import subprocess
import cairosvg

carpeta = Path(__file__).resolve().parent

def renderizar(nombre: str, contenido: str, direccion: str = 'LR') -> None:
    archivo_dot = carpeta / f'{nombre}.dot'
    archivo_svg = carpeta / f'{nombre}.svg'
    archivo_png = carpeta / f'{nombre}.png'
    parametros = f'''digraph UML {{
    graph [rankdir={direccion}, fontname="DejaVu Sans", fontsize=11, bgcolor="white",
           nodesep=0.55, ranksep=0.7, pad=0.3, splines=polyline];
    node [fontname="DejaVu Sans", fontsize=10, shape=box, style="rounded,filled",
          color="#7c8eae", fillcolor="#edf2fc", margin="0.20,0.13"];
    edge [fontname="DejaVu Sans", fontsize=9, color="#62799c", arrowsize=0.8];
    {contenido}
}}'''
    archivo_dot.write_text(parametros, encoding='utf-8')
    subprocess.run(['dot', '-Tsvg', str(archivo_dot), '-o', str(archivo_svg)], check=True)
    cairosvg.svg2png(url=str(archivo_svg), write_to=str(archivo_png), output_width=1400)

renderizar('01_casos_de_uso', '''
admin [shape=ellipse, fillcolor="#d9e3ff", label="Administrador"];
lector [shape=ellipse, fillcolor="#d9e3ff", label="Usuario de consulta"];
subgraph cluster_sistema {label="AutoAudit — casos de uso"; color="#b0bddd"; style="rounded";
login [label="Iniciar sesión"]; explorar [label="Explorar tablas y columnas"];
config [label="Configurar reglas"]; generador [label="Generar triggers AFTER"];
pausar [label="Pausar / activar auditoría"]; lab [label="Probar INSERT / UPDATE / DELETE"];
hist [label="Consultar historial"]; filtrar [label="Filtrar usuario / columna"];
csv [label="Exportar CSV"];
}
admin -> login; admin -> explorar; admin -> config; admin -> pausar; admin -> lab; admin -> hist;
lector -> login; lector -> hist; lector -> filtrar; lector -> csv;
config -> generador [style=dashed, label="include"];
filtrar -> hist [style=dashed, label="extend"];
csv -> hist [style=dashed, label="extend"];
''')

renderizar('02_clases', '''
tabla [shape=record, label="{TablaAuditada|+ id_tabla : BIGINT\\l+ esquema : TEXT\\l+ nombre_tabla : TEXT\\l+ activa : BOOLEAN\\l+ version : INTEGER\\l}"];
columna [shape=record, label="{ColumnaAuditada|+ id_columna : BIGINT\\l+ nombre_columna : TEXT\\l+ activa : BOOLEAN\\l}"];
regla [shape=record, label="{ReglaAuditoria|+ id_regla : BIGINT\\l+ operacion : INSERT/UPDATE/DELETE\\l}"];
evento [shape=record, label="{BitacoraEvento|+ id_evento : BIGINT\\l+ operacion : TEXT\\l+ fecha : TIMESTAMPTZ\\l+ OLD : JSONB\\l+ NEW : JSONB\\l}"];
detalle [shape=record, label="{BitacoraDetalle|+ columna : TEXT\\l+ OLD : JSONB\\l+ NEW : JSONB\\l}"];
usuario [shape=record, label="{Usuario|+ id_usuario : BIGINT\\l+ nombre_usuario : TEXT\\l+ rol : TEXT\\l}"];
estacion [shape=record, label="{EstacionTrabajo|+ id_estacion : BIGINT\\l+ ip : INET\\l}"];
tabla -> columna [label="1 → 0..*"]; columna -> regla [label="1 → 0..*"];
tabla -> evento [label="1 → 0..*"]; evento -> detalle [label="1 → 1..*", arrowhead=diamond];
usuario -> evento [label="0..1 → 0..*"]; estacion -> evento [label="0..1 → 0..*"];
''')

renderizar('03_secuencia_configuracion', '''
admin [label="Administrador",fillcolor="#dce7ff"];
ui [label="Bootstrap / HTML"];
php [label="PHP / validación"];
config [label="configurar_tabla()"];
gen [label="generar_triggers()"];
pg [label="Catálogo / triggers PostgreSQL",fillcolor="#ecf9ee"];
admin -> ui [label="selecciona columnas"];
ui -> php [label="POST + CSRF"];
php -> pg [label="consulta catálogo"];
php -> config [label="JSONB"];
config -> pg [label="guarda reglas"];
config -> gen [label="regenera"];
gen -> pg [label="DROP / CREATE TRIGGER AFTER"];
pg -> php [label="resultado", style=dashed];
php -> ui [label="confirmación", style=dashed];
''')

renderizar('04_secuencia_auditoria', '''
usuario [label="Usuario"];
web [label="Interfaz web"];
php [label="PHP + transacción"];
clientes [label="Tabla public.clientes"];
trigger [label="Trigger AFTER / OLD-NEW",fillcolor="#e1e8ff"];
eventos [label="bitacora_eventos",fillcolor="#e3f5eb"];
detalles [label="bitacora_detalles",fillcolor="#e3f5eb"];
usuario -> web [label="editar teléfono"];
web -> php [label="POST CSRF"];
php -> clientes [label="SET contexto + UPDATE"];
clientes -> trigger [label="AFTER UPDATE"];
trigger -> trigger [label="comparar valores"];
trigger -> eventos [label="INSERT si cambió"];
eventos -> detalles [label="INSERT por columna"];
clientes -> php [label="resultado", style=dashed];
php -> web [label="COMMIT", style=dashed];
''')

renderizar('05_actividad', '''
inicio [shape=oval,label="Inicio",fillcolor="#d6eff9"];
login [label="Autenticarse"];
admin [shape=diamond,label="¿Admin?"];
config [label="Seleccionar tabla y reglas"];
triggers [label="Validar y generar triggers"];
cambio [label="Ejecutar INSERT / UPDATE / DELETE"];
regla [shape=diamond,label="¿Regla activa?"];
modificado [shape=diamond,label="¿Cambio real en UPDATE?"];
guardar [label="Guardar evento y detalles",fillcolor="#ddf4e4"];
ignorar [label="No crear evento",fillcolor="#fff2da"];
consulta [label="Consultar / filtrar / CSV"];
fin [shape=oval,label="Fin",fillcolor="#d6eff9"];
inicio -> login -> admin;
admin -> config [label="sí"]; config -> triggers -> cambio;
admin -> cambio [label="no"];
cambio -> regla;
regla -> modificado [label="sí"];
regla -> ignorar [label="no"];
modificado -> guardar [label="sí o INSERT / DELETE"];
modificado -> ignorar [label="sin cambios"];
guardar -> consulta;
ignorar -> consulta;
consulta -> fin;
''', 'TB')

renderizar('06_componentes', '''
subgraph cluster_servidor { label="Servidor Ubuntu 26.04 LTS (Docker)"; color="#9eadd1";
subgraph cluster_web { label="Contenedor web: PHP 8.3 / Apache";
bootstrap [label="Interfaz Bootstrap"];
controlador [label="PHP: Controlador / PDO"];
seguridad [label="Sesión / CSRF / roles"];
}
subgraph cluster_postgres {label="Contenedor BD: PostgreSQL 18";
catalogo [label="Catálogo + tablas de reglas"];
generador [label="Funciones PL/pgSQL"];
clientes [label="public.clientes / productos"];
bitacora [label="bitácora central"];
}
}
bootstrap -> controlador -> seguridad;
controlador -> catalogo [label="consultar / configurar"];
controlador -> clientes [label="CRUD"];
catalogo -> generador -> clientes [label="CREATE TRIGGER"];
clientes -> bitacora [label="AFTER INSERT / UPDATE / DELETE"];
''')

renderizar('07_entidad_relacion', '''
node [shape=record];
usuarios [label="{usuarios|PK id_usuario\\lnombre_usuario\\lcontrasena_hash\\lrol\\l}"];
estaciones [label="{estaciones_trabajo|PK id_estacion\\lidentificador\\ldireccion_ip\\l}"];
tablas [label="{tablas_auditadas|PK id_tabla\\lesquema\\ltabla\\lversion_configuracion\\l}"];
columnas [label="{columnas_auditadas|PK id_columna\\lFK id_tabla\\lnombre_columna\\l}"];
reglas [label="{reglas_auditoria|PK id_regla\\lFK id_columna\\loperacion\\l}"];
eventos [label="{bitacora_eventos|PK id_evento\\lFK id_tabla\\lFK id_usuario (NULL)\\lFK id_estacion (NULL)\\lregistro_anterior / nuevo\\l}"];
detalles [label="{bitacora_detalles|PK id_detalle\\lFK id_evento\\lcolumna / OLD / NEW\\l}"];
tablas -> columnas [label="1 : N"];
columnas -> reglas [label="1 : N"];
tablas -> eventos [label="1 : N"];
usuarios -> eventos [label="1 : N opcional"];
estaciones -> eventos [label="1 : N opcional"];
eventos -> detalles [label="1 : N"];
''')
print('Diagramas generados en', carpeta)
