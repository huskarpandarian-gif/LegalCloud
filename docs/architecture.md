# Arquitectura de LegalCloud AI

## Límites principales

- `app/Http/Controllers/Api/V1`: orquestación HTTP.
- `app/Http/Requests/Api/V1`: validación y autorización de entrada.
- `app/Http/Resources/Api/V1`: representación pública estable en camelCase.
- `app/Domain/Identity`, `app/Domain/Audit` y `app/Domain/AI`: servicios y contratos desacoplados.
- `app/Models`, `database/migrations` y `app/Jobs`: persistencia transaccional MySQL y procesamiento en segundo plano.
- `config/database.php` connection `vector` y `app/Domain/AI/VectorIndexService`: índice semántico PostgreSQL/pgvector separado.
- `docs/openapi.yaml`: contrato API-first versionado.

## Aislamiento y almacenamiento

Cada usuario pertenece a una organización. El tenant se resuelve desde el usuario autenticado, no desde un ID elegido por el cliente. Las consultas de expedientes, documentos y conversaciones se acotan en servidor. Los originales viven en el disco privado y las descargas usan enlaces temporales.

## Flujo documental e IA

La carga crea una versión inmutable con checksum y extrae el texto de PDF y DOCX. MySQL conserva texto y permisos; los embeddings siguen en PostgreSQL/pgvector cuando el proveedor está activo. Sin clave de OpenAI, el asistente responde citando los fragmentos recuperados. El detalle del esquema RAG está en [rag.md](rag.md).

## Deploy

`deploy/docker`, `deploy/terraform` y `deploy/kubernetes` quedan reservados para una decisión futura. Las instrucciones y pendientes están centralizados en [backend.md](backend.md).