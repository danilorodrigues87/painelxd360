<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class LmsNotificacao {

	public $id;
	public $id_admin;
	public $id_aluno;
	public $tipo = 'system';
	public $titulo;
	public $mensagem = '';
	public $link;
	public $lida = 0;
	public $ref_chave;
	public $created_at;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_notificacoes'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelasExistem() || $id <= 0) {
			return null;
		}
		$row = (new Database('lms_notificacoes'))->select('id = '.(int)$id)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listByAluno(int $idAluno, int $idAdmin, int $limit = 50, bool $apenasNaoLidas = false): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$where = 'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin;
		if ($apenasNaoLidas) {
			$where .= ' AND lida = 0';
		}
		$stmt = (new Database('lms_notificacoes'))->select($where, 'created_at DESC, id DESC', (string)max(1, $limit));
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			$out[] = $row;
		}
		return $out;
	}

	public function cadastrar(): int {
		$db = new Database('lms_notificacoes');
		$this->id = $db->insert([
			'id_admin' => (int)$this->id_admin,
			'id_aluno' => (int)$this->id_aluno,
			'tipo' => (string)$this->tipo,
			'titulo' => (string)$this->titulo,
			'mensagem' => (string)$this->mensagem,
			'link' => $this->link,
			'lida' => !empty($this->lida) ? 1 : 0,
			'ref_chave' => $this->ref_chave,
		]);
		return (int)$this->id;
	}

	public function marcarLida(): bool {
		$this->lida = 1;
		return (new Database('lms_notificacoes'))->update('id = '.(int)$this->id, ['lida' => 1]);
	}

	public static function marcarTodasLidas(int $idAluno, int $idAdmin): void {
		if (!self::tabelasExistem()) {
			return;
		}
		(new Database('lms_notificacoes'))->update(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin.' AND lida = 0',
			['lida' => 1]
		);
	}

	public static function existeRef(int $idAluno, string $ref): bool {
		if (!self::tabelasExistem() || $ref === '') {
			return false;
		}
		$row = (new Database('lms_notificacoes'))->select(
			'id_aluno = '.(int)$idAluno.' AND ref_chave = "'.addslashes($ref).'"',
			null,
			'1',
			'id'
		)->fetch(PDO::FETCH_ASSOC);
		return !empty($row);
	}
}
