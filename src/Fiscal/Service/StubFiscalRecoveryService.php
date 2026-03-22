<?php
declare(strict_types=1);

final class StubFiscalRecoveryService implements FiscalRecoveryService
{
    public function recoverByRequestUid(string $requestUid, int $usuarioId): RecoveryResult
    {
        return RecoveryResult::error(
            $requestUid,
            'NOT_IMPLEMENTED',
            'La recuperacion post-ARCA queda pendiente para la Fase 2.'
        );
    }

    public function recoverPendings(int $limit = 50): array
    {
        return [];
    }
}
