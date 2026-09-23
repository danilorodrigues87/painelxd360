<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class CampanhaFila {

	public $id;
	public $campanha_id;
	public $id_admin;
	public $destinatario_tipo;
	public $destinatario_id;
	public $nome;
	public $contato;
	public $curso;
	public $status = 'pendente';
	public $tentativas = 0;
	public $erro_msg;
	public $enviado_em;
	public $criado_em;

	public static function tabelaExiste(): bool {
		return Campanhas::tabelaExiste();
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('campanha_fila'))->select($where, $order, $limit, $fields);
	}

	public static function contarPorCampanha(int $campanhaId, int $idAdmin, ?string $status = null): int {
		$where = 'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin;
		if ($status !== null) {
			$where .= ' AND status = "'.addslashes($status).'"';
		}
		$row = self::get($where, null, null, 'COUNT(*) AS qtd')->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}

	/** Uma query para total/enviados/erros/pendentes (evita 4 COUNT na resposta web). */
	public static function resumoPorCampanha(int $campanhaId, int $idAdmin): array {
		$where = 'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin;
		$row = self::get(
			$where,
			null,
			null,
			'COUNT(*) AS total,'
			.' SUM(CASE WHEN status = "enviado" THEN 1 ELSE 0 END) AS enviados,'
			.' SUM(CASE WHEN status = "erro" THEN 1 ELSE 0 END) AS erros,'
			.' SUM(CASE WHEN status = "pendente" THEN 1 ELSE 0 END) AS pendentes'
		)->fetch(\PDO::FETCH_ASSOC);

		return [
			'total'     => (int)($row['total'] ?? 0),
			'enviados'  => (int)($row['enviados'] ?? 0),
			'erros'     => (int)($row['erros'] ?? 0),
			'pendentes' => (int)($row['pendentes'] ?? 0),
		];
	}

	public static function limparCampanha(int $campanhaId, int $idAdmin): void {
		(new Database('campanha_fila'))->delete(
			'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin
		);
	}

	/** Remove só pendentes/cancelados — mantém enviados e erros como histórico. */
	public static function limparPendentesCampanha(int $campanhaId, int $idAdmin): void {
		(new Database('campanha_fila'))->delete(
			'campanha_id = '.(int)$campanhaId
			.' AND id_admin = '.(int)$idAdmin
			.' AND status IN ("pendente", "cancelado")'
		);
	}

	public static function resumoRelatorio(int $campanhaId, int $idAdmin): array {
		$where = 'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin;
		$row = self::get(
			$where,
			null,
			null,
			'COUNT(*) AS total,'
			.' SUM(CASE WHEN status = "enviado" THEN 1 ELSE 0 END) AS enviados,'
			.' SUM(CASE WHEN status = "erro" THEN 1 ELSE 0 END) AS erros,'
			.' SUM(CASE WHEN status = "pendente" THEN 1 ELSE 0 END) AS pendentes,'
			.' SUM(CASE WHEN status = "cancelado" THEN 1 ELSE 0 END) AS cancelados'
		)->fetch(\PDO::FETCH_ASSOC);

		return [
			'total'      => (int)($row['total'] ?? 0),
			'enviados'   => (int)($row['enviados'] ?? 0),
			'erros'      => (int)($row['erros'] ?? 0),
			'pendentes'  => (int)($row['pendentes'] ?? 0),
			'cancelados' => (int)($row['cancelados'] ?? 0),
		];
	}

	/**
	 * @return array{itens:array,pagination:array}
	 */
	public static function listarRelatorio(int $campanhaId, int $idAdmin, array $opts = []): array {
		$where = 'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin;
		$status = trim((string)($opts['status'] ?? ''));
		if ($status !== '' && in_array($status, ['enviado', 'erro', 'pendente', 'cancelado'], true)) {
			$where .= ' AND status = "'.addslashes($status).'"';
		}
		$busca = trim((string)($opts['busca'] ?? ''));
		if ($busca !== '') {
			$like = '%'.addslashes($busca).'%';
			$where .= ' AND (nome LIKE "'.$like.'" OR contato LIKE "'.$like.'" OR erro_msg LIKE "'.$like.'")';
		}

		$page = max(1, (int)($opts['page'] ?? 1));
		$limit = min(100, max(10, (int)($opts['limit'] ?? 25)));
		$rowCount = self::get($where, null, null, 'COUNT(*) AS q')->fetch(\PDO::FETCH_ASSOC);
		$total = (int)($rowCount['q'] ?? 0);
		$pages = max(1, (int)ceil($total / $limit));
		if ($page > $pages) {
			$page = $pages;
		}
		$offset = ($page - 1) * $limit;
		$order = 'FIELD(status, "erro", "pendente", "enviado", "cancelado"), id DESC';
		$results = self::get($where, $order, $offset.','.$limit);

		$itens = [];
		while ($row = $results->fetchObject(self::class)) {
			$itens[] = $row;
		}

		return [
			'itens' => $itens,
			'pagination' => [
				'page' => $page,
				'pages' => $pages,
				'total' => $total,
				'limit' => $limit,
			],
		];
	}

	public static function temColunaCurso(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('campanha_fila'))->execute(
				"SHOW COLUMNS FROM campanha_fila LIKE 'curso'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function inserirLote(array $itens): int {
		$inseridos = 0;
		$db = new Database('campanha_fila');
		$comCurso = self::temColunaCurso();

		foreach ($itens as $item) {
			$row = [
				'campanha_id'        => (int)$item['campanha_id'],
				'id_admin'           => (int)$item['id_admin'],
				'destinatario_tipo'  => $item['destinatario_tipo'],
				'destinatario_id'    => $item['destinatario_id'] ?? null,
				'nome'               => $item['nome'] ?? null,
				'contato'            => $item['contato'],
				'status'             => 'pendente',
			];
			if ($comCurso) {
				$row['curso'] = isset($item['curso']) && $item['curso'] !== ''
					? mb_substr((string)$item['curso'], 0, 255)
					: null;
			}
			$db->insert($row);
			$inseridos++;
		}

		return $inseridos;
	}

	public static function getPendentes(int $idAdmin, int $limite = 10, ?int $campanhaId = null) {
		$where = 'id_admin = '.(int)$idAdmin.' AND status = "pendente"';
		if ($campanhaId !== null) {
			$where .= ' AND campanha_id = '.(int)$campanhaId;
		}
		return self::get($where, 'id ASC', (int)$limite);
	}

	/** Pendentes de campanhas em envio no canal informado (evita misturar e-mail e WhatsApp). */
	public static function getPendentesPorCanal(int $idAdmin, string $canal, int $limite = 10) {
		$canal = $canal === 'whatsapp' ? 'whatsapp' : 'email';
		$limite = max(1, (int)$limite);
		$sql = '
			SELECT f.id, f.campanha_id
			FROM campanha_fila f
			INNER JOIN campanhas c ON c.id = f.campanha_id AND c.id_admin = f.id_admin
			WHERE f.id_admin = '.(int)$idAdmin.'
			  AND f.status = "pendente"
			  AND c.status = "enviando"
			  AND c.canal = "'.addslashes($canal).'"
			ORDER BY f.id ASC
		';
		$stmt = (new Database('campanha_fila'))->execute($sql);
		$porCampanha = [];
		while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			$cid = (int)($row['campanha_id'] ?? 0);
			if ($cid <= 0) {
				continue;
			}
			if (!isset($porCampanha[$cid])) {
				$porCampanha[$cid] = [];
			}
			$porCampanha[$cid][] = (int)$row['id'];
		}

		$ids = [];
		$round = 0;
		while (count($ids) < $limite) {
			$added = false;
			foreach ($porCampanha as $lista) {
				if (!isset($lista[$round])) {
					continue;
				}
				$ids[] = $lista[$round];
				$added = true;
				if (count($ids) >= $limite) {
					break 2;
				}
			}
			if (!$added) {
				break;
			}
			$round++;
		}

		if (!$ids) {
			return (new Database('campanha_fila'))->execute('SELECT * FROM campanha_fila WHERE 1=0');
		}

		$idList = implode(',', array_map('intval', $ids));
		return (new Database('campanha_fila'))->execute(
			'SELECT f.* FROM campanha_fila f WHERE f.id IN ('.$idList.') ORDER BY FIELD(f.id, '.$idList.')'
		);
	}

	public function marcarEnviado(?string $mensagemEnviada = null): void {
		$dados = [
			'status'     => 'enviado',
			'tentativas' => (int)$this->tentativas + 1,
			'enviado_em' => date('Y-m-d H:i:s'),
			'erro_msg'   => null,
		];
		if ($mensagemEnviada !== null && self::temColunaMensagemEnviada()) {
			$dados['mensagem_enviada'] = $mensagemEnviada;
		}
		(new Database('campanha_fila'))->update('id = '.(int)$this->id, $dados);
	}

	public static function temColunaMensagemEnviada(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('campanha_fila'))->execute(
				"SHOW COLUMNS FROM campanha_fila LIKE 'mensagem_enviada'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public function marcarErro(string $mensagem): void {
		(new Database('campanha_fila'))->update('id = '.(int)$this->id, [
			'status'     => 'erro',
			'tentativas' => (int)$this->tentativas + 1,
			'erro_msg'   => mb_substr($mensagem, 0, 500),
		]);
	}

	public static function cancelarPendentes(int $campanhaId, int $idAdmin): void {
		$db = new Database('campanha_fila');
		$db->update(
			'campanha_id = '.(int)$campanhaId.' AND id_admin = '.(int)$idAdmin.' AND status = "pendente"',
			['status' => 'cancelado']
		);
	}
}
