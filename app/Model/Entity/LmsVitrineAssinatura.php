<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsVitrineAssinatura extends LmsBase {

	public $id;
	public $id_escola_assinante;
	public $id_escola_criadora;
	public $id_curso;
	public $status = 'ativa';
	public $inicio;
	public $cancelada_em;
	public $created_at;
	public $updated_at;

	protected static function table(): string {
		return 'lms_vitrine_assinaturas';
	}

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_vitrine_assinaturas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function ativaParaEscolaCurso(int $idEscola, int $idCurso): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = self::get(
			'id_escola_assinante = '.(int)$idEscola
			.' AND id_curso = '.(int)$idCurso
			.' AND status = "ativa"'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return self[] */
	public static function listAtivasEscola(int $idEscola): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$stmt = self::get('id_escola_assinante = '.(int)$idEscola.' AND status = "ativa"', 'id DESC');
		$out = [];
		while ($r = $stmt->fetchObject(self::class)) {
			$out[] = $r;
		}
		return $out;
	}

	public function salvar(): int {
		$dados = [
			'id_escola_assinante' => (int)$this->id_escola_assinante,
			'id_escola_criadora' => (int)$this->id_escola_criadora,
			'id_curso' => (int)$this->id_curso,
			'status' => $this->status === 'cancelada' ? 'cancelada' : 'ativa',
			'inicio' => $this->inicio ?: date('Y-m-d'),
			'cancelada_em' => $this->cancelada_em ?: null,
		];
		if (!empty($this->id)) {
			(new Database(self::table()))->update('id = '.(int)$this->id, $dados);
			return (int)$this->id;
		}
		$this->id = (int)(new Database(self::table()))->insert($dados);
		return (int)$this->id;
	}
}
