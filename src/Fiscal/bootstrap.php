<?php
declare(strict_types=1);

if (defined('FLUS_FISCAL_BOOTSTRAP_LOADED')) {
    return;
}
define('FLUS_FISCAL_BOOTSTRAP_LOADED', true);

require_once __DIR__ . '/../db_schema.php';
require_once __DIR__ . '/Contracts/NotaCreditoService.php';
require_once __DIR__ . '/Contracts/FiscalRecoveryService.php';
require_once __DIR__ . '/Contracts/FacturaFiscalRepository.php';
require_once __DIR__ . '/Contracts/AnulacionFiscalCoordinator.php';
require_once __DIR__ . '/DTO/EmitirNotaCreditoCommand.php';
require_once __DIR__ . '/DTO/EmitirNotaCreditoResult.php';
require_once __DIR__ . '/DTO/RecoveryResult.php';
require_once __DIR__ . '/DTO/AnulacionFiscalOutcome.php';
require_once __DIR__ . '/Repository/PdoFacturaFiscalRepository.php';
require_once __DIR__ . '/Service/StubNotaCreditoService.php';
require_once __DIR__ . '/Service/StubFiscalRecoveryService.php';
require_once __DIR__ . '/Service/StubAnulacionFiscalCoordinator.php';
require_once __DIR__ . '/Service/ArcaNotaCreditoService.php';
require_once __DIR__ . '/Service/DbAnulacionFiscalCoordinator.php';
