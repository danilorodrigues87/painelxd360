<?php

namespace App\Common\Helpers;

use App\Model\Entity\LmsNotificacao;

/**
 * Notificações in-app do portal do aluno.
 */
class LmsNotificacaoHelper {

	public static function tabelasExistem(): bool {
		return LmsNotificacao::tabelasExistem();
	}

	/**
	 * Cria notificação. Se $refChave for informado e já existir, não duplica.
	 */
	public static function criar(
		int $idAdmin,
		int $idAluno,
		string $tipo,
		string $titulo,
		string $mensagem = '',
		?string $link = null,
		?string $refChave = null
	): ?LmsNotificacao {
		if (!self::tabelasExistem() || $idAdmin <= 0 || $idAluno <= 0) {
			return null;
		}
		$tipos = ['lesson', 'course', 'certificate', 'ai', 'system'];
		if (!in_array($tipo, $tipos, true)) {
			$tipo = 'system';
		}
		$titulo = trim($titulo);
		if ($titulo === '') {
			return null;
		}
		if ($refChave !== null && $refChave !== '') {
			$refChave = substr($refChave, 0, 120);
			if (LmsNotificacao::existeRef($idAluno, $refChave)) {
				return null;
			}
		} else {
			$refChave = null;
		}

		$n = new LmsNotificacao();
		$n->id_admin = $idAdmin;
		$n->id_aluno = $idAluno;
		$n->tipo = $tipo;
		$n->titulo = mb_substr($titulo, 0, 180);
		$n->mensagem = mb_substr(trim($mensagem), 0, 500);
		$n->link = $link !== null && $link !== '' ? mb_substr($link, 0, 255) : null;
		$n->lida = 0;
		$n->ref_chave = $refChave;
		try {
			$n->cadastrar();
		} catch (\Throwable $e) {
			return null;
		}
		return $n;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listForApi(int $idAdmin, int $idAluno, int $limit = 50, bool $apenasNaoLidas = false): array {
		if (!self::tabelasExistem()) {
			return [];
		}
		$out = [];
		foreach (LmsNotificacao::listByAluno($idAluno, $idAdmin, $limit, $apenasNaoLidas) as $n) {
			$out[] = [
				'id' => (string)$n->id,
				'title' => (string)$n->titulo,
				'message' => (string)$n->mensagem,
				'type' => (string)$n->tipo,
				'link' => $n->link ? (string)$n->link : null,
				'createdAt' => $n->created_at ? date('c', strtotime((string)$n->created_at)) : date('c'),
				'read' => !empty($n->lida),
			];
		}
		return $out;
	}

	public static function marcarTodasLidas(int $idAdmin, int $idAluno): void {
		LmsNotificacao::marcarTodasLidas($idAluno, $idAdmin);
	}

	public static function marcarLida(int $idAdmin, int $idAluno, int $id): bool {
		$n = LmsNotificacao::getById($id);
		if (!$n || (int)$n->id_aluno !== $idAluno || (int)$n->id_admin !== $idAdmin) {
			return false;
		}
		return $n->marcarLida();
	}

	/** Após recalcular conquistas: notifica as que acabaram de desbloquear. */
	public static function notificarConquistasNovas(int $idAdmin, int $idAluno, array $antesUnlocked, array $depoisList): void {
		foreach ($depoisList as $item) {
			$id = (string)($item['id'] ?? '');
			if ($id === '' || empty($item['unlockedAt'])) {
				continue;
			}
			if (isset($antesUnlocked[$id])) {
				continue;
			}
			$titulo = trim((string)($item['subtitle'] ?? '')) ?: (string)($item['title'] ?? 'Nova conquista');
			self::criar(
				$idAdmin,
				$idAluno,
				'system',
				'Nova conquista: '.$titulo,
				(string)($item['title'] ?? ''),
				'/achievements',
				'ach:'.$id
			);
		}
	}
}
