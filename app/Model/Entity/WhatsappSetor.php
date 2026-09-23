<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class WhatsappSetor {

	public $id;
	public $id_admin;
	public $nome;
	public $slug;
	public $ativo = 1;
	public $ordem = 0;
	public $mensagem_fila;

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
			$stmt = $pdo->query("SHOW TABLES LIKE 'whatsapp_setores'");
			$cache = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function listarAtivos(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$rows = (new Database('whatsapp_setores'))
			->select('id_admin = '.(int)$idAdmin.' AND ativo = 1', 'ordem ASC, id ASC')
			->fetchAll(\PDO::FETCH_ASSOC);
		return $rows ?: [];
	}

	public static function listarTodos(int $idAdmin): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$rows = (new Database('whatsapp_setores'))
			->select('id_admin = '.(int)$idAdmin, 'ordem ASC, id ASC')
			->fetchAll(\PDO::FETCH_ASSOC);
		return $rows ?: [];
	}

	public static function getById(int $id, int $idAdmin) {
		if (!self::tabelaExiste()) {
			return null;
		}
		return (new Database('whatsapp_setores'))
			->select('id = '.(int)$id.' AND id_admin = '.(int)$idAdmin, null, 1)
			->fetchObject(self::class) ?: null;
	}

	/** Cria setores padrão se a escola ainda não tiver nenhum. */
	public static function garantirPadroes(int $idAdmin): void {
		if (!self::tabelaExiste()) {
			return;
		}
		$qtd = (new Database('whatsapp_setores'))
			->select('id_admin = '.(int)$idAdmin, null, null, 'COUNT(*) AS c')
			->fetch(\PDO::FETCH_ASSOC);
		if ((int)($qtd['c'] ?? 0) > 0) {
			return;
		}

		$padroes = [
			['nome' => 'Comercial', 'slug' => 'comercial', 'ordem' => 1],
			['nome' => 'Financeiro', 'slug' => 'financeiro', 'ordem' => 2],
			['nome' => 'Secretaria', 'slug' => 'secretaria', 'ordem' => 3],
			['nome' => 'Pedagógico', 'slug' => 'pedagogico', 'ordem' => 4],
		];

		$db = new Database('whatsapp_setores');
		foreach ($padroes as $p) {
			$db->insert([
				'id_admin' => $idAdmin,
				'nome'     => $p['nome'],
				'slug'     => $p['slug'],
				'ordem'    => $p['ordem'],
				'ativo'    => 1,
				'mensagem_fila' => 'Você foi direcionado para *'.$p['nome'].'*. Aguarde, em breve um atendente irá responder.',
			]);
		}
	}

	public function salvar(): bool {
		$db = new Database('whatsapp_setores');
		$dados = [
			'nome' => $this->nome,
			'slug' => $this->slug,
			'ativo' => (int)$this->ativo,
			'ordem' => (int)$this->ordem,
			'mensagem_fila' => $this->mensagem_fila,
		];
		if (!empty($this->id)) {
			$db->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, $dados);
			return true;
		}
		$dados['id_admin'] = (int)$this->id_admin;
		$this->id = (int)$db->insert($dados);
		return $this->id > 0;
	}
}
