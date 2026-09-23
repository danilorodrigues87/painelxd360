<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class CategoryCourses{

	public $id;
	public $nome;
	public $descricao;
	public $id_admin;
	public $contrato_clausula_1;
	public $contrato_clausula_2;
	public $contrato_clausula_3;
	public $contrato_clausula_extra;
	public $contrato_pagamento_parcelado;
	public $contrato_pagamento_vista;
	public $contrato_pagamento_bolsista;
	public $contrato_obs_pontualidade;

	public static function getCategoryById($id){
		return self::getCategory('id = '.(int)$id)->fetchObject(self::class);
	}

	public function cadastrar(){
		$obDatabase = new Database('categorias_curso');
		$dados = [
			'nome' => $this->nome,
			'descricao' => $this->descricao,
			'id_admin' => $this->id_admin,
		];
		$this->id = $obDatabase->insert($dados);
		return true;
	}

	public static function getCategory($where = null,$order = null,$limit = null,$fields = '*'){
		return (new Database('categorias_curso'))->select($where,$order,$limit,$fields);
	}

	public function atualizar(){
		return (new Database('categorias_curso'))->update('id = '.$this->id,[
			'nome' => $this->nome,
			'descricao' => $this->descricao,
		]);
	}

	public function excluir(){
		return (new Database('categorias_curso'))->delete('id = '.$this->id);
	}

	public static function temColunaContrato(): bool {
		return self::temColuna('contrato_clausula_1');
	}

	private static function temColuna(string $coluna): bool {
		static $cache = [];
		$coluna = preg_replace('/[^a-z0-9_]/i', '', $coluna) ?: '';
		if ($coluna === '') {
			return false;
		}
		if (array_key_exists($coluna, $cache)) {
			return $cache[$coluna];
		}
		try {
			$row = (new Database('categorias_curso'))->execute(
				"SHOW COLUMNS FROM categorias_curso LIKE '{$coluna}'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache[$coluna] = !empty($row);
		} catch (\Throwable $e) {
			$cache[$coluna] = false;
		}
		return $cache[$coluna];
	}

	/** @return array<string,mixed> */
	public static function rowToContratoArray($row): array {
		if (!is_object($row) && !is_array($row)) {
			return [];
		}
		$a = (array)$row;
		return [
			'contrato_clausula_1'          => (string)($a['contrato_clausula_1'] ?? ''),
			'contrato_clausula_2'          => (string)($a['contrato_clausula_2'] ?? ''),
			'contrato_clausula_3'          => (string)($a['contrato_clausula_3'] ?? ''),
			'contrato_clausula_extra'      => (string)($a['contrato_clausula_extra'] ?? ''),
			'contrato_pagamento_parcelado' => (string)($a['contrato_pagamento_parcelado'] ?? ''),
			'contrato_pagamento_vista'     => (string)($a['contrato_pagamento_vista'] ?? ''),
			'contrato_pagamento_bolsista'  => (string)($a['contrato_pagamento_bolsista'] ?? ''),
			'contrato_obs_pontualidade'    => (string)($a['contrato_obs_pontualidade'] ?? ''),
		];
	}

	public static function contratoEstaCompleto($row): bool {
		if (!self::temColunaContrato()) {
			return false;
		}
		$a = self::rowToContratoArray($row);
		foreach (['contrato_clausula_1', 'contrato_clausula_2', 'contrato_clausula_3'] as $k) {
			if (trim($a[$k]) === '') {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param array<string,mixed> $dados
	 */
	public static function salvarContratoClausulas(int $idCategoria, array $dados): bool {
		if (!self::temColunaContrato()) {
			return false;
		}
		$payload = [];
		foreach ([
			'contrato_clausula_1',
			'contrato_clausula_2',
			'contrato_clausula_3',
			'contrato_clausula_extra',
			'contrato_pagamento_parcelado',
			'contrato_pagamento_vista',
			'contrato_pagamento_bolsista',
			'contrato_obs_pontualidade',
		] as $col) {
			if (array_key_exists($col, $dados)) {
				$val = trim((string)$dados[$col]);
				$payload[$col] = $val === '' ? null : $val;
			}
		}
		if (!$payload) {
			return false;
		}
		return (bool)(new Database('categorias_curso'))->update('id = '.(int)$idCategoria, $payload);
	}

	/** @return array<int,array{id:int,nome:string,contrato_completo:bool}> */
	public static function listarResumoEscola(int $idAdmin): array {
		$out = [];
		$res = self::getCategory('id_admin = '.(int)$idAdmin, 'nome ASC');
		while ($row = $res->fetchObject(self::class)) {
			$out[] = [
				'id'                => (int)$row->id,
				'nome'              => (string)$row->nome,
				'contrato_completo' => self::contratoEstaCompleto($row),
			];
		}
		return $out;
	}
}
