<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsAtividade;
use App\Model\Entity\LmsAtividadeTentativa;
use App\Model\Entity\LmsAula;
use App\Model\Entity\LmsProgressoAula;
use App\Model\Entity\LmsRoleplayCenario;
use App\Model\Entity\LmsRoleplaySessao;

/**
 * Avaliação por unidade (aula): média das atividades + roleplay ≥ 70%.
 * 3 tentativas por ciclo; reprovação → reassistir aula → novo ciclo (+3).
 */
class LmsUnidadeAvaliacaoHelper {

	public const NOTA_MINIMA = 70;
	public const TENTATIVAS_POR_CICLO = 3;

	public static function getOrCreateProgresso(int $idAluno, int $idAula, int $idAdmin): LmsProgressoAula {
		$prog = LmsProgressoAula::getAlunoAula($idAluno, $idAula);
		if ($prog instanceof LmsProgressoAula) {
			if (!isset($prog->ciclo) || (int)$prog->ciclo < 1) {
				$prog->ciclo = 1;
			}
			return $prog;
		}
		$prog = new LmsProgressoAula();
		$prog->id_aluno = $idAluno;
		$prog->id_aula = $idAula;
		$prog->id_admin = $idAdmin;
		$prog->ciclo = 1;
		$prog->precisa_revisar = 0;
		$prog->unidade_aprovada = 0;
		$prog->nota_unidade = null;
		return $prog;
	}

	public static function cicloAtual(LmsProgressoAula $prog): int {
		return max(1, (int)($prog->ciclo ?? 1));
	}

	/** Itens avaliados da aula: atividades + roleplays. */
	public static function itensAvaliados(int $idAula, int $idAdmin): array {
		$itens = [];
		foreach (LmsAtividade::listByAula($idAula, $idAdmin) as $at) {
			$itens[] = ['kind' => 'assessment', 'id' => (int)$at->id, 'titulo' => (string)$at->titulo, 'max' => max(1, (int)($at->tentativas_max ?: self::TENTATIVAS_POR_CICLO))];
		}
		foreach (LmsRoleplayCenario::listByAula($idAula, $idAdmin) as $rp) {
			$itens[] = ['kind' => 'roleplay', 'id' => (int)$rp->id, 'titulo' => (string)$rp->titulo, 'max' => 1];
		}
		return $itens;
	}

	/** Melhor nota do ciclo para uma atividade (null se ainda não finalizou nenhuma). */
	public static function melhorNotaAtividade(int $idAluno, int $idAtividade, int $ciclo): ?float {
		$best = null;
		foreach (LmsAtividadeTentativa::listByAlunoAtividadeCiclo($idAluno, $idAtividade, $ciclo) as $t) {
			if ((string)($t->status ?? '') === 'in_progress' || $t->nota === null) {
				continue;
			}
			$n = (float)$t->nota;
			if ($best === null || $n > $best) {
				$best = $n;
			}
		}
		return $best;
	}

	public static function melhorNotaRoleplay(int $idAluno, int $idCenario, int $idAdmin, int $ciclo): ?float {
		$best = null;
		foreach (LmsRoleplaySessao::listByAlunoCenarioCiclo($idAluno, $idCenario, $idAdmin, $ciclo) as $s) {
			if ($s->score === null || empty($s->ended_at)) {
				continue;
			}
			$n = (float)$s->score;
			if ($best === null || $n > $best) {
				$best = $n;
			}
		}
		return $best;
	}

	/**
	 * @return array{scores: float[], average: ?float, allDone: bool, passed: bool, details: array}
	 */
	public static function avaliarUnidade(int $idAluno, int $idAula, int $idAdminEscola, ?int $idAdminConteudo = null): array {
		$idContent = $idAdminConteudo ?? $idAdminEscola;
		$prog = self::getOrCreateProgresso($idAluno, $idAula, $idAdminEscola);
		$ciclo = self::cicloAtual($prog);
		$itens = self::itensAvaliados($idAula, $idContent);
		$scores = [];
		$details = [];
		$allDone = true;
		if (count($itens) === 0) {
			return ['scores' => [], 'average' => 100.0, 'allDone' => true, 'passed' => true, 'details' => [], 'ciclo' => $ciclo];
		}
		foreach ($itens as $it) {
			$nota = null;
			if ($it['kind'] === 'assessment') {
				$nota = self::melhorNotaAtividade($idAluno, $it['id'], $ciclo);
			} else {
				$nota = self::melhorNotaRoleplay($idAluno, $it['id'], $idAdminEscola, $ciclo);
			}
			$details[] = [
				'kind' => $it['kind'],
				'id' => (string)$it['id'],
				'title' => $it['titulo'],
				'score' => $nota,
			];
			if ($nota === null) {
				$allDone = false;
			} else {
				$scores[] = $nota;
			}
		}
		$average = count($scores) > 0 ? round(array_sum($scores) / count($scores), 2) : null;
		$passed = $allDone && $average !== null && $average >= self::NOTA_MINIMA;
		return [
			'scores' => $scores,
			'average' => $average,
			'allDone' => $allDone,
			'passed' => $passed,
			'details' => $details,
			'ciclo' => $ciclo,
		];
	}

	/** Persiste resultado da unidade; se reprovado com tudo feito, exige revisar aula. */
	public static function sincronizarUnidade(int $idAluno, int $idAula, int $idAdminEscola, ?int $idAdminConteudo = null): array {
		$eval = self::avaliarUnidade($idAluno, $idAula, $idAdminEscola, $idAdminConteudo);
		$prog = self::getOrCreateProgresso($idAluno, $idAula, $idAdminEscola);
		if ($eval['allDone'] && $eval['average'] !== null) {
			$prog->nota_unidade = $eval['average'];
			if ($eval['passed']) {
				$prog->unidade_aprovada = 1;
				$prog->precisa_revisar = 0;
				if (empty($prog->concluida_em)) {
					$prog->concluida_em = date('Y-m-d H:i:s');
				}
			} else {
				$prog->unidade_aprovada = 0;
				$prog->precisa_revisar = 1;
				$prog->concluida_em = null; // precisa reassistir
			}
			$prog->salvar();
		} elseif ($eval['passed']) {
			$prog->unidade_aprovada = 1;
			$prog->precisa_revisar = 0;
			$prog->nota_unidade = $eval['average'];
			$prog->salvar();
		}
		$eval['precisaRevisar'] = (int)($prog->precisa_revisar ?? 0) === 1;
		$eval['unidadeAprovada'] = (int)($prog->unidade_aprovada ?? 0) === 1;
		$eval['notaUnidade'] = $prog->nota_unidade !== null ? (float)$prog->nota_unidade : $eval['average'];
		return $eval;
	}

	/** Ao marcar aula concluída: se precisava revisar, abre novo ciclo (+3 tentativas). */
	public static function onCompleteLesson(LmsProgressoAula $prog): void {
		if ((int)($prog->precisa_revisar ?? 0) === 1 || (int)($prog->unidade_aprovada ?? 0) !== 1) {
			if ((int)($prog->precisa_revisar ?? 0) === 1) {
				$prog->ciclo = self::cicloAtual($prog) + 1;
			}
			$prog->precisa_revisar = 0;
			$prog->nota_unidade = null;
			// unidade_aprovada permanece 0 até nova média ≥ 70
		}
		if ((int)($prog->ciclo ?? 0) < 1) {
			$prog->ciclo = 1;
		}
		$prog->concluida_em = date('Y-m-d H:i:s');
		$prog->ultimo_acesso = date('Y-m-d H:i:s');
	}

	public static function tentativasUsadasNoCiclo(int $idAluno, int $idAtividade, int $ciclo): int {
		return LmsAtividadeTentativa::countCompletedCiclo($idAluno, $idAtividade, $ciclo);
	}

	public static function aulaAssistidaNoCiclo(LmsProgressoAula $prog): bool {
		return !empty($prog->concluida_em) && (int)($prog->precisa_revisar ?? 0) === 0;
	}

	/** Unidade anterior aprovada? (para liberar próxima aula) */
	public static function unidadeAprovada(int $idAluno, int $idAula, int $idAdminConteudo, ?int $idAdminEscola = null): bool {
		$prog = null;
		if ($idAdminEscola !== null && $idAdminEscola > 0) {
			foreach (LmsProgressoAula::listByAluno($idAluno, $idAdminEscola) as $p) {
				if ((int)$p->id_aula === $idAula) {
					$prog = $p;
					break;
				}
			}
		}
		if (!$prog instanceof LmsProgressoAula) {
			$prog = LmsProgressoAula::getAlunoAula($idAluno, $idAula);
		}
		if ($prog instanceof LmsProgressoAula && (int)($prog->unidade_aprovada ?? 0) === 1) {
			return true;
		}
		// Sem itens avaliados: basta ter assistido
		$itens = self::itensAvaliados($idAula, $idAdminConteudo);
		if (count($itens) === 0) {
			return $prog instanceof LmsProgressoAula && !empty($prog->concluida_em);
		}
		return false;
	}
}
