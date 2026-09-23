<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class LmsAulaInterativaProgresso {

	public $id_aluno;
	public $id_aula;
	public $passo = 0;
	public $max_passo = 0;
	public $concluida = 0;
	public $atualizado_em;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$db = new Database();
			$stmt = $db->execute("SHOW TABLES LIKE 'lms_aula_interativa_progresso'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function get(int $idAluno, int $idAula) {
		if (!self::tabelaExiste()) {
			return null;
		}
		return (new Database('lms_aula_interativa_progresso'))->select(
			'id_aluno = '.(int)$idAluno.' AND id_aula = '.(int)$idAula,
			null,
			1
		)->fetchObject(self::class);
	}

	public static function upsert(int $idAluno, int $idAula, int $passo, int $maxPasso, int $concluida): void {
		if (!self::tabelaExiste()) {
			throw new \RuntimeException('Tabela lms_aula_interativa_progresso não existe.');
		}

		$passo = max(0, $passo);
		$maxPasso = max(0, $maxPasso);
		$concluida = $concluida ? 1 : 0;
		$exist = self::get($idAluno, $idAula);
		$db = new Database('lms_aula_interativa_progresso');

		if ($exist instanceof self) {
			$maxPasso = max($maxPasso, (int)$exist->max_passo, $passo);
			if ((int)$exist->concluida === 1) {
				$concluida = 1;
			}
			$db->update(
				'id_aluno = '.(int)$idAluno.' AND id_aula = '.(int)$idAula,
				[
					'passo' => $passo,
					'max_passo' => $maxPasso,
					'concluida' => $concluida,
				]
			);
			return;
		}

		$db->insert([
			'id_aluno' => (int)$idAluno,
			'id_aula' => (int)$idAula,
			'passo' => $passo,
			'max_passo' => max($maxPasso, $passo),
			'concluida' => $concluida,
		]);
	}
}
