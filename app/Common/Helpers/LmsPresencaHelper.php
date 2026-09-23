<?php

namespace App\Common\Helpers;

use App\Model\Db\Database;
use PDO;

/**
 * Presença no portal Ascend (logado) + cruzamento com sessão de aula.
 */
class LmsPresencaHelper {

	public const TTL_SEC = 90;

	public static function tabelasExistem(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$st = (new Database())->execute("SHOW TABLES LIKE 'lms_portal_presenca'");
			$ok = $st && $st->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function ping(
		int $idAdmin,
		int $idAluno,
		string $rota = '',
		?int $idCurso = null,
		?int $idAula = null
	): array {
		if (!self::tabelasExistem()) {
			return ['ok' => false, 'message' => 'Execute database/lms_portal_presenca.sql'];
		}
		if ($idAdmin <= 0 || $idAluno <= 0) {
			return ['ok' => false, 'message' => 'Dados inválidos.'];
		}

		$rota = mb_substr(trim($rota), 0, 120);
		$now = date('Y-m-d H:i:s');
		$db = new Database('lms_portal_presenca');
		$existe = $db->select(
			'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
			null,
			'1'
		)->fetch(PDO::FETCH_ASSOC);

		$dados = [
			'last_seen_at' => $now,
			'rota' => $rota !== '' ? $rota : null,
			'id_curso' => ($idCurso !== null && $idCurso > 0) ? $idCurso : null,
			'id_aula' => ($idAula !== null && $idAula > 0) ? $idAula : null,
		];

		if ($existe) {
			$db->update(
				'id_aluno = '.(int)$idAluno.' AND id_admin = '.(int)$idAdmin,
				$dados
			);
		} else {
			$db->insert(array_merge([
				'id_aluno' => $idAluno,
				'id_admin' => $idAdmin,
			], $dados));
		}

		return ['ok' => true];
	}

	/**
	 * Alunos online na escola (presença recente).
	 * @return list<array{id:int,nome:string,fotoUrl:?string,status:string,statusLabel:string,detalhe:string,rota:?string,curso:?string,aula:?string,lastSeenAt:string,segundosAtras:int}>
	 */
	public static function listarOnline(int $idAdmin, int $ttlSec = self::TTL_SEC): array {
		if ($idAdmin <= 0 || !self::tabelasExistem()) {
			return [];
		}
		$ttlSec = max(30, min(300, $ttlSec));

		try {
			$sql = 'SELECT p.id_aluno, p.last_seen_at, p.rota, p.id_curso, p.id_aula,
					u.nome, u.foto,
					TIMESTAMPDIFF(SECOND, p.last_seen_at, NOW()) AS secs_ago
				FROM lms_portal_presenca p
				INNER JOIN usuarios u ON u.id = p.id_aluno AND u.id_admin = p.id_admin
				WHERE p.id_admin = '.(int)$idAdmin.'
				AND p.last_seen_at >= DATE_SUB(NOW(), INTERVAL '.(int)$ttlSec.' SECOND)
				AND u.nivel = "Cliente"
				ORDER BY p.last_seen_at DESC';
			$rows = (new Database())->execute($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
		} catch (\Throwable $e) {
			return [];
		}

		$estudoOk = LmsEstudoHelper::tabelasExistem();
		$out = [];

		foreach ($rows as $r) {
			$idAluno = (int)$r['id_aluno'];
			$secs = (int)($r['secs_ago'] ?? 0);
			$sessao = null;
			if ($estudoOk) {
				$sessao = self::sessaoAulaRecente($idAdmin, $idAluno, $ttlSec);
			}

			$cursoTitulo = null;
			$aulaTitulo = null;
			$status = 'navegando';
			$statusLabel = 'Navegando';
			$detalhe = self::labelRota((string)($r['rota'] ?? ''));

			if ($sessao) {
				$status = 'em_aula';
				$statusLabel = 'Em aula';
				$cursoTitulo = $sessao['curso'] ?: null;
				$aulaTitulo = $sessao['aula'] ?: null;
				$parts = array_filter([$cursoTitulo, $aulaTitulo]);
				$detalhe = $parts ? implode(' · ', $parts) : 'Em aula';
			} elseif (!empty($r['id_aula'])) {
				$meta = self::tituloCursoAula(
					$idAdmin,
					(int)($r['id_curso'] ?? 0),
					(int)$r['id_aula']
				);
				if ($meta['aula']) {
					$status = 'em_aula';
					$statusLabel = 'Em aula';
					$cursoTitulo = $meta['curso'];
					$aulaTitulo = $meta['aula'];
					$parts = array_filter([$cursoTitulo, $aulaTitulo]);
					$detalhe = $parts ? implode(' · ', $parts) : 'Em aula';
				}
			}

			$foto = null;
			$rawFoto = trim((string)($r['foto'] ?? ''));
			if ($rawFoto !== '' && strpos($rawFoto, '..') === false && strpos($rawFoto, '/') === false
				&& class_exists(UserFotoHelper::class)) {
				$foto = UserFotoHelper::urlPublica($rawFoto);
			}

			$out[] = [
				'id' => $idAluno,
				'nome' => (string)($r['nome'] ?? ''),
				'fotoUrl' => $foto,
				'status' => $status,
				'statusLabel' => $statusLabel,
				'detalhe' => $detalhe,
				'rota' => $r['rota'] !== null && $r['rota'] !== '' ? (string)$r['rota'] : null,
				'curso' => $cursoTitulo,
				'aula' => $aulaTitulo,
				'lastSeenAt' => (string)$r['last_seen_at'],
				'segundosAtras' => $secs,
			];
		}

		return $out;
	}

	/** @return array{curso:?string,aula:?string}|null */
	private static function sessaoAulaRecente(int $idAdmin, int $idAluno, int $ttlSec): ?array {
		try {
			$sql = 'SELECT s.id_curso, s.id_aula, a.titulo AS aula_titulo, t.nome AS curso_titulo
				FROM lms_estudo_sessao s
				LEFT JOIN lms_aulas a ON a.id = s.id_aula AND a.id_admin = s.id_admin
				LEFT JOIN lms_cursos c ON c.id = s.id_curso AND c.id_admin = s.id_admin
				LEFT JOIN trilhas t ON t.id = c.id_trilha
				WHERE s.id_admin = '.(int)$idAdmin.'
				AND s.id_aluno = '.(int)$idAluno.'
				AND s.ended_at IS NULL
				AND s.last_ping_at >= DATE_SUB(NOW(), INTERVAL '.(int)$ttlSec.' SECOND)
				ORDER BY s.last_ping_at DESC
				LIMIT 1';
			$row = (new Database())->execute($sql)->fetch(PDO::FETCH_ASSOC);
			if (!$row) {
				return null;
			}
			return [
				'curso' => trim((string)($row['curso_titulo'] ?? '')) ?: null,
				'aula' => trim((string)($row['aula_titulo'] ?? '')) ?: null,
			];
		} catch (\Throwable $e) {
			return null;
		}
	}

	/** @return array{curso:?string,aula:?string} */
	private static function tituloCursoAula(int $idAdmin, int $idCurso, int $idAula): array {
		$curso = null;
		$aula = null;
		try {
			if ($idAula > 0) {
				$row = (new Database())->execute(
					'SELECT titulo FROM lms_aulas WHERE id = '.(int)$idAula.' AND id_admin = '.(int)$idAdmin.' LIMIT 1'
				)->fetch(PDO::FETCH_ASSOC);
				$aula = trim((string)($row['titulo'] ?? '')) ?: null;
			}
			if ($idCurso > 0) {
				$row = (new Database())->execute(
					'SELECT t.nome AS titulo
					FROM lms_cursos c
					INNER JOIN trilhas t ON t.id = c.id_trilha
					WHERE c.id = '.(int)$idCurso.' AND c.id_admin = '.(int)$idAdmin.'
					LIMIT 1'
				)->fetch(PDO::FETCH_ASSOC);
				$curso = trim((string)($row['titulo'] ?? '')) ?: null;
			}
		} catch (\Throwable $e) {
			/* ignore */
		}
		return ['curso' => $curso, 'aula' => $aula];
	}

	public static function labelRota(string $path): string {
		$path = trim($path);
		if ($path === '' || $path === '/') {
			return 'Portal';
		}
		$path = strtok($path, '?') ?: $path;
		$map = [
			'/dashboard' => 'Dashboard',
			'/courses' => 'Meus cursos',
			'/continue' => 'Continuar estudando',
			'/achievements' => 'Conquistas',
			'/ranking' => 'Ranking',
			'/certificates' => 'Certificados',
			'/finance' => 'Financeiro',
			'/profile' => 'Perfil',
			'/settings' => 'Configurações',
			'/notifications' => 'Notificações',
			'/ai' => 'IA pedagógica',
			'/assessments' => 'Avaliações',
			'/roleplay' => 'Roleplay',
		];
		foreach ($map as $prefix => $label) {
			if ($path === $prefix || str_starts_with($path, $prefix.'/')) {
				if (str_starts_with($path, '/courses/') && str_contains($path, '/lessons/')) {
					return 'Em aula';
				}
				if (str_starts_with($path, '/courses/') && $path !== '/courses') {
					return 'Curso';
				}
				return $label;
			}
		}
		return 'Navegando no portal';
	}
}
