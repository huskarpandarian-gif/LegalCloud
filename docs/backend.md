# Backend y operación local

## Requisitos y MySQL

- PHP 8.3+, Composer y extensiones PDO MySQL, Fileinfo y Mbstring.
- MySQL 8 o MariaDB 10.4+. La migración se verificó con MariaDB 10.4.32 de XAMPP.
- El esquema usa UUID públicos, claves foráneas, índices por organización y borrado lógico para expedientes/documentos.
- La búsqueda vectorial usa PostgreSQL con la extensión `pgvector`; MySQL conserva usuarios, expedientes, documentos, chunks de texto, auditoría y jobs.

Para una instalación local XAMPP, crea una base vacía y un usuario dedicado desde phpMyAdmin o la consola:

```sql
CREATE DATABASE legalcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'legalcloud'@'localhost' IDENTIFIED BY 'elige-una-clave-local';
GRANT ALL PRIVILEGES ON legalcloud.* TO 'legalcloud'@'localhost';
```

Copia `.env.example` a `.env`, genera `APP_KEY` con `php artisan key:generate` y configura host, puerto, base, usuario y contraseña. Para XAMPP local que use `root` sin contraseña, puedes usar esas credenciales solo en desarrollo; no las reutilices en cloud. Ejecuta `php artisan migrate` para crear el esquema. Usa `LEGALCLOUD_API_MODE=api` para activar HTTP en el frontend y `LEGALCLOUD_API_TOKEN_TTL_DAYS` para configurar expiración de tokens.

Crea la primera organización y su administrador; la contraseña se solicita oculta y no queda en el historial:

```powershell
php artisan legalcloud:bootstrap "Navarro & Asociados" admin@example.com --name="Mariana García"
```

## Seguridad

`POST /api/v1/auth/login` emite un token aleatorio de 256 bits; solo guarda SHA-256 y el token se devuelve una vez. Tiene expiración configurable y se revoca al cerrar sesión. El login limita intentos por usuario/IP. Los roles son `administrator`, `records_manager`, `analyst` y `consultant`.

`auth:api`, `organization.active` y `role` aplican autenticación, tenant activo y permisos. Las consultas se filtran por la organización del usuario autenticado. Las respuestas usan Resources para no serializar secretos ni columnas internas.

## Cifrado de datos sensibles

Laravel cifra con AES-256-CBC autenticado los archivos originales antes de escribirlos en `storage/app/private`; las descargas solo se descifran tras autorización y llevan `Cache-Control: private, no-store`. OCR recibe un temporal con permisos `0600`, eliminado al finalizar. Texto OCR, chunks, mensajes, citas, títulos/descripciones, referencias, nombres de archivo, email/nombre, notificaciones, resultados de jobs y payloads/IPs de auditoría se cifran en MySQL; sesiones de Laravel también están cifradas. Los emails y referencias usan además HMAC-SHA256 con `APP_KEY` para login/unicidad sin dejar esos valores en claro. En PostgreSQL se cifran los fragmentos textuales asociados a vectores; los embeddings permanecen como vectores consultables y requieren permisos de rol/TLS/cifrado del volumen.

`APP_KEY` debe generarse una vez, guardarse en un gestor de secretos y respaldarse de forma segura. No lo regeneres: perderlo impide descifrar los datos existentes y cambia los HMAC de búsqueda. Para filas heredadas, primero revisa `php artisan legalcloud:encrypt-sensitive-data --dry-run`; cuando el inventario esté aprobado, ejecuta el comando sin `--dry-run`. El backfill es idempotente y no imprime datos. Respaldar la base y almacenamiento privado antes de rotar claves. Las búsquedas cifradas sobre títulos/nombres hacen un escaneo acotado a 5000 registros autorizados; para escalar debe añadirse un índice de búsqueda diseñado sin almacenar texto sensible en claro.

El índice pgvector guarda el embedding necesario para calcular similitud y referencias de tenant; el fragmento textual que lo acompaña se cifra. Restringe el rol PostgreSQL, usa TLS y cifrado de disco administrado. Las llamadas LLM envían solo fragmentos autorizados y usan `store=false`.

## API implementada

Todas las rutas viven bajo `/api/v1`. El contrato completo está en [openapi.yaml](openapi.yaml) y también se sirve en `GET /api/v1/openapi.yaml`. Salvo el login y ese contrato, hace falta `Authorization: Bearer <token>`. El tenant sale del usuario autenticado. Los roles son `administrator`, `records_manager`, `analyst` y `consultant`.

| Método y ruta | Quién | Qué hace |
| --- | --- | --- |
| `POST /auth/login` | Público, con límite de intentos | Devuelve el token una sola vez. Solo se guarda su SHA-256. |
| `POST /auth/logout` | Autenticado | Revoca el token actual. |
| `GET /users`, `GET /users/{userId}` | Autenticado | Lista o consulta usuarios de la organización. |
| `POST /users`, `PATCH /users/{userId}` | `administrator` | Crea o actualiza un usuario. |
| `GET /cases`, `GET /cases/{caseId}` | Autenticado | Lista o consulta expedientes. |
| `POST /cases`, `PATCH /cases/{caseId}` | `administrator`, `records_manager`, `analyst` | Crea o actualiza un expediente. |
| `GET /categories` | Autenticado | Lista categorías. |
| `POST /categories` | `administrator`, `records_manager` | Crea una categoría. |
| `GET /documents`, `GET /documents/{documentId}` | Autenticado | Lista o consulta documentos, con filtros `caseId`, `categoryId`, `status`. |
| `POST /documents` | `administrator`, `records_manager`, `analyst` | Carga PDF, DOC/DOCX o imagen de hasta 25 MiB, crea la versión y encola la extracción. |
| `PATCH /documents/{documentId}` | `administrator`, `records_manager`, `analyst` | Cambia título, descripción o categoría. |
| `DELETE /documents/{documentId}` | `administrator`, `records_manager`, `analyst` | Archiva el documento. |
| `GET /documents/{documentId}/versions` | Autenticado | Lista versiones inmutables. |
| `POST /documents/{documentId}/versions` | `administrator`, `records_manager`, `analyst` | Agrega una versión y vuelve a extraer. |
| `GET /documents/{documentId}/versions/{versionId}/download` | Autenticado | Descarga el archivo descifrado, sin caché. |
| `POST /documents/{documentId}/ocr` | `administrator`, `records_manager`, `analyst` | Reintenta la extracción de la versión vigente. Responde 202. |
| `GET /processing-jobs/{jobId}` | Autenticado | Estado de un job `ocr` o `embedding`: `queued`, `processing`, `completed` o `failed`. |
| `POST /search` | Autenticado | Busca en la documentación autorizada. Ver modos abajo. |
| `GET /assistant/conversations`, `POST /assistant/conversations` | Autenticado | Lista o crea conversaciones del usuario. |
| `POST /assistant/conversations/{conversationId}/messages` | Autenticado | Pregunta RAG. Guarda la pregunta, la respuesta y las citas. |
| `GET /assistant/messages/{messageId}` | Autenticado | Recupera una respuesta y sus citas. |
| `GET /notifications`, `PATCH /notifications/{notificationId}/read` | Autenticado | Bandeja del propio usuario. |
| `GET /audit-events` | `administrator`, `records_manager` | Auditoría de la organización. |
| `GET /dashboard` | Autenticado | Conteos de expedientes, documentos, jobs y actividad reciente. |

`POST /search` acepta `query`, `mode` (`keyword`, `semantic` o `hybrid`; por defecto `hybrid`), `caseId`, `categoryId`, `from`, `to`, `page` y `perPage`. `keyword` recorre título, descripción y fragmentos en MySQL. `semantic` calcula el embedding de la consulta y ordena por distancia coseno en pgvector; si el índice o el proveedor de embeddings no están listos, responde `503`. `hybrid`, con el índice encendido, pide los dos rankings y los fusiona con Reciprocal Rank Fusion (RRF): un documento que aparece bien situado en ambas listas queda por encima de uno que solo aparece en una. Con el índice apagado, `hybrid` usa solo MySQL.

El asistente sigue el mismo criterio de evidencia. El cuerpo lleva `question`, y de forma opcional `caseId`, `documentIds` (máximo 50) y `answerMode` (`answer` o `summary`). Sin fragmentos autorizados, la respuesta lo dice y no llama al modelo. Con fragmentos, OpenAI responde solo si está habilitado y hay clave; si no, el respondedor extractivo cita frases de esos fragmentos. Un fallo de OpenAI responde `503` con `llm_provider_failed`. Si pgvector está encendido pero los embeddings no, responde `503` con `embedding_provider_unavailable`. Una respuesta completa incluye `citations[].reference`, documento, versión, página, extracto y relevancia.

## PostgreSQL y pgvector

La conexión `vector` está separada de la conexión MySQL principal. Instala PostgreSQL con `pgvector` y habilita `pdo_pgsql` y `pgsql` en el `php.ini` que usa XAMPP/CLI; reinicia Apache si también atenderá peticiones web. Crea una base y un usuario dedicados, configura `VECTOR_DB_HOST`, `VECTOR_DB_PORT`, `VECTOR_DB_DATABASE`, `VECTOR_DB_USERNAME` y `VECTOR_DB_PASSWORD`, y usa dimensiones compatibles con el modelo de embeddings seleccionado. El ejemplo inicial usa 1536 dimensiones.

```sql
CREATE DATABASE legalcloud_vectors;
CREATE USER legalcloud_vectors_user WITH PASSWORD 'elige-una-clave-local';
GRANT ALL PRIVILEGES ON DATABASE legalcloud_vectors TO legalcloud_vectors_user;
```

Con el rol autorizado para instalar la extensión, habilita el índice idempotente:

```powershell
php artisan legalcloud:vector-migrate
```

El comando crea `vector`, la tabla `document_vectors` y un índice HNSW con distancia coseno. Después configura `VECTOR_DB_ENABLED=true`, `VECTOR_DB_CONNECTION=vector`, `VECTOR_EMBEDDING_DIMENSIONS` y `VECTOR_EMBEDDING_MODEL`. Esas dimensiones y ese modelo tienen que coincidir con `OPENAI_EMBEDDING_MODEL` (por defecto `text-embedding-3-small`, 1536 dimensiones). El worker indexa los fragmentos en PostgreSQL después de la extracción cuando el almacén vectorial está habilitado. Para que el índice se llene también hace falta `LEGALCLOUD_EMBEDDINGS_ENABLED=true`, `LEGALCLOUD_EMBEDDINGS_PROVIDER=openai` y `OPENAI_API_KEY`. Sin esa clave, el job de embedding queda `failed` con `embedding_provider_unavailable` y la búsqueda semántica responde `503`. El texto OCR ya indexado en MySQL sigue buscable por palabras.

La cuenta de aplicación solo necesita permisos sobre sus tablas en `legalcloud_vectors`; reserva la instalación de extensiones para el rol de administración de la base. El índice no almacena documentos como fuente de verdad: autorización, versión vigente y metadatos se vuelven a comprobar en MySQL al servir los resultados.

## Archivos y jobs

Se aceptan PDF, DOC/DOCX, PNG/JPG y TIFF de hasta 25 MiB. Los archivos cifrados quedan en `storage/app/private`, no en `public/`. Cada versión conserva nombre, MIME, tamaño y checksum SHA-256. Las descargas autenticadas se descifran bajo demanda, no generan URL pública ni se guardan en caché.

Desarrollo local con `QUEUE_CONNECTION=database`:

```powershell
php artisan queue:work --queue=document-processing,default
```

La extracción se procesa fuera de HTTP. `LEGALCLOUD_OCR_PROVIDER=native` (el valor por defecto) lee la capa de texto de PDF y el XML de DOCX, parte el texto en fragmentos solapados y marca el documento como `indexed`. No rellena texto que el archivo no contenga. PNG, JPG, TIFF, PDF escaneados sin texto y DOC binario dejan el job en `failed` con `ocr_provider_unavailable`. Para esos formatos implementa `App\Domain\AI\Contracts\OcrProvider` y cambia `LEGALCLOUD_OCR_PROVIDER`. `EmbeddingProvider` y `LlmProvider` aíslan sus llamadas a OpenAI.

## LLM y privacidad

Sin clave externa, `ExtractiveLlmProvider` responde solo con frases de los fragmentos autorizados y las cita como `[S#]`. No inventa normas ni páginas. OpenAI Responses API, modelo **`gpt-4.1-mini`**, se usa únicamente con `LEGALCLOUD_LLM_ENABLED=true`, `LEGALCLOUD_LLM_PROVIDER=openai` y `OPENAI_API_KEY`. `OPENAI_MODEL` cambia el modelo y `OPENAI_BASE_URL` el endpoint. La interfaz arranca en modo API (`LEGALCLOUD_API_MODE=api`) y exige iniciar sesión.

Solo se envían hasta cinco fragmentos relevantes, limitados a documentos de la organización, filtros de la consulta y versión actual; el prompt exige tratar el texto documental como dato no confiable y citar fuentes `[S#]`. Si no hay fuentes, no se invoca el LLM. Los errores HTTP/red se convierten en 503 sin revelar respuestas upstream ni credenciales. La prueba del cliente usa HTTP fakes; no se ha realizado una llamada real porque no hay clave configurada.

## Pruebas

La suite normal usa SQLite en memoria para mantenerse aislada:

```powershell
php artisan test
php artisan test --filter=ApiV1Test
```

Para probar MySQL, crea una base desechable separada y configura temporalmente `DB_CONNECTION=mysql`, host, puerto, base, usuario y contraseña en la sesión de PowerShell. `migrate:fresh` elimina todas las tablas de la base seleccionada: nunca lo ejecutes contra datos que quieras conservar.

## Deploy pendiente

`deploy/docker`, `deploy/terraform` y `deploy/kubernetes` reservan la ubicación, sin manifiestos de producción todavía. Antes de desplegar hay que seleccionar proveedor y servicios para MySQL administrado, storage privado compatible con URLs firmadas, colas, secretos, TLS, backups, observabilidad, OCR, embeddings y LLM.