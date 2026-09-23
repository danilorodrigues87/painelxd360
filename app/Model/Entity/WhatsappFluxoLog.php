<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappFluxoLog {

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'whatsapp_fluxo_logs'");
			$ok = (bool)$st->fetch();
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function registrar(int $idAdmin, int $conversaId, int $fluxoId, ?string $nodeId, string $evento, ?string $detalhe = null): void {
		if (!self::tabelaExiste()) {
			return;
		}
		try {
			(new Database('whatsapp_fluxo_logs'))->insert([
				'id_admin'    => $idAdmin,
				'conversa_id' => $conversaId,
				'fluxo_id'    => $fluxoId,
				'node_id'     => $nodeId !== null ? mb_substr($nodeId, 0, 64) : null,
				'evento'      => mb_substr($evento, 0, 40),
				'detalhe'     => $detalhe !== null ? mb_substr($detalhe, 0, 255) : null,
			]);
		} catch (\Throwable $e) {
			// não quebra o bot
		}
	}
}
