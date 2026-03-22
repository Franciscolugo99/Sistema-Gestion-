<?php
declare(strict_types=1);

final class StubNotaCreditoService implements NotaCreditoService
{
    public function emitir(EmitirNotaCreditoCommand $command): EmitirNotaCreditoResult
    {
        return EmitirNotaCreditoResult::rejected(
            $command->requestUid,
            $command->scope,
            'NOT_IMPLEMENTED',
            'La emision fiscal real de Nota de Credito queda pendiente para la Fase 2.'
        );
    }
}
