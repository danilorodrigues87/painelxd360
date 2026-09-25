<?php

namespace App\Model\Entity;

use App\Model\Db\Database;
use App\Common\ProductModules;

class PlanosAssinatura {

	public $id;
	public $nome;
	public $produto_slug;
	public $descricao;
	public $descricao_detalhada;
	public $valor_mensal = 0;
	public $ciclo = 'mensal';
	public $valor_sugerido;
	public $modulos;
	public $ativo = 1;
	public $ordem = 0;
	public $criado_em;

	public static function temColunaDescricaoDetalhada(): bool {
		return self::temColuna('descricao_detalhada');
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
			$row = (new Database('planos_assinatura'))->execute(
				"SHOW COLUMNS FROM planos_assinatura LIKE '".$coluna."'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache[$coluna] = !empty($row);
		} catch (\Throwable $e) {
			$cache[$coluna] = false;
		}
		return $cache[$coluna];
	}

	public static function getDescricaoDetalhada(?PlanosAssinatura $plano): string {
		if (!$plano instanceof PlanosAssinatura) {
			return '';
		}
		if (self::temColunaDescricaoDetalhada()) {
			return trim((string)($plano->descricao_detalhada ?? ''));
		}
		return trim((string)($plano->descricao ?? ''));
	}

	public static function temColunaValorMensal(): bool {
		return self::temColuna('valor_mensal');
	}

	public static function temColunaProduto(): bool {
		return self::temColuna('produto_slug');
	}

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$row = (new Database('planos_assinatura'))->execute(
				"SHOW TABLES LIKE 'planos_assinatura'"
			)->fetch(\PDO::FETCH_NUM);
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
		return (new Database('planos_assinatura'))->select($where, $order, $limit, $fields);
	}

	public function cadastrar(): bool {
		$dados = [
			'nome'      => $this->nome,
			'descricao' => $this->descricao,
			'modulos'   => $this->modulos,
			'ativo'     => (int)$this->ativo ? 1 : 0,
			'ordem'     => (int)$this->ordem,
		];
		self::anexarCamposComerciais($dados);
		if (self::temColunaDescricaoDetalhada()) {
			$dados['descricao_detalhada'] = $this->descricaoDetalhadaParaDb();
		}
		$this->id = (int)(new Database('planos_assinatura'))->insert($dados);
		return $this->id > 0;
	}

	public function atualizar(): bool {
		$dados = [
			'nome'      => $this->nome,
			'descricao' => $this->descricao,
			'modulos'   => $this->modulos,
			'ativo'     => (int)$this->ativo ? 1 : 0,
			'ordem'     => (int)$this->ordem,
		];
		self::anexarCamposComerciais($dados);
		if (self::temColunaDescricaoDetalhada()) {
			$dados['descricao_detalhada'] = $this->descricaoDetalhadaParaDb();
		}
		return (bool)(new Database('planos_assinatura'))->update('id = '.(int)$this->id, $dados);
	}

	public function excluir(): bool {
		return (bool)(new Database('planos_assinatura'))->delete('id = '.(int)$this->id);
	}

	private function descricaoDetalhadaParaDb(): ?string {
		$t = trim((string)($this->descricao_detalhada ?? ''));
		return $t !== '' ? $t : null;
	}

	public function ehPlanoDeProduto(): bool {
		return self::temColunaProduto() && trim((string)($this->produto_slug ?? '')) !== '';
	}

	public function valorSugerido(): float {
		if (self::temColuna('valor_sugerido') && $this->valor_sugerido !== null && $this->valor_sugerido !== '') {
			return round((float)$this->valor_sugerido, 2);
		}
		return round((float)($this->valor_mensal ?? 0), 2);
	}

	/** true = todos os módulos. Em plano de produto, NULL = todos os módulos daquele produto. */
	public function temTodosModulos(): bool {
		$raw = $this->modulos ?? null;
		if ($this->ehPlanoDeProduto()) {
			return $raw === null || $raw === '';
		}
		return $raw === null || $raw === '';
	}

	/** @param array<string,mixed> $dados */
	private function anexarCamposComerciais(array &$dados): void {
		$valor = round((float)($this->valor_sugerido ?? $this->valor_mensal ?? 0), 2);
		if (self::temColunaValorMensal()) {
			$dados['valor_mensal'] = $valor;
		}
		if (self::temColunaProduto()) {
			$slug = trim((string)($this->produto_slug ?? ''));
			$dados['produto_slug'] = $slug !== '' ? $slug : null;
		}
		if (self::temColuna('ciclo')) {
			$ciclo = (string)($this->ciclo ?? 'mensal');
			$dados['ciclo'] = $ciclo === 'anual' ? 'anual' : 'mensal';
		}
		if (self::temColuna('valor_sugerido')) {
			$dados['valor_sugerido'] = $valor;
		}
	}

	/** @return string[] slugs de módulos internos, ou de produtos no plano legado */
	public function getSlugs(): array {
		if ($this->ehPlanoDeProduto()) {
			$permitidos = ProdutoModulo::slugs((string)$this->produto_slug);
			if ($this->temTodosModulos()) {
				return $permitidos;
			}
			$decoded = json_decode((string)$this->modulos, true);
			if (!is_array($decoded)) {
				return [];
			}
			if (empty($permitidos)) {
				return [];
			}
			$map = array_flip($permitidos);
			$out = [];
			foreach ($decoded as $s) {
				$s = (string)$s;
				if (isset($map[$s])) {
					$out[] = $s;
				}
			}
			return $out;
		}
		if ($this->temTodosModulos()) {
			return ProductModules::getSlugs();
		}
		$decoded = json_decode((string)$this->modulos, true);
		if (!is_array($decoded)) {
			return [];
		}
		$validos = array_flip(ProductModules::getSlugs());
		$out = [];
		foreach ($decoded as $s) {
			$s = (string)$s;
			if (isset($validos[$s])) {
				$out[] = $s;
			}
		}
		return $out;
	}

	/** Valor para gravar em clientes_assinantes.modulos_liberados */
	public function modulosParaEscola(): ?string {
		if ($this->ehPlanoDeProduto()) {
			$slug = trim((string)$this->produto_slug);
			return $slug !== '' ? json_encode([$slug], JSON_UNESCAPED_UNICODE) : null;
		}
		if ($this->temTodosModulos()) {
			return null;
		}
		$slugs = $this->getSlugs();
		return empty($slugs) ? null : json_encode($slugs, JSON_UNESCAPED_UNICODE);
	}
}
