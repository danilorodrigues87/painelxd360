<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappConversa {

	public $id;
	public $id_admin;
	public $numero_id;
	public $telefone;
	public $nome_contato;
	public $status = 'aberta';
	public $setor_id;
	public $id_atendente;
	public $chatbot_estado = 'novo';
	public $assigned_at;
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
			$stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_conversas'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunasChatbot(): bool {
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
			$stmt = $pdo->query("SHOW COLUMNS FROM whatsapp_conversas LIKE 'chatbot_estado'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function getById(int $id, int $idAdmin) {
		if (!self::tabelaExiste()) {
			return null;
		}
		return (new Database('whatsapp_conversas'))
			->select('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin, null, 1)
			->fetchObject(self::class) ?: null;
	}

	public static function getByIdAdminTelefone(int $idAdmin, string $telefone) {
		if (!self::tabelaExiste()) {
			return null;
		}
		$tel = addslashes($telefone);
		return (new Database('whatsapp_conversas'))
			->select('id_admin = '.(int)$idAdmin.' AND telefone = "'.$tel.'"', null, 1)
			->fetchObject(self::class) ?: null;
	}

	/** Une thread @lid com telefone real quando o sufixo bate. */
	public static function buscarConversaPorTelefoneReal(int $idAdmin, string $telefone): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$digitos = preg_replace('/\D+/', '', $telefone) ?? '';
		if (strlen($digitos) < 10) {
			return null;
		}
		$suf = addslashes(substr($digitos, -8));
		$tel = addslashes($telefone);
		return (new Database('whatsapp_conversas'))
			->select(
				'id_admin = '.(int)$idAdmin
				.' AND (telefone = "'.$tel.'" OR telefone LIKE "%'.$suf.'" OR telefone LIKE "lid:%'.$suf.'%")',
				'id DESC',
				1
			)
			->fetchObject(self::class) ?: null;
	}

	public static function findOrCreate(int $idAdmin, string $telefone, ?string $nome = null, ?int $numeroId = null, bool $nomeDoCliente = false): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}

		$nome = $nome !== null ? trim($nome) : null;
		if ($nome === '') {
			$nome = null;
		}

		$existente = self::getByIdAdminTelefone($idAdmin, $telefone);
		if (!($existente instanceof self) && strpos($telefone, 'lid:') !== 0) {
			$existente = self::buscarConversaPorTelefoneReal($idAdmin, $telefone);
		}
		if ($existente instanceof self) {
			$upd = [];
			if (strpos($telefone, 'lid:') !== 0 && strpos((string)$existente->telefone, 'lid:') === 0) {
				$upd['telefone'] = $telefone;
				$existente->telefone = $telefone;
			}
			if ($nome && ($nomeDoCliente || empty($existente->nome_contato))) {
				$upd['nome_contato'] = $nome;
				$existente->nome_contato = $nome;
			}
			if ($numeroId && empty($existente->numero_id) && self::temColunasChatbot()) {
				$upd['numero_id'] = $numeroId;
				$existente->numero_id = $numeroId;
			}
			if ($upd) {
				(new Database('whatsapp_conversas'))->update('id = '.(int)$existente->id, $upd);
			}
			return $existente;
		}

		if ($nome === null) {
			$nome = self::resolverNomePorTelefone($idAdmin, $telefone);
		}

		$dados = [
			'id_admin'     => $idAdmin,
			'telefone'     => $telefone,
			'nome_contato' => $nome,
			'status'       => 'aberta',
		];
		if (self::temColunasChatbot()) {
			$dados['chatbot_estado'] = 'novo';
			$dados['numero_id'] = $numeroId;
		}

		$db = new Database('whatsapp_conversas');
		$id = $db->insert($dados);

		$ob = new self;
		$ob->id = (int)$id;
		$ob->id_admin = $idAdmin;
		$ob->telefone = $telefone;
		$ob->nome_contato = $nome;
		$ob->status = 'aberta';
		$ob->chatbot_estado = 'novo';
		$ob->numero_id = $numeroId;
		return $ob;
	}

	/** Busca nome do aluno/responsável pelo WhatsApp cadastrado. */
	public static function resolverNomePorTelefone(int $idAdmin, string $telefone): ?string {
		$digitos = preg_replace('/\D+/', '', $telefone) ?? '';
		if ($digitos === '') {
			return null;
		}
		$suf = strlen($digitos) >= 8 ? substr($digitos, -8) : $digitos;
		try {
			$like = '%'.addslashes($suf).'%';
			$stmt = (new Database('usuarios'))->select(
				'id_admin = '.(int)$idAdmin.' AND whatsapp IS NOT NULL AND whatsapp != ""'
				.' AND REPLACE(REPLACE(REPLACE(REPLACE(whatsapp," ",""),"-",""),"(",""),")","") LIKE "'.$like.'"',
				'id DESC',
				1,
				'nome'
			);
			$row = $stmt->fetch(\PDO::FETCH_ASSOC);
			if ($row && !empty($row['nome'])) {
				return trim((string)$row['nome']);
			}
		} catch (\Throwable $e) {
			return null;
		}
		return null;
	}

	public function tocarUltimaMensagem(bool $marcarNaoLida = false): void {
		$dados = ['ultima_mensagem_em' => date('Y-m-d H:i:s')];
		if ($marcarNaoLida && self::temColunaNaoLida()) {
			$dados['nao_lida'] = 1;
		}
		(new Database('whatsapp_conversas'))->update('id = '.(int)$this->id, $dados);
	}

	public static function temColunaNaoLida(): bool {
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
			$stmt = $pdo->query("SHOW COLUMNS FROM whatsapp_conversas LIKE 'nao_lida'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public function marcarLida(): void {
		if (!self::temColunaNaoLida()) {
			return;
		}
		$this->atualizar(['nao_lida' => 0]);
	}

	/**
	 * Indicadores do inbox.
	 * @return array{nao_lidas:int,fila:int,abertas:int,por_setor:array}
	 */
	public static function indicadores(int $idAdmin, int $usuarioId, string $nivel, array $setorIds): array {
		$base = self::listarInbox($idAdmin, $usuarioId, $nivel, $setorIds, 200, 'todas', '');
		$naoLidas = 0;
		$fila = 0;
		$abertas = 0;
		$porSetor = [];

		foreach ($base as $c) {
			$status = (string)($c['status'] ?? '');
			$estado = (string)($c['chatbot_estado'] ?? '');
			if ($status === 'fechada' || $estado === 'encerrado') {
				continue;
			}
			$abertas++;

			if (self::temColunaNaoLida() && (int)($c['nao_lida'] ?? 0) === 1) {
				$naoLidas++;
			}

			$semAtendente = empty($c['id_atendente']);
			if ($semAtendente && in_array($estado, ['fila', 'aguardando_setor', 'novo'], true)) {
				$fila++;
			}

			$setorNome = trim((string)($c['setor_nome'] ?? '')) !== '' ? (string)$c['setor_nome'] : 'Sem setor';
			if (!isset($porSetor[$setorNome])) {
				$porSetor[$setorNome] = 0;
			}
			$porSetor[$setorNome]++;
		}

		if (!self::temColunaNaoLida() && WhatsappMensagem::tabelaExiste()) {
			$naoLidas = self::contarNaoLidasHeuristica($idAdmin, array_column($base, 'id'));
		}

		arsort($porSetor);
		$listaSetor = [];
		foreach ($porSetor as $nome => $qtd) {
			$listaSetor[] = ['setor' => $nome, 'qtd' => $qtd];
		}

		return [
			'nao_lidas' => $naoLidas,
			'fila' => $fila,
			'abertas' => $abertas,
			'por_setor' => $listaSetor,
		];
	}

	private static function contarNaoLidasHeuristica(int $idAdmin, array $conversaIds): int {
		$ids = array_filter(array_map('intval', $conversaIds));
		if (!$ids) {
			return 0;
		}
		$sql = '
			SELECT COUNT(*) AS qtd FROM whatsapp_conversas c
			WHERE c.id_admin = '.(int)$idAdmin.'
			  AND c.id IN ('.implode(',', $ids).')
			  AND c.status != "fechada"
			  AND EXISTS (
			    SELECT 1 FROM whatsapp_mensagens m
			    WHERE m.conversa_id = c.id AND m.direction = "in"
			      AND m.id = (
			        SELECT MAX(m2.id) FROM whatsapp_mensagens m2 WHERE m2.conversa_id = c.id
			      )
			  )
		';
		$row = (new Database('whatsapp_conversas'))->execute($sql)->fetch(\PDO::FETCH_ASSOC);
		return (int)($row['qtd'] ?? 0);
	}

	public function atualizar(array $dados): void {
		if (!$dados) {
			return;
		}
		(new Database('whatsapp_conversas'))->update('id = '.(int)$this->id, $dados);
		foreach ($dados as $k => $v) {
			$this->$k = $v;
		}
	}

	/**
	 * Mesma regra de visibilidade usada na listagem do inbox e ao carregar mensagens.
	 */
	public static function usuarioPodeVerConversa(self $conv, string $nivel, int $usuarioId, array $setorIds): bool {
		if ($nivel === 'Diretor') {
			return true;
		}
		if ((int)$conv->id_atendente === $usuarioId) {
			return true;
		}
		if ($conv->setor_id && in_array((int)$conv->setor_id, $setorIds, true) && !(int)$conv->id_atendente) {
			return true;
		}
		$estado = (string)($conv->chatbot_estado ?? '');
		if (in_array($estado, ['novo', 'aguardando_setor', 'fila'], true) && !(int)$conv->id_atendente) {
			return true;
		}
		if ($conv->setor_id === null && !(int)$conv->id_atendente && in_array($estado, ['novo', 'aguardando_setor', 'fila', ''], true)) {
			return true;
		}
		return false;
	}

	/**
	 * Lista conversas visíveis ao usuário.
	 * @param string $filtro todas|minhas|fila|nao_lidas|abertas
	 */
	public static function listarInbox(
		int $idAdmin,
		int $usuarioId,
		string $nivel,
		array $setorIds,
		int $limite = 80,
		string $filtro = 'todas',
		string $busca = ''
	): array {
		if (!self::tabelaExiste()) {
			return [];
		}

		$limite = max(1, min(200, $limite));
		$filtro = in_array($filtro, ['minhas', 'fila', 'todas', 'nao_lidas', 'abertas'], true) ? $filtro : 'todas';
		$where = 'c.id_admin = '.(int)$idAdmin;

		// Visibilidade base
		if ($nivel !== 'Diretor') {
			$parts = ['c.id_atendente = '.(int)$usuarioId];
			if ($setorIds) {
				$ids = implode(',', array_map('intval', $setorIds));
				$parts[] = '(c.setor_id IN ('.$ids.') AND (c.id_atendente IS NULL OR c.id_atendente = 0))';
			}
			$parts[] = "(c.chatbot_estado IN ('novo','aguardando_setor') OR (c.setor_id IS NULL AND c.id_atendente IS NULL))";
			$where .= ' AND ('.implode(' OR ', $parts).')';
		}

		if ($filtro === 'minhas') {
			$where .= ' AND c.id_atendente = '.(int)$usuarioId;
		} elseif ($filtro === 'fila') {
			$where .= ' AND (c.id_atendente IS NULL OR c.id_atendente = 0)';
			$where .= " AND c.status != 'fechada' AND IFNULL(c.chatbot_estado,'') != 'encerrado'";
		} elseif ($filtro === 'nao_lidas') {
			$where .= " AND c.status != 'fechada' AND IFNULL(c.chatbot_estado,'') != 'encerrado'";
			if (self::temColunaNaoLida()) {
				$where .= ' AND c.nao_lida = 1';
			} elseif (WhatsappMensagem::tabelaExiste()) {
				$where .= ' AND EXISTS (
					SELECT 1 FROM whatsapp_mensagens m
					WHERE m.conversa_id = c.id AND m.direction = "in"
					  AND m.id = (
					    SELECT MAX(m2.id) FROM whatsapp_mensagens m2 WHERE m2.conversa_id = c.id
					  )
				)';
			}
		} elseif ($filtro === 'abertas') {
			$where .= " AND c.status != 'fechada' AND IFNULL(c.chatbot_estado,'') != 'encerrado'";
		} else {
			// todas: oculta encerradas antigas da lista principal (ainda aparecem na busca)
			if ($busca === '') {
				$where .= " AND (c.status IS NULL OR c.status != 'fechada' OR c.ultima_mensagem_em >= DATE_SUB(NOW(), INTERVAL 2 DAY))";
			}
		}

		$busca = trim($busca);
		if ($busca !== '') {
			$like = addslashes(str_replace(['%', '_'], ['\\%', '\\_'], $busca));
			$digitos = preg_replace('/\D+/', '', $busca) ?? '';
			$or = [
				'c.nome_contato LIKE "%'.$like.'%"',
				'c.telefone LIKE "%'.$like.'%"',
			];
			if ($digitos !== '') {
				$or[] = 'c.telefone LIKE "%'.addslashes($digitos).'%"';
			}
			$where .= ' AND ('.implode(' OR ', $or).')';
		}

		$joinSetor = WhatsappSetor::tabelaExiste()
			? 'LEFT JOIN whatsapp_setores s ON s.id = c.setor_id'
			: '';
		$camposSetor = WhatsappSetor::tabelaExiste() ? ', s.nome AS setor_nome' : ', NULL AS setor_nome';

		$sql = 'SELECT c.*, u.nome AS atendente_nome'.$camposSetor.'
			FROM whatsapp_conversas c
			LEFT JOIN usuarios u ON u.id = c.id_atendente
			'.$joinSetor.'
			WHERE '.$where.'
			ORDER BY COALESCE(c.ultima_mensagem_em, c.created_at) DESC
			LIMIT '.$limite;

		return (new Database('whatsapp_conversas'))->execute($sql)->fetchAll(\PDO::FETCH_ASSOC) ?: [];
	}

	/**
	 * Encerra em lote conversas em andamento visíveis ao usuário (mesma regra do inbox).
	 */
	public static function fecharTodasEmAndamento(
		int $idAdmin,
		int $usuarioId,
		string $nivel,
		array $setorIds
	): int {
		if (!self::tabelaExiste()) {
			return 0;
		}

		$where = 'id_admin = '.(int)$idAdmin;

		if ($nivel !== 'Diretor') {
			$parts = ['id_atendente = '.(int)$usuarioId];
			if ($setorIds) {
				$ids = implode(',', array_map('intval', $setorIds));
				$parts[] = '(setor_id IN ('.$ids.') AND (id_atendente IS NULL OR id_atendente = 0))';
			}
			$parts[] = "(chatbot_estado IN ('novo','aguardando_setor') OR (setor_id IS NULL AND id_atendente IS NULL))";
			$where .= ' AND ('.implode(' OR ', $parts).')';
		}

		$where .= " AND (status IS NULL OR status != 'fechada')";
		$where .= " AND IFNULL(chatbot_estado,'') != 'encerrado'";

		$sql = 'UPDATE whatsapp_conversas SET
			status = "fechada",
			chatbot_estado = "encerrado",
			id_atendente = NULL,
			setor_id = NULL,
			assigned_at = NULL
			WHERE '.$where;

		$stmt = (new Database('whatsapp_conversas'))->execute($sql);

		return (int)$stmt->rowCount();
	}
}
