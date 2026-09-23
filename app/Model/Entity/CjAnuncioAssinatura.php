<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use PDO;

class CjAnuncioAssinatura {

	public $id;
	public $id_empresa;
	public $plan_id;
	public $status = 'pendente';
	public $inicio_em;
	public $fim_em;
	public $proximo_vencimento;
	public $cancelada_em;

	public static function tabelaExiste(): bool {
		static $ok = null;
		if ($ok !== null) {
			return $ok;
		}
		try {
			$stmt = (new Database())->execute("SHOW TABLES LIKE 'cj_anuncio_assinaturas'");
			$ok = $stmt && $stmt->rowCount() > 0;
		} catch (\Throwable $e) {
			$ok = false;
		}
		return $ok;
	}

	public static function getById(int $id): ?self {
		if (!self::tabelaExiste() || $id <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_assinaturas'))->select('id = '.(int)$id, null, '1')->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getVigentePorEmpresa(int $idEmpresa): ?self {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_assinaturas'))->select(
			'id_empresa = '.(int)$idEmpresa.' AND status IN ("pendente","ativa")',
			'(status = "ativa") DESC, id DESC',
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getAtivaPorEmpresa(int $idEmpresa): ?self {
		if (!self::tabelaExiste() || $idEmpresa <= 0) {
			return null;
		}
		$row = (new Database('cj_anuncio_assinaturas'))->select(
			'id_empresa = '.(int)$idEmpresa.' AND status = "ativa"',
			'id DESC',
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	/** @return array<int,array<string,mixed>> */
	public static function listarMaster(int $limit = 200): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$sql = 'SELECT a.*, p.nome AS plano_nome, p.max_anuncios, p.valor_mensal, e.nome_fantasia AS empresa_nome '
			.'FROM cj_anuncio_assinaturas a '
			.'LEFT JOIN cj_anuncio_planos p ON p.id = a.plan_id '
			.'LEFT JOIN cj_empresas e ON e.id = a.id_empresa '
			.'ORDER BY a.id DESC LIMIT '.max(1, min(500, $limit));
		$stmt = (new Database())->execute($sql);
		return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
	}

	/** @return array<int,self> */
	public static function listarParaWorker(?int $idEmpresa = null): array {
		if (!self::tabelaExiste()) {
			return [];
		}
		$where = 'status IN ("ativa","pendente")';
		if ($idEmpresa !== null && $idEmpresa > 0) {
			$where .= ' AND id_empresa = '.(int)$idEmpresa;
		}
		$stmt = (new Database('cj_anuncio_assinaturas'))->select($where, 'id ASC');
		$out = [];
		while ($row = $stmt->fetchObject(self::class)) {
			if ($row instanceof self) {
				$out[] = $row;
			}
		}
		return $out;
	}

	public function cadastrar(): bool {
		$this->id = (int)(new Database('cj_anuncio_assinaturas'))->insert([
			'id_empresa'          => (int)$this->id_empresa,
			'plan_id'             => (int)$this->plan_id,
			'status'              => $this->status ?: 'pendente',
			'inicio_em'           => $this->inicio_em ?: null,
			'fim_em'              => $this->fim_em ?: null,
			'proximo_vencimento'  => $this->proximo_vencimento ?: null,
			'cancelada_em'        => $this->cancelada_em ?: null,
		]);
		return $this->id > 0;
	}

	public function atualizar(): bool {
		return (bool)(new Database('cj_anuncio_assinaturas'))->update('id = '.(int)$this->id, [
			'plan_id'             => (int)$this->plan_id,
			'status'              => $this->status,
			'inicio_em'           => $this->inicio_em ?: null,
			'fim_em'              => $this->fim_em ?: null,
			'proximo_vencimento'  => $this->proximo_vencimento ?: null,
			'cancelada_em'        => $this->cancelada_em ?: null,
		]);
	}
}
