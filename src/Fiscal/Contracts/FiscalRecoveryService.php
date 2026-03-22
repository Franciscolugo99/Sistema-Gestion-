<?php
declare(strict_types=1);

interface FiscalRecoveryService
{
    public function recoverByRequestUid(string $requestUid, int $usuarioId): RecoveryResult;

    /**
     * @return array<int,RecoveryResult>
     */
    public function recoverPendings(int $limit = 50): array;
}
