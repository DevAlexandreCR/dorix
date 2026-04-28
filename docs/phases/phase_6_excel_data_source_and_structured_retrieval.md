# Fase 6 - Excel Data Source and Structured Retrieval

## Objetivo

Implementar la primera fuente de datos útil del MVP con carga de Excel, normalización interna y retrieval estructurado sobre PostgreSQL.

## Scope incluido

- Upload de archivos Excel.
- Validación, parsing e importación.
- Metadata de archivos e import jobs.
- Contratos `DataSourceReader` y `DataSourceImporter`.
- Retrieval estructurado para inventario y FAQ.

## Fuera de scope

- API data source genérico.
- Database adapters cliente.
- Vector DB o embeddings.

## Precondiciones

- Fase 5 terminada.
- Revisar [phase_5_tools_registry_and_execution_logging.md](/Users/alexandrecr/devs/gorda/auto/docs/phases/phase_5_tools_registry_and_execution_logging.md).

## Decisiones cerradas

- Fuente inicial única: Excel.
- Retrieval estructurado en PostgreSQL.
- Separación entre lectura con `DataSourceReader` e importación con `DataSourceImporter`.

## Entregables

- Flujo de upload y persistencia de archivo.
- Importador de Excel a tablas internas.
- Reader para consultas estructuradas.
- Tools funcionales `search_inventory` y `search_faq`.

## Cambios técnicos esperados

- Guardar archivos en almacenamiento persistente local.
- Persistir metadata y estado de importación.
- Diseñar tablas internas aptas para búsqueda de inventario y FAQs.
- Conectar `search_inventory` y `search_faq` a `DataSourceReader`.

## Interfaces o contratos a definir

- `DataSourceReader`
- `DataSourceImporter`
- DTO de resultado de importación
- contrato de búsqueda estructurada para `search_inventory` y `search_faq`

## Riesgos y validaciones

- Evitar importar hojas sin validación mínima de columnas.
- Evitar acoplar formato Excel a prompts del runtime.
- Validar que búsquedas comunes se resuelvan sin necesidad de vector DB.

## Checklist de implementación

- Crear upload y storage metadata.
- Implementar parser/importador Excel.
- Crear tablas internas para inventario y FAQ.
- Implementar `search()` y `find()`.
- Conectar tools de búsqueda al retrieval estructurado.
- Validar errores de importación y reintentos controlados.

## Criterio de done

- Un tenant puede cargar un Excel y consultar inventario y FAQ mediante `search_inventory` y `search_faq`.
- El flujo deja trazabilidad de archivo, importación y consultas.
- La base de datos soporta retrieval estructurado sin capas extras.

## Prompt sugerido para Codex

```text
Implementa solo la Fase 6 usando:
- docs/implementation_plan_index.md
- docs/phases/phase_6_excel_data_source_and_structured_retrieval.md
- docs/phases/phase_5_tools_registry_and_execution_logging.md
- docs/whatsapp_automation_mvp_architecture.md

Trabaja solo en backend/ y, si hace falta, ajustes mínimos de frontend o docs para probar uploads.
No implementes API data sources, database adapters ni vector search.
Explora primero el runtime y las tools existentes; luego agrega el vertical de Excel con importación y retrieval estructurado para inventario y FAQ.
Usa `DataSourceReader` y `DataSourceImporter` como contratos definitivos de esta fase.
Completa funcionalmente `search_inventory` y `search_faq`.
Valida con pruebas de importación, búsqueda y uso desde tools.
```
