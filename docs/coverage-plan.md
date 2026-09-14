# Plan de cobertura para la publicación

Estado medido el 14 de septiembre de 2026: 640 pruebas en verde, **81.54%** de líneas sobre
código ejecutable (5425/6653) y **60.84%** de métodos (567/932). La meta es publicar el stack
antes de que termine 2026, con Foundation ya listo.

La cifra global de 80.35% que reporta PHPUnit incluye los ocho ficheros de constantes que
Composer precarga; Xdebug cuenta sus `const` de nivel de fichero como sentencias pero el driver
arranca después del preload y nunca las ve ejecutarse. Son 99 sentencias de artefacto. Toda
cifra de este documento las descuenta.

## Qué es realmente lo que falta

De las 1228 sentencias sin cubrir, **1072 (87%) caen en diez frentes**; el resto son 156
repartidas por los demás ficheros. Y 160 de los 932 métodos nunca se entran una sola vez, en
familias coherentes, no dispersos. La deuda está concentrada, y eso es lo que hace que la meta
sea alcanzable.

El punto importante: **la mayoría no es lógica difícil, es superficie no visitada.** Los tres
backends de `RowCache` están al 42.7% porque `snapshots()`, `setSnapshots()` y
`deletePropertySnapshots()` — sus operaciones en bloque — no se llaman nunca. El hueco mayor de
`SQLGenerator` es un `switch` de 66 líneas que mapea `ExpressionOperatorType` a nombres de
función SQL: una tabla de datos, un caso por operador. Eso se cubre con un `#[DataProvider]`
sobre el enum, no con diseño de pruebas.

## Los diez frentes, en orden de ejecución

El orden es por rendimiento y por riesgo: primero lo mecánico y aislado, al final lo que toca
el núcleo y puede romper otras pruebas.

| # | Frente | Sin cubrir | Actual | Por qué aquí |
|---|---|---|---|---|
| ~~A~~ | ~~Operaciones en bloque de `RowCache`~~ | ~~82~~ | **hecho** | 52 pruebas; los tres backends al 95-100% |
| ~~J~~ | ~~Tipos pequeños sin prueba~~ | ~~93~~ | **hecho** | 47 pruebas; 67 de 93 cerradas, el resto inalcanzable |
| B | Contrato del store abstracto | 20 | 52.4% | Métodos-plantilla de una línea; un doble de prueba los cubre todos |
| D | `SQLGenerator` | 218 | 81.3% | El mayor hueco individual, y 66 líneas son tabla de datos |
| E | Contextos de petición SQL | 101 | 67.7% | `SQLSaveChangesRequestContext` al 46.2% es el peor; `resolveUpsertConflicts` entero sin tocar |
| C | Coordinador y modelo | 91 | 63.6% | `metadataForPersistentStore`, `mergedModel`, plantillas de fetch |
| I | Adaptador y fontanería SQL | 134 | 82.4% | DDL sin ejercitar: crear/soltar índices, renombrar tablas |
| F | Motor de migración | 142 | 69.8% | `StagedMigrationManager` al 48.6%: todas las ramas de validación |
| H | Store atómico | 72 | 84.9% | Escritura y faulting del XML |
| G | Núcleo del grafo de objetos | 119 | 88.5% | Lo más delicado; se deja al final a propósito |

**Progreso.** Frentes A y J cerrados el 14 de septiembre de 2026: 739 pruebas (desde 640),
cobertura ejecutable **84.02%** (desde 81.54%, +2.48 puntos).

**Proyección medida, no estimada.** Si los diez frentes cierran el 75% de su deuda, el total
llega a **93.6%**; al 90%, a **96.0%**. Incluso al 50% el resultado es 89.6%. La meta razonable
para publicar es la banda del 90-93%: por encima de eso el esfuerzo por punto se dispara
contra código que, como se explica abajo, no debe cubrirse.

## Lo que NO se va a cubrir, y por qué

Esto es parte del plan, no una excusa. "En la medida de lo que es posible cubrir" tiene un
límite concreto y medido:

- **67 líneas de `fatal_error()` / `request_concrete_implementation()` / `unimplemented()`.**
  Son los guardias de "esto no debería ocurrir" y los miembros deliberadamente no
  implementados que honran la forma del contrato de Core Data (`QueryGenerationToken::current()`,
  `FetchIndexDescription::$partialIndexPredicate`, los tipos de store `binary` e `inMemory`).
  Ya está decidido que se quedan; cubrirlos exigiría construir estados inválidos a propósito.
- **Los ocho ficheros de constantes precargados.** Artefacto de medición, no código.
- **`MemoryObjectStore` y `BinaryObjectStore`.** No tienen `load()`; no se pueden montar en un
  coordinador. Son cascarones intencionales.
- **`FetchRequestExpression`** (17 sentencias), confirmado al abordar el frente J: es `final` con
  constructor `protected` y no existe ninguna fábrica —Foundation no tiene `expressionForFetch`—,
  así que nada fuera de su propia jerarquía puede construirla. Solo la reflexión llegaría, y una
  prueba que refleja verifica la reflexión, no un contrato. Reserva el nombre de
  `NSFetchRequestExpression` en el sitio correcto. `SQLSavePlan` y `SQLAttributeTrigger` tampoco
  se construyen en `src/`.

Con eso descontado, el techo real está cerca del 96%, no del 100%.

## Cómo se trabaja cada frente

1. **Medir antes**: `--coverage-clover` y anotar el fichero objetivo. Parsear con
   `$xml->xpath("//file")`; `$xml->project->file` se salta la mayoría de ficheros porque están
   anidados en `<package>`.
2. **Una suite por familia**, copiando la forma de `tests/RowCacheTest.php`: un contrato,
   un `#[DataProvider]` de factorías, y `markTestSkipped` cuando la máquina no puede hospedar
   el backend.
3. **Mutar siempre que una suite salga verde a la primera.** Es la regla que atrapó el
   hallazgo real de `AtomicStore` (un clamp que no hacía nada y un comentario que afirmaba lo
   contrario). 17/17 al primer intento es motivo de sospecha, no de confianza.
4. **Medir después** y confirmar que el delta es el esperado.

Invocación (Xdebug por invocación, nunca en `php.ini`, o se envenenan las mediciones de
rendimiento; `apc.enable_cli=1` es lo que evita los 15 skips de `RowCacheTest`):

```sh
php -d zend_extension=xdebug -d xdebug.mode=coverage -d apc.enable_cli=1 -d memory_limit=2G \
  "$APPDATA/Composer/vendor/bin/phpunit" --coverage-clover=cov.xml
```

El ciclo es cómodo: 46s sin cobertura, 2:47 con ella.

## Riesgos

- **`SQLGenerator` es territorio guardado.** Las pruebas van contra `->statement`, sin ejecutar
  queries, como hace `SQLGeneratorTest`. El contrato del hidratador no se toca: se adapta el
  generador a él, nunca al revés.
- **Los tests de migración necesitan MariaDB real** y un `.env` con `SQL_SCHEMA_NAME`; el
  frente F no corre en una máquina sin servidor. Es el único frente con dependencia externa.
- **Frente G al final** porque `ManagedObject` y `ManagedObjectContext` están al 88.5%: lo que
  queda son las ramas raras, y tocarlas puede mover el comportamiento del que dependen las
  otras 640 pruebas.
- Los nombres de clase de las entidades de prueba comparten un solo espacio de nombres:
  comprobar con `grep -rho "^final class [A-Za-z]* extends ManagedObject" tests/*.php` antes de
  bautizar una fixture nueva.

## Relación con el resto de la publicación

La cobertura es el punto 1 de la lista de pendientes. No bloquea a `docs/`, y Packagist más las
etiquetas van al final por instrucción explícita: primero se publica y etiqueta Foundation,
luego se quita de `composer.json` el bloque `repositories` que apunta a `../Foundation`.
