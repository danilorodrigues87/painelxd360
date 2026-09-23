<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SocialAutomacaoLog {

	public $id;
	public $id_admin;
	public $id_automacao;
	public $comment_id;
	public $canal;
	public $comentario_txt;
	public $status;
	public $erro_msg;
	public $created_at;

	public static function jaProcessou(string $commentId): bool {
		$cid = addslashes($commentId);
		$row = (new Database('social_automacao_log'))->select(
			'comment_id = "'.$cid.'"',
			null,
			1,
			'id'
		)->fetch(\PDO::FETCH_ASSOC);
		return !empty($row);
	}

	public static function registrar(
		int $idAdmin,
		?int $idAuto,
		string $commentId,
		string $canal,
		string $status,
		?string $comentario = null,
		?string $erro = null
	): void {
		try {
			(new Database('social_automacao_log'))->insert([
				'id_admin' => $idAdmin,
				'id_automacao' => $idAuto,
				'comment_id' => mb_substr($commentId, 0, 128),
				'canal' => mb_substr($canal, 0, 20),
				'comentario_txt' => $comentario !== null ? mb_substr($comentario, 0, 500) : null,
				'status' => $status,
				'erro_msg' => $erro !== null ? mb_substr($erro, 0, 500) : null,
			]);
		} catch (\Throwable $e) {
			// unique comment_id: ignore race
		}
	}

	/** @return array<int,array> */
	public static function listRecentes(int $idAdmin, int $limite = 30): array {
		$stmt = (new Database('social_automacao_log'))->select(
			'id_admin = '.(int)$idAdmin,
			'id DESC',
			(int)$limite
		);
		$out = [];
		while ($r = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$out[] = $r;
		}
		return $out;
	}
}
