<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsAulaAnotacao;

class LmsAnotacaoHelper {

	public const MAX_LEN = 50000;

	public static function tabelasExistem(): bool {
		return LmsAulaAnotacao::tabelasExistem();
	}

	/** @return array{text:string,updatedAt:?string} */
	public static function getForApi(int $idAdmin, int $idAluno, int $idAula): array {
		if (!self::tabelasExistem()) {
			return ['text' => '', 'updatedAt' => null];
		}
		$row = LmsAulaAnotacao::getByAlunoAula($idAluno, $idAula, $idAdmin);
		if (!$row) {
			return ['text' => '', 'updatedAt' => null];
		}
		$ts = $row->updated_at ?: $row->created_at;
		return [
			'text' => (string)($row->texto ?? ''),
			'updatedAt' => $ts ? date('c', strtotime((string)$ts)) : null,
		];
	}

	/**
	 * @return array{ok:bool,text?:string,updatedAt?:?string,message?:string}
	 */
	public static function salvar(
		int $idAdmin,
		int $idAluno,
		int $idAula,
		?int $idCurso,
		string $texto
	): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_aula_anotacoes.sql'];
		}
		if (mb_strlen($texto) > self::MAX_LEN) {
			return ['ok' => false, 'message' => 'Anotação muito longa (máx. '.self::MAX_LEN.' caracteres).'];
		}

		$existente = LmsAulaAnotacao::getByAlunoAula($idAluno, $idAula, $idAdmin);
		if ($existente) {
			$existente->atualizarTexto($texto);
			$existente->texto = $texto;
			$existente->updated_at = date('Y-m-d H:i:s');
			$row = $existente;
		} else {
			$row = new LmsAulaAnotacao();
			$row->id_admin = $idAdmin;
			$row->id_aluno = $idAluno;
			$row->id_aula = $idAula;
			$row->id_curso = $idCurso;
			$row->texto = $texto;
			$row->cadastrar();
			$row->created_at = date('Y-m-d H:i:s');
			$row->updated_at = $row->created_at;
		}

		$ts = $row->updated_at ?: $row->created_at;
		return [
			'ok' => true,
			'text' => (string)$row->texto,
			'updatedAt' => $ts ? date('c', strtotime((string)$ts)) : date('c'),
		];
	}
}
