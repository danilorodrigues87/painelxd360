<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsAulaComentario;
use App\Model\Entity\User;
use App\Model\Db\Database;
use PDO;

class LmsComentarioHelper {

	public static function tabelasExistem(): bool {
		return LmsAulaComentario::tabelasExistem();
	}

	/** @return array<int,array<string,mixed>> */
	public static function listForApi(int $idAdmin, int $idAula): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$rows = LmsAulaComentario::listByAula($idAula, $idAdmin);
		$autorIds = [];
		foreach ($rows as $r) {
			$autorIds[(int)$r->id_autor] = true;
		}
		$nomes = self::nomesAutores(array_keys($autorIds));
		$out = [];
		foreach ($rows as $r) {
			$aid = (int)$r->id_autor;
			$out[] = [
				'id' => (string)$r->id,
				'parentId' => $r->id_pai ? (string)$r->id_pai : null,
				'authorId' => (string)$aid,
				'authorName' => $nomes[$aid] ?? 'Aluno',
				'authorType' => (($r->autor_tipo ?? '') === 'equipe') ? 'staff' : 'student',
				'text' => (string)$r->texto,
				'createdAt' => $r->created_at ? date('c', strtotime((string)$r->created_at)) : date('c'),
			];
		}
		return $out;
	}

	/**
	 * @return array{ok:bool,comment?:array,message?:string}
	 */
	public static function criar(
		int $idAdmin,
		int $idAluno,
		int $idAula,
		?int $idCurso,
		string $texto,
		?int $idPai = null,
		string $autorTipo = 'aluno'
	): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_aula_comentarios.sql'];
		}
		$texto = trim($texto);
		if ($texto === '') {
			return ['ok' => false, 'message' => 'Escreva um comentário.'];
		}
		if (mb_strlen($texto) > 2000) {
			return ['ok' => false, 'message' => 'Comentário muito longo (máx. 2000).'];
		}
		if ($idPai !== null && $idPai > 0) {
			$pai = LmsAulaComentario::getById($idPai);
			if (!$pai || (int)$pai->id_aula !== $idAula || (int)$pai->id_admin !== $idAdmin || !empty($pai->deleted_at)) {
				return ['ok' => false, 'message' => 'Comentário pai inválido.'];
			}
			if ($pai->id_pai) {
				return ['ok' => false, 'message' => 'Responda apenas ao comentário principal.'];
			}
		} else {
			$idPai = null;
		}

		$c = new LmsAulaComentario();
		$c->id_admin = $idAdmin;
		$c->id_aula = $idAula;
		$c->id_curso = $idCurso;
		$c->id_autor = $idAluno;
		$c->autor_tipo = $autorTipo === 'equipe' ? 'equipe' : 'aluno';
		$c->id_pai = $idPai;
		$c->texto = $texto;
		$c->cadastrar();
		$c->created_at = date('Y-m-d H:i:s');

		$nome = 'Aluno';
		$u = User::getUserById($idAluno);
		if ($u instanceof User) {
			$nome = (string)$u->nome;
		}

		return [
			'ok' => true,
			'comment' => [
				'id' => (string)$c->id,
				'parentId' => $idPai ? (string)$idPai : null,
				'authorId' => (string)$idAluno,
				'authorName' => $nome,
				'authorType' => $c->autor_tipo === 'equipe' ? 'staff' : 'student',
				'text' => $texto,
				'createdAt' => date('c'),
			],
		];
	}

	/**
	 * Soft-delete: autor ou equipe da mesma escola.
	 * @return array{ok:bool,message?:string}
	 */
	public static function excluir(int $idAdmin, int $idUser, int $idComentario, bool $isStaff = false): array {
		$c = LmsAulaComentario::getById($idComentario);
		if (!$c || (int)$c->id_admin !== $idAdmin || !empty($c->deleted_at)) {
			return ['ok' => false, 'message' => 'Comentário não encontrado.'];
		}
		if (!$isStaff && (int)$c->id_autor !== $idUser) {
			return ['ok' => false, 'message' => 'Sem permissão.'];
		}
		$c->softDelete();
		return ['ok' => true];
	}

	/** @param int[] $ids @return array<int,string> */
	private static function nomesAutores(array $ids): array {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (!$ids) {
			return [];
		}
		$out = [];
		try {
			$in = implode(',', $ids);
			$stmt = (new Database('usuarios'))->select('id IN ('.$in.')', null, null, 'id, nome');
			while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
				$out[(int)$r['id']] = (string)$r['nome'];
			}
		} catch (\Throwable $e) {
			// ignore
		}
		return $out;
	}
}
