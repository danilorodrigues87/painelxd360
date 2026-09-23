<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class MetaConversa {

	public $id;
	public $id_admin;
	public $canal = 'messenger';
	public $page_id;
	public $participant_id;
	public $nome_contato;
	public $foto_url;
	public $ultima_mensagem;
	public $nao_lidas = 0;
	public $status = 'aberta';
	public $lead_id;
	public $ultima_mensagem_em;
	public $created_at;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'meta_conversas'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function getById(int $id, int $idAdmin): ?self {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$row = (new Database('meta_conversas'))
			->select('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin, null, 1)
			->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByParticipant(int $idAdmin, string $canal, string $participantId): ?self {
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return null;
		}
		$canal = self::normalizarCanal($canal);
		$pid = addslashes(trim($participantId));
		if ($pid === '') {
			return null;
		}
		$row = (new Database('meta_conversas'))
			->select(
				'id_admin = '.(int)$idAdmin
				.' AND canal = "'.addslashes($canal).'"'
				.' AND participant_id = "'.$pid.'"',
				null,
				1
			)
			->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/**
	 * @param array{nome_contato?:?string,foto_url?:?string,page_id?:string} $extras
	 */
	public static function findOrCreate(
		int $idAdmin,
		string $canal,
		string $participantId,
		string $pageId = '',
		array $extras = []
	): ?self {
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return null;
		}

		$canal = self::normalizarCanal($canal);
		$participantId = trim($participantId);
		if ($participantId === '') {
			return null;
		}

		$existente = self::getByParticipant($idAdmin, $canal, $participantId);
		if ($existente instanceof self) {
			$upd = [];
			if (!empty($extras['nome_contato']) && trim((string)$extras['nome_contato']) !== '') {
				$upd['nome_contato'] = trim((string)$extras['nome_contato']);
				$existente->nome_contato = $upd['nome_contato'];
			}
			if (!empty($extras['foto_url']) && trim((string)$extras['foto_url']) !== '') {
				$upd['foto_url'] = trim((string)$extras['foto_url']);
				$existente->foto_url = $upd['foto_url'];
			}
			if ($pageId !== '') {
				$atual = trim((string)($existente->page_id ?? ''));
				if ($atual === '' || self::pageIdPrecisaCorrigir($atual, $pageId)) {
					$upd['page_id'] = $pageId;
					$existente->page_id = $pageId;
				}
			}
			if ($upd) {
				(new Database('meta_conversas'))->update('id = '.(int)$existente->id, $upd);
			}
			return $existente;
		}

		$dados = [
			'id_admin'       => $idAdmin,
			'canal'          => $canal,
			'page_id'        => $pageId !== '' ? $pageId : '',
			'participant_id' => $participantId,
			'nome_contato'   => !empty($extras['nome_contato']) ? trim((string)$extras['nome_contato']) : null,
			'foto_url'       => !empty($extras['foto_url']) ? trim((string)$extras['foto_url']) : null,
			'status'         => 'aberta',
			'nao_lidas'      => 0,
		];

		$id = (int)(new Database('meta_conversas'))->insert($dados);
		if ($id <= 0) {
			return null;
		}

		$ob = new self;
		$ob->id = $id;
		foreach ($dados as $k => $v) {
			$ob->$k = $v;
		}
		return $ob;
	}

	public function registrarMensagemRecebida(string $texto, ?string $metaMessageId, string $tipo = 'text', ?string $anexoJson = null): void {
		$preview = trim($texto);
		if ($preview === '' && $anexoJson) {
			$preview = '[anexo]';
		}
		if (mb_strlen($preview) > 500) {
			$preview = mb_substr($preview, 0, 497).'...';
		}

		$naoLidas = (int)($this->nao_lidas ?? 0) + 1;
		$agora = date('Y-m-d H:i:s');

		(new Database('meta_conversas'))->update('id = '.(int)$this->id, [
			'ultima_mensagem'    => $preview !== '' ? $preview : null,
			'ultima_mensagem_em' => $agora,
			'nao_lidas'          => $naoLidas,
			'status'             => 'aberta',
		]);

		$this->ultima_mensagem = $preview;
		$this->ultima_mensagem_em = $agora;
		$this->nao_lidas = $naoLidas;
		$this->status = 'aberta';
	}

	public function registrarMensagemEnviada(string $texto): void {
		$preview = trim($texto);
		if (mb_strlen($preview) > 500) {
			$preview = mb_substr($preview, 0, 497).'...';
		}
		$agora = date('Y-m-d H:i:s');

		(new Database('meta_conversas'))->update('id = '.(int)$this->id, [
			'ultima_mensagem'    => $preview !== '' ? $preview : null,
			'ultima_mensagem_em' => $agora,
		]);

		$this->ultima_mensagem = $preview;
		$this->ultima_mensagem_em = $agora;
	}

	public static function normalizarCanal(string $canal): string {
		$c = strtolower(trim($canal));
		return $c === 'instagram' ? 'instagram' : 'messenger';
	}

	/** IG Business IDs começam com 178414; Page ID da Meta é o numérico da Facebook Page. */
	public static function pageIdPrecisaCorrigir(string $atual, string $correto): bool {
		$atual = trim($atual);
		$correto = trim($correto);
		if ($correto === '' || $atual === $correto) {
			return false;
		}
		if ($atual === '') {
			return true;
		}
		if (str_starts_with($atual, '178414') && !str_starts_with($correto, '178414')) {
			return true;
		}
		return false;
	}

	public static function labelCanal(string $canal): string {
		return self::normalizarCanal($canal) === 'instagram'
			? 'Instagram Direct'
			: 'Facebook Messenger';
	}

	public function marcarLida(): void {
		if ((int)($this->nao_lidas ?? 0) === 0) {
			return;
		}
		(new Database('meta_conversas'))->update('id = '.(int)$this->id, ['nao_lidas' => 0]);
		$this->nao_lidas = 0;
	}

	public function arquivar(): void {
		(new Database('meta_conversas'))->update('id = '.(int)$this->id, [
			'status' => 'arquivada',
			'nao_lidas' => 0,
		]);
		$this->status = 'arquivada';
		$this->nao_lidas = 0;
	}

	public function reabrir(): void {
		(new Database('meta_conversas'))->update('id = '.(int)$this->id, ['status' => 'aberta']);
		$this->status = 'aberta';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public static function listarInbox(
		int $idAdmin,
		int $limite = 80,
		string $filtro = 'todas',
		string $busca = ''
	): array {
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return [];
		}

		$limite = max(1, min(200, $limite));
		$filtrosOk = ['todas', 'nao_lidas', 'messenger', 'instagram', 'arquivadas'];
		$filtro = in_array($filtro, $filtrosOk, true) ? $filtro : 'todas';

		$where = 'id_admin = '.(int)$idAdmin;

		if ($filtro === 'nao_lidas') {
			$where .= ' AND nao_lidas > 0 AND status = "aberta"';
		} elseif ($filtro === 'messenger') {
			$where .= ' AND canal = "messenger" AND status = "aberta"';
		} elseif ($filtro === 'instagram') {
			$where .= ' AND canal = "instagram" AND status = "aberta"';
		} elseif ($filtro === 'arquivadas') {
			$where .= ' AND status = "arquivada"';
		} else {
			$where .= ' AND status = "aberta"';
		}

		$busca = trim($busca);
		if ($busca !== '') {
			$like = addslashes(str_replace(['%', '_'], ['\\%', '\\_'], $busca));
			$where .= ' AND (nome_contato LIKE "%'.$like.'%"'
				.' OR ultima_mensagem LIKE "%'.$like.'%"'
				.' OR participant_id LIKE "%'.$like.'%")';
		}

		$sql = 'SELECT * FROM meta_conversas WHERE '.$where
			.' ORDER BY COALESCE(ultima_mensagem_em, created_at) DESC'
			.' LIMIT '.$limite;

		$rows = (new Database('meta_conversas'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
		foreach ($rows as &$row) {
			$row['canal_label'] = self::labelCanal((string)($row['canal'] ?? 'messenger'));
		}
		unset($row);
		return $rows;
	}

	/**
	 * @return array{total:int,nao_lidas:int,messenger:int,instagram:int,arquivadas:int}
	 */
	public static function indicadores(int $idAdmin): array {
		$out = [
			'total'      => 0,
			'nao_lidas'  => 0,
			'messenger'  => 0,
			'instagram'  => 0,
			'arquivadas' => 0,
		];
		if (!self::tabelaExiste() || $idAdmin <= 0) {
			return $out;
		}

		$sql = 'SELECT
			SUM(status = "aberta") AS total,
			SUM(status = "aberta" AND nao_lidas > 0) AS nao_lidas,
			SUM(status = "aberta" AND canal = "messenger") AS messenger,
			SUM(status = "aberta" AND canal = "instagram") AS instagram,
			SUM(status = "arquivada") AS arquivadas
			FROM meta_conversas WHERE id_admin = '.(int)$idAdmin;

		$row = (new Database('meta_conversas'))->execute($sql)->fetch(\PDO::FETCH_ASSOC);
		if ($row) {
			$out['total'] = (int)($row['total'] ?? 0);
			$out['nao_lidas'] = (int)($row['nao_lidas'] ?? 0);
			$out['messenger'] = (int)($row['messenger'] ?? 0);
			$out['instagram'] = (int)($row['instagram'] ?? 0);
			$out['arquivadas'] = (int)($row['arquivadas'] ?? 0);
		}
		return $out;
	}
}
