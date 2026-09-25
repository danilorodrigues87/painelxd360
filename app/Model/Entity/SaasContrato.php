<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SaasContrato {

	public $id;
	public $id_admin;
	public $plan_id;
	public $produto_slug;
	public $plano_nome;
	public $modulos_json;
	public $valor_parcela;
	public $qtd_parcelas;
	public $duracao_meses;
	public $ciclo;
	public $inicio;
	public $fim;
	public $status;
	public $recorrente;
	public $renovado_de_id;
	public $html_snapshot;
	public $criado_em;
	public $aceito_em;
	public $aceito_usuario_id;
	public $aceito_nome;
	public $aceito_cpf;
	public $aceito_ip;
	public $aceito_hash;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('saas_contratos'))->execute("SHOW TABLES LIKE 'saas_contratos'")->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function getById(int $id) {
		if ($id <= 0 || !self::tabelaExiste()) {
			return false;
		}
		return self::get('id = '.$id)->fetchObject(self::class);
	}

	public static function get($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database('saas_contratos'))->select($where, $order, $limit, $fields);
	}

	/** @return self[] */
	public static function vigentesDoCliente(int $idAdmin): array {
		if ($idAdmin <= 0 || !self::tabelaExiste()) {
			return [];
		}
		$out = [];
		$rs = self::get('id_admin = '.$idAdmin.' AND status = "vigente"', 'id ASC');
		while ($c = $rs->fetchObject(self::class)) {
			$out[] = $c;
		}
		return $out;
	}

	public function cadastrar(): bool {
		$this->id = (int)(new Database('saas_contratos'))->insert([
			'id_admin'       => (int)$this->id_admin,
			'plan_id'        => $this->plan_id ? (int)$this->plan_id : null,
			'produto_slug'   => $this->produto_slug ?: null,
			'plano_nome'     => (string)$this->plano_nome,
			'modulos_json'   => $this->modulos_json,
			'valor_parcela'  => round((float)$this->valor_parcela, 2),
			'qtd_parcelas'   => max(1, (int)$this->qtd_parcelas),
			'duracao_meses'  => max(1, (int)$this->duracao_meses),
			'ciclo'          => $this->ciclo === 'anual' ? 'anual' : 'mensal',
			'inicio'         => $this->inicio,
			'fim'            => $this->fim ?: null,
			'status'         => $this->status ?: 'vigente',
			'recorrente'     => (int)$this->recorrente ? 1 : 0,
			'renovado_de_id' => $this->renovado_de_id ? (int)$this->renovado_de_id : null,
			'html_snapshot'  => $this->html_snapshot ?: null,
		]);
		return $this->id > 0;
	}

	public function registrarAceite(int $usuarioId, string $nome, string $cpf, string $ip, string $hash): bool {
		return (bool)(new Database('saas_contratos'))->update('id = '.(int)$this->id, [
			'aceito_em'         => date('Y-m-d H:i:s'),
			'aceito_usuario_id' => $usuarioId,
			'aceito_nome'       => mb_substr($nome, 0, 191),
			'aceito_cpf'        => $cpf,
			'aceito_ip'         => mb_substr($ip, 0, 45),
			'aceito_hash'       => $hash,
		]);
	}

	public function atualizar(): bool {
		return (bool)(new Database('saas_contratos'))->update('id = '.(int)$this->id, [
			'status'        => $this->status,
			'fim'           => $this->fim ?: null,
			'html_snapshot' => $this->html_snapshot ?: null,
			'valor_parcela' => round((float)$this->valor_parcela, 2),
		]);
	}

	/** @return string[] */
	public function modulos(): array {
		$decoded = json_decode((string)($this->modulos_json ?? ''), true);
		if (!is_array($decoded)) {
			return [];
		}
		$out = [];
		foreach ($decoded as $s) {
			$s = trim((string)$s);
			if ($s !== '') {
				$out[] = $s;
			}
		}
		return $out;
	}
}
