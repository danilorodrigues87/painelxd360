<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use App\Session\User\Login as SessionUser;
use PDO;

class TenantHelper {

	private static ?int $overrideIdAdmin = null;

	/** Executa callback usando tenant fixo (ex.: catálogo CTI no Master, sem impersonate). */
	public static function withTenant(int $idAdmin, callable $fn) {
		self::$overrideIdAdmin = $idAdmin > 0 ? $idAdmin : null;
		try {
			return $fn();
		} finally {
			self::$overrideIdAdmin = null;
		}
	}

	public static function getIdAdmin(): int {
		if (self::$overrideIdAdmin !== null && self::$overrideIdAdmin > 0) {
			return self::$overrideIdAdmin;
		}
		$data = SessionUser::getUserLogedData();
		return (int)($data['usuario']['id_admin'] ?? 0);
	}

	public static function getUsuarioId(): int {
		$data = SessionUser::getUserLogedData();
		return (int)($data['usuario']['id'] ?? 0);
	}

	public static function whereComFiltroId(int $filtroId, int $idAdmin, string $wherePadrao): string {
		if ($filtroId > 0) {
			return 'id = '.$filtroId.' AND id_admin = '.$idAdmin;
		}
		return $wherePadrao;
	}

	public static function whereMatriculaFiltro(int $idAluno, int $idAdmin, string $wherePadrao): string {
		if ($idAluno > 0) {
			return 'id_aluno = '.$idAluno.' AND id_admin = '.$idAdmin;
		}
		return $wherePadrao;
	}

	/** Escapa termo para LIKE (sem %/_); retorna '' se vazio. */
	public static function termoLike(string $busca): string {
		$busca = trim($busca);
		if ($busca === '') {
			return '';
		}
		$busca = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $busca);
		return addslashes($busca);
	}

	/**
	 * IDs de alunos (nivel Cliente) do tenant cujo nome/email/whatsapp batem com a busca.
	 * @return list<int>|null null = sem filtro de busca; [] = nenhum match
	 */
	public static function idsAlunosPorBusca(int $idAdmin, string $busca): ?array {
		$termo = self::termoLike($busca);
		if ($termo === '') {
			return null;
		}
		$like = '\'%'.$termo.'%\'';
		$stmt = (new Database('usuarios'))->select(
			'id_admin = '.(int)$idAdmin.' AND nivel = "Cliente" AND ('
			.'nome LIKE '.$like.' OR email LIKE '.$like.' OR whatsapp LIKE '.$like
			.')',
			null,
			null,
			'id'
		);
		$ids = [];
		while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
			$ids[] = (int)$row['id'];
		}
		return $ids;
	}

	public static function pertence(string $tabela, int $id, int $idAdmin, string $coluna = 'id_admin'): bool {
		if ($id <= 0 || $idAdmin <= 0) {
			return false;
		}

		$row = (new Database($tabela))->select(
			'id = '.(int)$id.' AND '.$coluna.' = '.(int)$idAdmin,
			null,
			1,
			'id'
		)->fetch(PDO::FETCH_ASSOC);

		return !empty($row);
	}

	public static function pertenceUsuario(int $id, int $idAdmin, ?string $nivel = null): bool {
		if ($id <= 0 || $idAdmin <= 0) {
			return false;
		}

		$where = 'id = '.(int)$id.' AND id_admin = '.(int)$idAdmin;

		if ($nivel !== null) {
			$where .= ' AND nivel = "'.addslashes($nivel).'"';
		}

		$row = (new Database('usuarios'))->select($where, null, 1, 'id')->fetch(PDO::FETCH_ASSOC);

		return !empty($row);
	}

	public static function pertenceMatricula(int $id, int $idAdmin): bool {
		return self::pertence('matriculas', $id, $idAdmin);
	}

	public static function pertenceCaixa(int $id, int $idAdmin): bool {
		return self::pertence('caixa', $id, $idAdmin);
	}

	public static function pertenceEscola(int $id, int $idAdmin): bool {
		return (int)$id === (int)$idAdmin;
	}

	public static function pertenceListaTarefa(int $id, int $idAdmin): bool {
		return self::pertence('crm_tarefas_listas', $id, $idAdmin);
	}

}
