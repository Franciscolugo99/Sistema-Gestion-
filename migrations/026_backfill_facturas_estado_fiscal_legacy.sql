UPDATE facturas
SET estado_fiscal = 'AUTORIZADA'
WHERE COALESCE(TRIM(cae), '') <> ''
  AND COALESCE(TRIM(estado_fiscal), 'NO_APLICA') IN ('', 'NO_APLICA')
  AND COALESCE(punto_venta, 0) > 0
  AND COALESCE(numero, 0) > 0
  AND (
    COALESCE(TRIM(naturaleza), 'FACTURA') = 'FACTURA'
    OR UPPER(COALESCE(TRIM(tipo), '')) NOT IN ('NCA', 'NCB', 'NCC', 'NDA', 'NDB', 'NDC')
  );
