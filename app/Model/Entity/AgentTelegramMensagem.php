<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class AgentTelegramMensagem {

	private static $tabelaOk = null;

	public $id;
	public $id_admin;
	public $chat_id;
	public $role;
	public $content;
	public $criado_em;

	public static function tabelaExiste(): bool {
		if (self::$tabelaOk !== null) {
			return self::$tabelaOk;
		}
		try {
			$row = (new Database())->execute("SHOW TABLES LIKE 'agent_telegram_mensagens'")->fetch(\PDO::FETCH_NUM);
			self::$tabelaOk = !empty($row);
		} catch (\Throwable $e) {
			self::$tabelaOk = false;
		}
		return self::$tabelaOk;
	}

	public static function salvar(int $idAdmin, string $chatId, string $role, string $content): void {
		if (!self::tabelaExiste()) {
			return;
		}
		$role = $role === 'assistant' ? 'assistant' : 'user';
		(new Database('agent_telegram_mensagens'))->insert([
			'id_admin' => $idAdmin,
			'chat_id' => mb_substr(trim($chatId), 0, 64),
			'role' => $role,
			'content' => mb_substr($content, 0, 8000),
		]);
	}

	/** @return array<int,array{role:string,content:string}> */
	public static function ultimas(int $idAdmin, string $chatId, int $limite = 8): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$limite = max(1, min(20, $limite));
		$sql = '
			SELECT role, content
			FROM agent_telegram_mensagens
			WHERE id_admin = '.(int)$idAdmin.'
			  AND chat_id = "'.addslashes(mb_substr(trim($chatId), 0, 64)).'"
			ORDER BY id DESC
			LIMIT '.(int)$limite.'
		';
		$rows = (new Database('agent_telegram_mensagens'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		$rows = array_reverse($rows);
		$out = [];
		foreach ($rows as $r) {
			$out[] = [
				'role' => (($r['role'] ?? '') === 'assistant') ? 'assistant' : 'user',
				'content' => (string)($r['content'] ?? ''),
			];
		}
		return $out;
	}

	public static function contarUltimaHora(int $idAdmin, string $chatId): int {
		if (!self::tabelaExiste()) {
			return 0;
		}
		$sql = '
			SELECT COUNT(*) AS qtd
			FROM agent_telegram_mensagens
			WHERE id_admin = '.(int)$idAdmin.'
			  AND chat_id = "'.addslashes(mb_substr(trim($chatId), 0, 64)).'"
			  AND role = "user"
			  AND criado_em >= DATE_SUB(NOW(), INTERVAL 1 HOUR)
		';
		$row = (new Database('agent_telegram_mensagens'))->execute($sql)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}
}
