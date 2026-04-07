UPDATE facturas
SET estado_fiscal = 'ERROR_TRANSITORIO',
    estado = CASE WHEN COALESCE(estado, 'PENDIENTE') = 'RECHAZADA' THEN 'PENDIENTE' ELSE estado END,
    fiscal_error_code = CASE
        WHEN COALESCE(TRIM(fiscal_error_code), '') IN ('', 'ARCA_ERROR') THEN 'TRANSIENT'
        ELSE fiscal_error_code
    END
WHERE COALESCE(TRIM(estado_fiscal), 'NO_APLICA') = 'RECHAZADA'
  AND (
      LOWER(COALESCE(fiscal_error_message, '')) LIKE '%arca no responde%'
      OR LOWER(COALESCE(fiscal_error_message, '')) LIKE '%no se puede emitir ahora porque arca no responde%'
      OR LOWER(COALESCE(fiscal_error_message, '')) LIKE '%soap-error: parsing wsdl%'
      OR LOWER(COALESCE(fiscal_error_message, '')) LIKE '%failed to load external entity%'
      OR LOWER(COALESCE(fiscal_error_message, '')) LIKE '%couldn''t load from%'
  );
