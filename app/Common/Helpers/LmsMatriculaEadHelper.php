<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsMatriculaEad;
use App\Model\Entity\LmsVitrineAssinatura;
use App\Model\Entity\LmsEscolaCursoCti;
use App\Common\CtiCatalog;
use App\Model\Entity\User as EntityUser;
use App\Session\User\Login as SessionUser;

class LmsMatriculaEadHelper {

	public static function tabelasExistem(): bool {
		return LmsMatriculaEad::tabelaExiste();
	}

	/**
	 * Escola do aluno pode usar o curso se:
	 * - curso é da própria escola, ou
	 * - há assinatura vitrine ativa da escola no curso.
	 */
	public static function escolaPodeUsarCurso(LmsCurso $curso, int $idAdminEscola): bool {
		if ((int)$curso->id_admin === $idAdminEscola) {
			return true;
		}
		if (CtiCatalog::isCursoCti($curso) && LmsEscolaCursoCti::ativaParaEscolaCurso($idAdminEscola, (int)$curso->id)) {
			return true;
		}
		if (!class_exists(LmsVitrineAssinatura::class) || !LmsVitrineAssinatura::tabelaExiste()) {
			return false;
		}
		return LmsVitrineAssinatura::ativaParaEscolaCurso($idAdminEscola, (int)$curso->id) !== null;
	}

	public static function matricular(int $idAdmin, int $idAluno, int $idCurso, ?int $createdBy = null): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_ead_independente.sql'];
		}
		$aluno = EntityUser::getUserById($idAluno);
		if (!$aluno || (int)$aluno->id_admin !== $idAdmin || ($aluno->nivel ?? '') !== 'Cliente') {
			return ['ok' => false, 'message' => 'Aluno inválido.'];
		}
		$curso = LmsCurso::getById($idCurso);
		if (!$curso instanceof LmsCurso || (int)$curso->publicado !== 1) {
			return ['ok' => false, 'message' => 'Curso não encontrado ou não publicado.'];
		}
		if (!self::escolaPodeUsarCurso($curso, $idAdmin)) {
			return ['ok' => false, 'message' => 'Sua escola não tem licença deste curso.'];
		}

		$exist = LmsMatriculaEad::get(
			'id_aluno = '.(int)$idAluno.' AND id_curso = '.(int)$idCurso
		)->fetchObject(LmsMatriculaEad::class);

		if ($exist instanceof LmsMatriculaEad) {
			$exist->ativo = 1;
			$exist->inicio = $exist->inicio ?: date('Y-m-d');
			$exist->fim = null;
			$exist->salvar();
			return ['ok' => true, 'message' => 'Matrícula EAD reativada.', 'id' => (int)$exist->id];
		}

		$m = new LmsMatriculaEad();
		$m->id_admin = $idAdmin;
		$m->id_aluno = $idAluno;
		$m->id_curso = $idCurso;
		$m->ativo = 1;
		$m->inicio = date('Y-m-d');
		$m->created_by = $createdBy;
		$m->salvar();
		return ['ok' => true, 'message' => 'Aluno matriculado no curso EAD.', 'id' => (int)$m->id];
	}

	public static function desmatricular(int $idAdmin, int $idAluno, int $idCurso): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_ead_independente.sql'];
		}
		$exist = LmsMatriculaEad::get(
			'id_aluno = '.(int)$idAluno
			.' AND id_curso = '.(int)$idCurso
			.' AND id_admin = '.(int)$idAdmin
		)->fetchObject(LmsMatriculaEad::class);
		if (!$exist instanceof LmsMatriculaEad) {
			return ['ok' => false, 'message' => 'Matrícula EAD não encontrada.'];
		}
		$exist->ativo = 0;
		$exist->fim = date('Y-m-d');
		$exist->salvar();
		return ['ok' => true, 'message' => 'Acesso EAD removido.'];
	}

	public static function createdByAtual(): ?int {
		$u = SessionUser::getUserLogedData();
		$id = (int)($u['usuario']['id'] ?? 0);
		return $id > 0 ? $id : null;
	}
}
