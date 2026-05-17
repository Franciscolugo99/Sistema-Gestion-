<?php
declare(strict_types=1);

final class AnulacionFiscalOutcome
{
    public int $ventaId = 0;
    public int $ventaAnulacionId = 0;
    public string $estado = 'PENDIENTE';
    public string $estadoFiscal = 'PENDIENTE';
    public ?int $ncFacturaId = null;
    public ?string $requestUid = null;
    public ?string $message = null;
}
