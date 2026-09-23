<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class Campanhas {

	public $id;
	public $id_admin;
	public $canal = 'email';
	public $tipo = 'manual';
	public $titulo;
	public $assunto;
	public $mensagem;
	public $segmento;
	public $status = 'rascunho';
	public $total = 0;
	public $enviados = 0;
	public $erros = 0;
	public $agendada_para;
	public $criada_por;
	public $criada_em;
	public $atualizada_em;

	public static function tabelaExiste(): bool {
		try {
			$pdo = new \PDO(
				'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_NAME').';charset=utf8mb4',
				getenv('DB_USER'),
				getenv('DB_PASS'),
				[\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
			);
			$stmt = $pdo->query("SHOW TABLES LIKE 'campanhas'");
			return $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			return false;
		}
	}

	public static function getById(int $id, int $idAdmin = 0) {
		if (!self::tabelaExiste()) {
			return null;
		}
		$where = 'id = '.(int)$id;
		if ($idAdmin > 0) {
			$where .= ' AND id_admin = '.(int)$idAdmin;
		}
		return self::get($where)->fetchObject(self::class);
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('campanhas'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(): bool {
		$db = new Database('campanhas');
		$id = $db->insert([
			'id_admin'      => (int)$this->id_admin,
			'canal'         => $this->canal,
			'tipo'          => $this->tipo,
			'titulo'        => $this->titulo,
			'assunto'       => $this->assunto,
			'mensagem'      => $this->mensagem,
			'segmento'      => $this->segmento,
			'status'        => $this->status,
			'total'         => (int)$this->total,
			'enviados'      => (int)$this->enviados,
			'erros'         => (int)$this->erros,
			'agendada_para' => $this->agendada_para,
			'criada_por'    => (int)$this->criada_por,
		]);
		$this->id = (int)$id;
		return $id > 0;
	}

	public function atualizar(): bool {
		return (bool)(new Database('campanhas'))->update('id = '.(int)$this->id.' AND id_admin = '.(int)$this->id_admin, [
			'canal'         => $this->canal,
			'tipo'          => $this->tipo,
			'titulo'        => $this->titulo,
			'assunto'       => $this->assunto,
			'mensagem'      => $this->mensagem,
			'segmento'      => $this->segmento,
			'status'        => $this->status,
			'total'         => (int)$this->total,
			'enviados'      => (int)$this->enviados,
			'erros'         => (int)$this->erros,
			'agendada_para' => $this->agendada_para,
		]);
	}

	public function ehCampanhaGrupos(): bool {
		$seg = json_decode($this->segmento ?? '{}', true) ?: [];
		return ($seg['tipo'] ?? '') === 'whatsapp_grupos';
	}

	public function recalcularTotais(): void {
		$campanhaId = (int)$this->id;
		$idAdmin = (int)$this->id_admin;

		$total = CampanhaFila::contarPorCampanha($campanhaId, $idAdmin);
		$enviados = CampanhaFila::contarPorCampanha($campanhaId, $idAdmin, 'enviado');
		$erros = CampanhaFila::contarPorCampanha($campanhaId, $idAdmin, 'erro');
		$pendentes = CampanhaFila::contarPorCampanha($campanhaId, $idAdmin, 'pendente');

		$this->total = $total;
		$this->enviados = $enviados;
		$this->erros = $erros;

		// Campanhas de grupo NÃO concluem sozinhas — ficam "enviando" até o usuário encerrar
		if (
			$this->status === 'enviando'
			&& $pendentes === 0
			&& $total > 0
			&& !$this->ehCampanhaGrupos()
		) {
			$this->status = 'concluida';
		}

		$this->atualizar();
	}
}
