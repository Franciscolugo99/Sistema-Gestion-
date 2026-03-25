-- Fase 6 minima: presupuestos / remitos y trazabilidad documental.
-- No destructiva. Reutiliza documentos_comerciales / documento_items existentes.

SET @doc_origen_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'documentos_comerciales'
      AND COLUMN_NAME  = 'documento_origen_id'
);

SET @sql_add_doc_origen = IF(
    @doc_origen_exists = 0,
    'ALTER TABLE `documentos_comerciales` ADD COLUMN `documento_origen_id` INT(11) DEFAULT NULL AFTER `venta_id`',
    'SELECT 1 -- documentos_comerciales.documento_origen_id ya existe, skip'
);

PREPARE _stmt FROM @sql_add_doc_origen; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_doc_origen_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'documentos_comerciales'
      AND INDEX_NAME   = 'idx_documentos_origen'
);

SET @sql_add_doc_origen_idx = IF(
    @idx_doc_origen_exists = 0,
    'ALTER TABLE `documentos_comerciales` ADD KEY `idx_documentos_origen` (`documento_origen_id`)',
    'SELECT 1 -- idx_documentos_origen ya existe, skip'
);

PREPARE _stmt FROM @sql_add_doc_origen_idx; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;

SET @idx_doc_tipo_estado_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'documentos_comerciales'
      AND INDEX_NAME   = 'idx_documentos_tipo_estado_cliente'
);

SET @sql_add_doc_tipo_estado_idx = IF(
    @idx_doc_tipo_estado_exists = 0,
    'ALTER TABLE `documentos_comerciales` ADD KEY `idx_documentos_tipo_estado_cliente` (`tipo_documento`, `estado`, `cliente_id`)',
    'SELECT 1 -- idx_documentos_tipo_estado_cliente ya existe, skip'
);

PREPARE _stmt FROM @sql_add_doc_tipo_estado_idx; EXECUTE _stmt; DEALLOCATE PREPARE _stmt;
