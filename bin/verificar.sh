#!/usr/bin/env bash
# bin/verificar.sh
#
# Reglas que se hacen cumplir con un grep y no con la disciplina. Corre en cada PR:
#     bash bin/verificar.sh
# Sale con error (1) si encuentra algo. En Windows: Git Bash.
#
# SECCIÓN BACKEND y SECCIÓN FRONTEND (F1, F2, F4, F10, prefijo de CSS, config.js sin mocks activos y sin config.local*.js,
# rutas de los mocks contra las de rutas.php y aislamiento de js/mocks/).
# Lo que no se puede comprobar con grep se revisa en el PR.

set -u
cd "$(dirname "$0")/.." || exit 1

errores=0
seccion() { printf '\n== %s\n' "$1"; }
ok() { printf '  ok  %s\n' "$1"; }
falla() { printf '  XX  %s\n' "$1"; errores=$((errores + 1)); }

# revisar "mensaje" "salida de grep": si hay salida, hay infracciones
revisar() {
  if [ -n "$2" ]; then
    falla "$1"
    printf '%s\n' "$2" | sed 's/^/        /'
  else
    ok "$1"
  fi
}

# ---------------------------------------------------------------------------- BACKEND
seccion "Backend: tiempo (RN-10, B4)"

# Prohibidas las funciones de hora de PostgreSQL y DEFAULT con fecha: toda fecha entra por Reloj.
revisar "SQL sin funciones de hora del motor" "$(
  grep -rEin --include='*.php' --include='*.sql' \
    -e '(^|[^a-z_])(now\(\)|current_timestamp|current_date|current_time|localtimestamp|clock_timestamp|statement_timestamp|transaction_timestamp|timeofday)($|[^a-z_])' \
    backend bin database 2>/dev/null || true)"

# En PHP, la hora solo nace en nucleo/Reloj.php
revisar "PHP sin time(), date(), strtotime(), new DateTime fuera de Reloj" "$(
  grep -rEn --include='*.php' --exclude='Reloj.php' \
    -e '(^|[^A-Za-z0-9_>:$\\])(time|date|gmdate|mktime|strtotime|date_create)\(' \
    -e 'new[[:space:]]+\\?DateTime(Immutable)?[[:space:]]*\(' \
    backend bin 2>/dev/null || true)"

seccion "Backend: capas (B1, B2, B3)"

revisar "Sin SQL ni conexión en Controlador ni Servicio" "$(
  grep -rEn --include='Controlador.php' --include='Servicio.php' \
    -e '->(prepare|query|exec)\(' -e 'Conexion::obtener' -e 'PDO::' \
    backend/modulos 2>/dev/null || true)"

revisar "Entrada HTTP solo en Solicitud" "$(
  grep -rEn --include='*.php' --exclude='Solicitud.php' \
    -e '\$_(GET|POST|REQUEST|FILES)\b' -e 'php://input' \
    backend 2>/dev/null || true)"

revisar "\$_SERVER solo en Solicitud y Sesion" "$(
  grep -rEn --include='*.php' --exclude='Solicitud.php' --exclude='Sesion.php' \
    -e '\$_SERVER' \
    backend 2>/dev/null || true)"

seccion "Backend: higiene"

revisar "Sin depuración olvidada (var_dump, print_r, var_export, phpinfo, die)" "$(
  grep -rEn --include='*.php' \
    -e '(^|[^A-Za-z0-9_>:$])(var_dump|print_r|var_export|phpinfo|die)\(' \
    backend 2>/dev/null || true)"

revisar "display_errors nunca activado" "$(
  grep -rEin --include='*.php' \
    -e "display_errors['\", ]+(1|on|true)" \
    backend 2>/dev/null || true)"

revisar "Todo PHP lleva declare(strict_types=1)" "$(
  grep -rL --include='*.php' 'declare(strict_types=1);' backend bin tests 2>/dev/null || true)"

seccion "Convención: la ruta del archivo en su encabezado"
sin_ruta=""
while IFS= read -r archivo; do
  if ! head -n 3 "$archivo" | grep -qF "$archivo"; then
    sin_ruta="${sin_ruta}${archivo}"$'\n'
  fi
done < <(find backend bin database tests docs -type f \
  \( -name '*.php' -o -name '*.sql' -o -name '*.sh' -o -name '*.md' -o -name '*.json' \) | sort)
revisar "Cada archivo trae su ruta en las 3 primeras líneas" "${sin_ruta%$'\n'}"

seccion "Base de datos: migraciones numeradas (B5)"
numero=1
fallo_migraciones=""
for archivo in database/migraciones/*.sql; do
  [ -e "$archivo" ] || continue
  nombre="$(basename "$archivo")"
  esperado="$(printf '%04d' "$numero")"
  if [[ ! "$nombre" =~ ^[0-9]{4}_[a-z0-9_]+\.sql$ ]]; then
    fallo_migraciones="${fallo_migraciones}nombre inválido: $nombre"$'\n'
  elif [ "${nombre%%_*}" != "$esperado" ]; then
    fallo_migraciones="${fallo_migraciones}se esperaba $esperado y está $nombre (sin saltos ni repetidos)"$'\n'
  fi
  numero=$((numero + 1))
done
revisar "Migraciones NNNN_nombre.sql, consecutivas" "${fallo_migraciones%$'\n'}"

seccion "Backend: sintaxis PHP"
if command -v php >/dev/null 2>&1; then
  sintaxis=""
  while IFS= read -r archivo; do
    salida="$(php -l "$archivo" 2>&1)" || sintaxis="${sintaxis}${salida}"$'\n'
  done < <(find backend bin tests -name '*.php' | sort)
  revisar "php -l en todos los archivos" "${sintaxis%$'\n'}"
else
  printf '  --  php no está en el PATH: se omitió php -l\n'
fi

# ---------------------------------------------------------------------------- FRONTEND
FRONT=frontend/js
# Código sin comentarios de línea ni de bloque (// , /* , * ...), con "archivo:línea:" delante.
sin_comentarios() { grep -vE '^[^:]+:[0-9]+:[[:space:]]*(//|/\*|\*)' || true; }

seccion "Frontend: imports (F1)"
# Un módulo importa solo de nucleo/, config.js, su propia carpeta y el .api.js de otro módulo.
# (modulos/index.js es el registro y no se revisa.)
fallo_f1=""
while IFS= read -r archivo; do
  while IFS= read -r ruta; do
    [ -n "$ruta" ] || continue
    case "$ruta" in
      ../../nucleo/*|../../config.js) continue ;;
    esac
    [[ "$ruta" =~ ^\./[^/]+$ ]] && continue
    if [[ "$ruta" =~ ^\.\./([a-z0-9_]+)/([a-z0-9_]+)\.api\.js$ ]] && [ "${BASH_REMATCH[1]}" = "${BASH_REMATCH[2]}" ]; then
      continue
    fi
    fallo_f1="${fallo_f1}${archivo}: importa \"${ruta}\""$'\n'
  done < <(grep -nE "(from[[:space:]]*|import[[:space:]]*\(?[[:space:]]*)['\"][^'\"]+['\"]" "$archivo" \
    | sed -E "s/^/${archivo//\//\\/}:/" | sin_comentarios \
    | grep -oE "['\"][^'\"]+['\"]" | tr -d "'\"")
done < <(find "$FRONT/modulos" -mindepth 2 -name '*.js' | sort)
revisar "Módulos importan solo de nucleo/, config.js, sí mismos o el .api.js de otro" "${fallo_f1%$'\n'}"

seccion "Frontend: backend solo por http (F2)"
revisar "fetch( solo en nucleo/http.js" "$(
  grep -rnE --include='*.js' --include='*.html' '(^|[^A-Za-z0-9_$.])fetch[[:space:]]*\(' frontend 2>/dev/null \
    | grep -v '^frontend/js/nucleo/http\.js:' || true)"

seccion "Frontend: HTML seguro (F4)"
revisar "Sin crudo() (ni su import) fuera de nucleo/ui.js" "$(
  grep -rnE --include='*.js' '\bcrudo\b' "$FRONT" 2>/dev/null \
    | grep -v '^frontend/js/nucleo/ui\.js:' | sin_comentarios || true)"
revisar "Sin innerHTML +=, outerHTML ni insertAdjacentHTML" "$(
  grep -rnE --include='*.js' -e 'innerHTML[[:space:]]*\+=' -e 'outerHTML[[:space:]]*[+]?=' -e 'insertAdjacentHTML' \
    "$FRONT" 2>/dev/null | sin_comentarios || true)"
revisar "innerHTML solo recibe html\`...\` o ''" "$(
  grep -rnE --include='*.js' 'innerHTML[[:space:]]*=[^=]' "$FRONT" 2>/dev/null | sin_comentarios \
    | grep -vE "innerHTML[[:space:]]*=[[:space:]]*(html\`|''|\"\")" || true)"

seccion "Frontend: CSS de módulos sin colores a mano (F10)"
CSS_MODULOS="$(find "$FRONT/modulos" -name '*.css' | sort)"
if [ -n "$CSS_MODULOS" ]; then
  # shellcheck disable=SC2086
  revisar "Solo variables de base.css (sin #hex, rgb(), hsl(), colores con nombre)" "$(
    grep -nE \
      -e ':[^;{}]*#[0-9a-fA-F]{3,8}\b' \
      -e '\b(rgba?|hsla?|hwb|lab|lch|oklab|oklch)\(' \
      -e ':[^;{}]*\b(white|black|red|green|blue|gray|grey|orange|yellow|purple|pink|silver|navy|teal|maroon|brown)\b' \
      $CSS_MODULOS 2>/dev/null || true)"
else
  ok "Sin CSS de módulos"
fi

seccion "Frontend: CSS con prefijo de 2 letras por módulo (lista en css/base.css)"
# La lista de prefijos vive en el comentario de css/base.css:  " *   lg-  → login   (js/modulos/login/login.css)".
# En js/modulos/<id>/<id>.css toda clase, @keyframes y variable propia empieza con su prefijo, y cada
# selector arranca con una clase del módulo (sin etiquetas ni #id sueltos).
BASE_CSS=frontend/css/base.css
LISTA_PREFIJOS="$(grep -E '^[[:space:]]*\*[[:space:]]+[a-z]{2}-[[:space:]]+→' "$BASE_CSS" 2>/dev/null || true)"
PREFIJOS="$(printf '%s\n' "$LISTA_PREFIJOS" | sed -E 's/^[[:space:]]*\*[[:space:]]+([a-z]{2})-.*/\1/' | grep -E '^[a-z]{2}$' || true)"

revisar "Ningún prefijo repetido en la lista de css/base.css" "$(printf '%s\n' "$PREFIJOS" | sort | uniq -d)"

if ! command -v perl >/dev/null 2>&1; then
  printf '  --  perl no está en el PATH: se omitió la revisión de prefijos de CSS\n'
else
  alternativa="$(printf '%s\n' "$PREFIJOS" | paste -sd'|' -)"
  if [ -n "$alternativa" ]; then
    revisar "css/base.css no define clases con prefijo de módulo" "$(
      perl -0777 -pe 's{/\*.*?\*/}{}gs' "$BASE_CSS" | grep -nE "\.($alternativa)-" || true)"
  fi

  fallo_prefijo=""
  while IFS= read -r archivo; do
    [ -n "$archivo" ] || continue
    id="$(basename "$(dirname "$archivo")")"
    prefijo="$(printf '%s\n' "$LISTA_PREFIJOS" | grep -F "(js/modulos/$id/$id.css)" \
      | sed -E 's/^[[:space:]]*\*[[:space:]]+([a-z]{2})-.*/\1/' | head -n1)"
    if [ -z "$prefijo" ]; then
      fallo_prefijo="${fallo_prefijo}${archivo}: el módulo \"$id\" no tiene prefijo en la lista de css/base.css"$'\n'
      continue
    fi
    salida="$(perl - "$prefijo" "$archivo" <<'PERL'
use strict;
use warnings;
my ($pref, $ruta) = @ARGV;
open(my $fh, '<', $ruta) or die "no se pudo abrir $ruta\n";
my $css = do { local $/; <$fh> };
close $fh;
$css =~ s{/\*.*?\*/}{}gs;                       # sin comentarios
my $p = "$pref-";
my %malos;
while ($css =~ /([^{}]+)\{/g) {
  my $sel = $1;
  $sel =~ s/^\s+|\s+$//g;
  next if $sel eq '';
  if ($sel =~ /^\@keyframes\s+(\S+)/) {
    $malos{"\@keyframes $1"} = 1 if index($1, $p) != 0;
    next;
  }
  next if $sel =~ /^\@/;                        # @media, @supports...
  $sel =~ s/\[[^\]]*\]//g;                      # sin el contenido de [atributos]
  for my $una (split /,/, $sel) {
    $una =~ s/^\s+|\s+$//g;
    next if $una eq '' or $una =~ /^(from|to|[0-9.]+%)$/;   # pasos de @keyframes
    $malos{$una} = 1 if $una !~ /^\.\Q$pref\E-/;           # arranca con clase propia
    while ($una =~ /\.([A-Za-z_][\w-]*)/g) {               # y todas las clases son propias
      $malos{".$1"} = 1 if index($1, $p) != 0;
    }
  }
}
while ($css =~ /(?<![\w-])(--[\w-]+)\s*:/g) {   # variables que se DEFINEN (var(--x) no cuenta)
  $malos{$1} = 1 if index($1, "--$p") != 0;
}
print "$ruta: $_ (prefijo $pref-)\n" for sort keys %malos;
PERL
)"
    [ -n "$salida" ] && fallo_prefijo="${fallo_prefijo}${salida}"$'\n'
  done <<< "$CSS_MODULOS"
  revisar "Todo en js/modulos/<id>/<id>.css empieza con el prefijo que le toca" "${fallo_prefijo%$'\n'}"
fi

seccion "Frontend: config.js sin mocks activos"
# La línea de base debe ser "MOCKS: false,". Los mocks solo se encienden con ?mocks= en localhost.
linea_mocks="$(grep -E '^[[:space:]]*MOCKS[[:space:]]*:' "$FRONT/config.js" 2>/dev/null || true)"
if printf '%s' "$linea_mocks" | grep -qE '^[[:space:]]*MOCKS[[:space:]]*:[[:space:]]*false[[:space:]]*,' \
   && [ "$(printf '%s\n' "$linea_mocks" | wc -l)" -eq 1 ]; then
  ok "config.js trae MOCKS: false"
else
  falla "config.js debe tener una sola línea base MOCKS: false (hay: ${linea_mocks:-ninguna})"
fi

revisar "Sin config.local*.js (los mocks se eligen con ?mocks= en la URL)" "$(
  find "$FRONT" -maxdepth 1 -name 'config.local*.js' 2>/dev/null | sort)"

seccion "Frontend: mocks alineados con el backend"
# Nada fuera de js/mocks/ y app.js (que lo carga con import dinámico) puede mencionar la carpeta mocks/.
revisar "Solo app.js depende de js/mocks/" "$(
  grep -rnE --include='*.js' --include='*.html' 'mocks/' frontend 2>/dev/null \
    | grep -v '^frontend/js/mocks/' | grep -v '^frontend/js/app\.js:' | sin_comentarios || true)"

# Cada ruta de js/mocks/handlers/*.js (método + ruta) debe existir en algún backend/modulos/*/rutas.php.
# Los parámetros ({id} en PHP, :id en JS) se comparan como :p. Lo contrario no se exige: no todo tiene mock.
if ! command -v perl >/dev/null 2>&1; then
  printf '  --  perl no está en el PATH: se omitió la comparación de rutas de mocks\n'
else
  rutas_php="$(find backend/modulos -name rutas.php | sort | xargs perl -ne \
    'while (/\$r->(get|post|put|patch|delete)\(\x27([^\x27]+)\x27/g) { my ($m, $r) = (uc $1, $2); $r =~ s/\{[^}]+\}/:p/g; print "$m $r\n" }' | sort -u)"
  rutas_mock="$(find "$FRONT/mocks/handlers" -name '*.js' | sort | xargs perl -0777 -ne \
    'while (/metodo:\s*\x27(\w+)\x27,\s*ruta:\s*\x27([^\x27]+)\x27/g) { my ($m, $r) = ($1, $2); $r =~ s/:[A-Za-z_]+/:p/g; print "$m $r\n" }' | sort -u)"
  if [ -z "$rutas_mock" ] || [ -z "$rutas_php" ]; then
    falla "No se pudieron leer las rutas de los mocks o de los rutas.php (revisa los patrones de esta sección)"
  else
    revisar "Toda ruta de los mocks existe en un rutas.php (mismo método y ruta)" \
      "$(comm -13 <(printf '%s\n' "$rutas_php") <(printf '%s\n' "$rutas_mock"))"
  fi
fi

# dominio.js contra Nucleo\Dominio (valores, permisos y matriz por rol): tests/dominio_front_test.php.

# ---------------------------------------------------------------------------- resultado
printf '\n'
if [ "$errores" -eq 0 ]; then
  echo "TODO EN ORDEN"
  exit 0
fi
echo "$errores comprobación(es) con problemas"
exit 1