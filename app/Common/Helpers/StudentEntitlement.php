<?php

namespace App\Common\Helpers;

use App\Model\Entity\Matriculas;
use App\Model\Entity\LmsCurso;
use App\Model\Entity\LmsModulo;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsAtividade;
use App\Model\Entity\LmsMatriculaEad;
use App\Model\Entity\LmsRoleplayCenario;
use App\Model\Entity\Trilhas;

class StudentEntitlement {

	/** Matrículas comerciais ativas (carnê/contrato) — não liberam portal EAD. */
	public static function matriculasAtivas(int $idAluno, int $idAdmin): array {
		$stmt = Matriculas::getMatriculas(
			'id_aluno = '.(int)$idAluno
			.' AND id_admin = '.(int)$idAdmin
			.' AND '.MatriculaStatusHelper::sqlAtiva('')
		);
		$rows = [];
		while ($r = $stmt->fetchObject(Matriculas::class)) {
			$rows[] = $r;
		}
		return $rows;
	}

	public static function idsTrilhasMatriculadas(int $idAluno, int $idAdmin): array {
		$ids = [];
		foreach (self::matriculasAtivas($idAluno, $idAdmin) as $m) {
			$ids[] = (int)$m->id_trilha;
		}
		return array_values(array_unique($ids));
	}

	/** IDs de cursos com matrícula EAD ativa. */
	public static function idsCursosEad(int $idAluno, int $idAdmin): array {
		$ids = [];
		foreach (LmsMatriculaEad::listAtivasAluno($idAluno, $idAdmin) as $m) {
			$ids[] = (int)$m->id_curso;
		}
		return array_values(array_unique($ids));
	}

	public static function podeAcessarCurso(LmsCurso $curso, int $idAluno, int $idAdmin): bool {
		if ((int)$curso->publicado !== 1) {
			return false;
		}
		if (!LmsMatriculaEadHelper::escolaPodeUsarCurso($curso, $idAdmin)) {
			return false;
		}
		$mat = LmsMatriculaEad::getAtiva($idAluno, (int)$curso->id);
		return $mat !== null && (int)$mat->id_admin === $idAdmin;
	}

	public static function cursoDoAluno($idCursoOrTrilhaOrSlug, int $idAluno, int $idAdmin, bool $bySlug = false): ?LmsCurso {
		$curso = null;
		if ($bySlug || !ctype_digit((string)$idCursoOrTrilhaOrSlug)) {
			$curso = LmsCurso::getBySlug((string)$idCursoOrTrilhaOrSlug, $idAdmin);
			if (!$curso) {
				// slug de curso licenciado (outro tenant)
				foreach (self::idsCursosEad($idAluno, $idAdmin) as $idC) {
					$c = LmsCurso::getById((int)$idC);
					if ($c && (string)$c->slug === (string)$idCursoOrTrilhaOrSlug) {
						$curso = $c;
						break;
					}
				}
			}
		} else {
			$id = (int)$idCursoOrTrilhaOrSlug;
			$curso = LmsCurso::getByIdAdmin($id, $idAdmin);
			if (!$curso) {
				$curso = LmsCurso::getById($id);
			}
			if (!$curso) {
				$curso = LmsCurso::getByTrilha($id, $idAdmin);
			}
		}
		if (!$curso instanceof LmsCurso) {
			return null;
		}
		return self::podeAcessarCurso($curso, $idAluno, $idAdmin) ? $curso : null;
	}

	public static function aulaPertenceCurso(LmsAula $aula, LmsCurso $curso, int $idAdminConteudo = 0): bool {
		$idOwner = $idAdminConteudo > 0 ? $idAdminConteudo : (int)$curso->id_admin;
		$mod = LmsModulo::getByIdAdmin((int)$aula->id_modulo, $idOwner);
		if (!$mod) {
			$mod = LmsModulo::getById((int)$aula->id_modulo);
		}
		return $mod && (int)$mod->id_curso === (int)$curso->id;
	}

	public static function nomeTrilha(int $idTrilha): string {
		$t = Trilhas::getTrilhaById($idTrilha);
		return $t ? (string)$t->nome : 'Curso';
	}

	/** id_admin dono do conteúdo (criador do curso). */
	public static function idAdminConteudo(LmsCurso $curso): int {
		return (int)$curso->id_admin;
	}

	/**
	 * Resolve atividade acessível (escola própria ou curso CTI cross-tenant).
	 * @return array{atividade: LmsAtividade, curso: LmsCurso, idOwner: int}|null
	 */
	public static function resolveAtividadeAluno(int $idAtividade, int $idAluno, int $idAdminEscola): ?array {
		if ($idAtividade <= 0) {
			return null;
		}
		$at = LmsAtividade::getByIdAdmin($idAtividade, $idAdminEscola);
		if ($at instanceof LmsAtividade) {
			$curso = LmsCurso::getByIdAdmin((int)$at->id_curso, $idAdminEscola);
			if ($curso && self::podeAcessarCurso($curso, $idAluno, $idAdminEscola)) {
				return ['atividade' => $at, 'curso' => $curso, 'idOwner' => self::idAdminConteudo($curso)];
			}
		}
		foreach (self::idsCursosEad($idAluno, $idAdminEscola) as $idCurso) {
			$curso = LmsCurso::getById((int)$idCurso);
			if (!$curso || !self::podeAcessarCurso($curso, $idAluno, $idAdminEscola)) {
				continue;
			}
			$idOwner = self::idAdminConteudo($curso);
			$at = LmsAtividade::getByIdAdmin($idAtividade, $idOwner);
			if ($at instanceof LmsAtividade && (int)$at->id_curso === (int)$curso->id) {
				return ['atividade' => $at, 'curso' => $curso, 'idOwner' => $idOwner];
			}
		}
		return null;
	}

	/**
	 * @return array{cenario: LmsRoleplayCenario, curso: LmsCurso, idOwner: int}|null
	 */
	public static function resolveRoleplayAluno(int $idCenario, int $idAluno, int $idAdminEscola): ?array {
		if ($idCenario <= 0) {
			return null;
		}
		$rp = LmsRoleplayCenario::getByIdAdmin($idCenario, $idAdminEscola);
		if ($rp instanceof LmsRoleplayCenario) {
			$curso = LmsCurso::getByIdAdmin((int)$rp->id_curso, $idAdminEscola);
			if ($curso && self::podeAcessarCurso($curso, $idAluno, $idAdminEscola)) {
				return ['cenario' => $rp, 'curso' => $curso, 'idOwner' => self::idAdminConteudo($curso)];
			}
		}
		foreach (self::idsCursosEad($idAluno, $idAdminEscola) as $idCurso) {
			$curso = LmsCurso::getById((int)$idCurso);
			if (!$curso || !self::podeAcessarCurso($curso, $idAluno, $idAdminEscola)) {
				continue;
			}
			$idOwner = self::idAdminConteudo($curso);
			$rp = LmsRoleplayCenario::getByIdAdmin($idCenario, $idOwner);
			if ($rp instanceof LmsRoleplayCenario && (int)$rp->id_curso === (int)$curso->id) {
				return ['cenario' => $rp, 'curso' => $curso, 'idOwner' => $idOwner];
			}
		}
		return null;
	}

	/** Título exibido no portal (trilha comercial ou nome do curso EAD). */
	public static function tituloCursoAluno(LmsCurso $curso, int $idAdminEscola): string {
		$idTrilha = (int)($curso->id_trilha ?? 0);
		if ($idTrilha > 0) {
			$t = Trilhas::getTrilhaById($idTrilha);
			if ($t) {
				return (string)$t->nome;
			}
		}
		return $curso->nomeExibicao();
	}
}
