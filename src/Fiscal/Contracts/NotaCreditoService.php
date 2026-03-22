<?php
declare(strict_types=1);

interface NotaCreditoService
{
    public function emitir(EmitirNotaCreditoCommand $command): EmitirNotaCreditoResult;
}
