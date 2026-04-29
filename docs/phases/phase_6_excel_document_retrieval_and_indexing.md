# Fase 6 - Excel Data Source Indexing and Documentary Retrieval

## Objetivo

Implementar la primera fuente de datos útil del MVP con carga de Excel, indexación de fragmentos recuperables y retrieval documental sobre PostgreSQL.

## Scope incluido

- Upload de archivos Excel.
- Validación, parsing e indexación.
- Metadata de archivos e import jobs.
- Contratos `DataSourceReader` y `DataSourceImporter`.
- Retrieval documental para inventario y conocimiento general.
- Segundo pase del runtime para responder desde contexto recuperado.

## Fuera de scope

- API data source genérico.
- Database adapters cliente.
- Vector DB o embeddings.

## Precondiciones

- Fase 5 terminada.
- Revisar [phase_5_tools_registry_and_execution_logging.md](./phase_5_tools_registry_and_execution_logging.md).

## Decisiones cerradas

- Fuente inicial única: Excel.
- Retrieval documental en PostgreSQL sobre fragmentos recuperables.
- Separación entre lectura con `DataSourceReader` e indexación con `DataSourceImporter`.
- La respuesta final al cliente la construye el modelo conversacional desde contexto recuperado.
- El archivo subido es la fuente canónica y los chunks son solo la representación de retrieval.

## Entregables

- Flujo de upload y persistencia de archivo.
- Indexador de Excel a fragmentos recuperables.
- Reader para consultas de retrieval.
- Tools funcionales `search_inventory` y `search_knowledge`.

## Cambios técnicos esperados

- Guardar archivos en almacenamiento persistente local.
- Persistir metadata y estado de importación.
- Diseñar una tabla interna de chunks recuperables con referencias de hoja y fila.
- Conectar `search_inventory` y `search_knowledge` a `DataSourceReader`.
- Permitir un segundo pase del runtime para que el LLM responda usando solo el contexto recuperado.
- No modelar inventario o FAQ como tablas rígidas de dominio.

## Interfaces o contratos a definir

- `DataSourceReader`
- `DataSourceImporter`
- DTO de resultado de importación
- contrato de retrieval para `search_inventory` y `search_knowledge`
- `DataSourceReader` MVP documentado con `search()` como contrato requerido
- `DataSourceImporter::sync()` como contrato de indexación del MVP

## Riesgos y validaciones

- Evitar acoplar el Excel a tablas de dominio rígidas.
- Evitar que las tools devuelvan respuestas finales en vez de contexto recuperado.
- Validar que búsquedas comunes se resuelvan sin necesidad de vector DB.

## Checklist de implementación

- Crear upload y storage metadata.
- Implementar parser/indexador Excel.
- Crear tabla interna de chunks recuperables.
- Implementar `search()`.
- Conectar tools de búsqueda al retrieval documental.
- Agregar segundo pase del runtime tras retrieval.
- Validar errores de importación y reintentos controlados.

## Criterio de done

- Un tenant puede cargar un Excel y consultar inventario y conocimiento mediante `search_inventory` y `search_knowledge`.
- El flujo deja trazabilidad de archivo, indexación y consultas.
- No existe importación a tablas rígidas de dominio ni editor manual de conocimiento como parte del MVP.
- La base de datos soporta retrieval documental sin capas extras.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 6 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_6_excel_document_retrieval_and_indexing.md
- docs/phases/phase_5_tools_registry_and_execution_logging.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y, si hace falta, ajustes mínimos de frontend o docs para probar uploads.
No implementes API data sources, database adapters ni vector search.
Explora primero el runtime y las tools existentes; luego agrega el vertical de Excel con indexación y retrieval documental para inventario y conocimiento.
Usa `DataSourceReader::search()` y `DataSourceImporter::sync()` como contratos definitivos de esta fase.
Completa funcionalmente `search_inventory` y `search_knowledge`.
Valida con pruebas de importación, búsqueda y uso desde tools.
```
