<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class ClientesAssinantes {

	public const TABELA = 'clientes_assinantes';

	public $id;
	public $id_admin;
	public $ativo;
	public $nome;
	public $cpf_cnpj;
	public $email;
	public $site;
	public $logo;
	public $instagram;
	public $telefone;
	public $youtube;
	public $endereco;
	public $numero;
	public $bairro;
	public $estado;
	public $cidade;
	public $uf;
	public $cidade_nome;
	public $cep;
	public $modulos_liberados;
	public $plan_id;
	public $valor_mensal_custom;
	public $dia_vencimento_assinatura = 10;
	public $assinatura_status = 'ativa';
	public $trial_ate;
	public $assinatura_proximo_vencimento;
	public $modelo_certificado;
	public $modelo_contrato_html;
	public $certificado_frase_conclusao;
	public $catalogo_cti = 0;
	public $slug;
	public $dominio_custom;
	public $dominio_verificado = 0;

	public static function temColunaSlug(): bool {
		return self::temColuna('slug');
	}

	public static function temColunaDominioCustom(): bool {
		return self::temColuna('dominio_custom');
	}

	public static function temColunaPlanId(): bool {
		return self::temColuna('plan_id');
	}

	public static function temColunaValorMensalCustom(): bool {
		return self::temColuna('valor_mensal_custom');
	}

	public static function temColunaTrialAte(): bool {
		return self::temColuna('trial_ate');
	}

	public static function temColunasAssinatura(): bool {
		return self::temColuna('dia_vencimento_assinatura');
	}

	public static function temColunaModeloCertificado(): bool {
		return self::temColuna('modelo_certificado');
	}

	public static function temColunaModeloContrato(): bool {
		return self::temColuna('modelo_contrato_html');
	}

	public static function temColunaCertificadoFrase(): bool {
		return self::temColuna('certificado_frase_conclusao');
	}

	public static function temColunaCatalogoCti(): bool {
		return self::temColuna('catalogo_cti');
	}

	public static function temColunaEnderecoTexto(): bool {
		return self::temColuna('uf') && self::temColuna('cidade_nome');
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
			$t = self::TABELA;
			$row = (new Database($t))->execute(
				"SHOW COLUMNS FROM {$t} LIKE '{$coluna}'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache[$coluna] = !empty($row);
		} catch (\Throwable $e) {
			$cache[$coluna] = false;
		}
		return $cache[$coluna];
	}

	public static function getBySlug(string $slug): ?self {
		$slug = \App\Common\Helpers\SlugHelper::sanitize($slug);
		if ($slug === '' || !self::temColunaSlug()) {
			return null;
		}
		$row = self::getClientes('slug = "'.addslashes($slug).'"', null, '1')
			->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByDominioCustom(string $host): ?self {
		if (!self::temColunaDominioCustom()) {
			return null;
		}
		$host = strtolower(trim($host));
		$host = preg_replace('/^www\./', '', $host) ?? $host;
		if ($host === '') {
			return null;
		}
		$row = self::getClientes(
			'dominio_custom IS NOT NULL AND dominio_custom != "" AND LOWER(dominio_custom) = "'.addslashes($host).'"',
			null,
			'1'
		)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function getByDominioPainel(string $host): ?self {
		if (!self::temColunaDominioCustom()) {
			return null;
		}
		$host = strtolower(trim($host));
		if (!str_starts_with($host, 'painel.')) {
			return null;
		}
		$apex = substr($host, 7);
		return self::getByDominioCustom($apex);
	}

	public static function getClienteById($id) {
		$id = (int)$id;
		if ($id <= 0) {
			return false;
		}
		$ob = self::getClientes('id = '.$id)->fetchObject(self::class);
		if ($ob instanceof self) {
			return $ob;
		}
		return self::getClientes('id_admin = '.$id)->fetchObject(self::class);
	}

	/** @deprecated use getClienteById */
	public static function getEscolaById($id) {
		return self::getClienteById($id);
	}

	public static function getClientes($where = null, $order = null, $limit = null, $fields = '*') {
		return (new Database(self::TABELA))->select($where, $order, $limit, $fields);
	}

	/** @deprecated use getClientes */
	public static function getEscolas($where = null, $order = null, $limit = null, $fields = '*') {
		return self::getClientes($where, $order, $limit, $fields);
	}

	public static function isAtivaValor($ativo): bool {
		if ($ativo === true || $ativo === 1 || $ativo === '1') {
			return true;
		}
		if (is_string($ativo)) {
			$v = strtolower(trim($ativo));
			return $v === 's' || $v === 'sim' || $v === 'ativo' || $v === 'true';
		}
		return false;
	}

	public function isAtiva(): bool {
		return self::isAtivaValor($this->ativo ?? null);
	}

	private function dadosPersistencia(): array {
		$dados = [
			'nome'               => (string)($this->nome ?? ''),
			'id_admin'           => (int)($this->id_admin ?: 0),
			'ativo'              => self::isAtivaValor($this->ativo ?? null) ? 's' : 'n',
			'cpf_cnpj'           => (string)($this->cpf_cnpj ?? ''),
			'email'              => (string)($this->email ?? ''),
			'site'               => (string)($this->site ?? ''),
			'logo'               => (string)($this->logo ?? ''),
			'youtube'            => $this->youtube !== null && $this->youtube !== '' ? (string)$this->youtube : null,
			'instagram'          => $this->instagram !== null && $this->instagram !== '' ? (string)$this->instagram : null,
			'telefone'           => (string)($this->telefone ?? ''),
			'endereco'           => (string)($this->endereco ?? ''),
			'numero'             => (string)($this->numero ?? ''),
			'bairro'             => (string)($this->bairro ?? ''),
			'estado'             => (int)($this->estado ?: 0),
			'cidade'             => (int)($this->cidade ?: 0),
			'cep'                => (string)($this->cep ?? ''),
			'modulos_liberados'  => $this->modulos_liberados,
		];
		if (self::temColunaSlug()) {
			$dados['slug'] = $this->slug !== null && trim((string)$this->slug) !== ''
				? trim((string)$this->slug)
				: null;
		}
		if (self::temColunaDominioCustom()) {
			$dom = trim(strtolower((string)($this->dominio_custom ?? '')));
			$dados['dominio_custom'] = $dom !== '' ? $dom : null;
			$dados['dominio_verificado'] = !empty($this->dominio_verificado) ? 1 : 0;
		}
		return $dados;
	}

	public function cadastrar() {
		$obDatabase = new Database(self::TABELA);
		$dados = $this->dadosPersistencia();
		if (self::temColunaPlanId()) {
			$dados['plan_id'] = $this->plan_id !== null && $this->plan_id !== ''
				? (int)$this->plan_id
				: null;
		}
		if (self::temColunaValorMensalCustom()) {
			$custom = $this->valor_mensal_custom;
			$dados['valor_mensal_custom'] = ($custom !== null && $custom !== '' && (float)$custom > 0)
				? round((float)$custom, 2)
				: null;
		}
		if (self::temColunasAssinatura()) {
			$dados['dia_vencimento_assinatura'] = max(1, min(28, (int)($this->dia_vencimento_assinatura ?: 10)));
			$dados['assinatura_status'] = in_array((string)($this->assinatura_status ?? ''), ['ativa', 'suspensa', 'trial'], true)
				? (string)$this->assinatura_status
				: 'ativa';
			$dados['assinatura_proximo_vencimento'] = $this->assinatura_proximo_vencimento ?: null;
		}
		if (self::temColunaTrialAte()) {
			$dados['trial_ate'] = !empty($this->trial_ate) ? (string)$this->trial_ate : null;
		}
		if (self::temColunaModeloCertificado()) {
			$dados['modelo_certificado'] = $this->modelo_certificado ?: null;
		}
		if (self::temColunaModeloContrato()) {
			$dados['modelo_contrato_html'] = $this->modelo_contrato_html !== null && trim((string)$this->modelo_contrato_html) !== ''
				? (string)$this->modelo_contrato_html
				: null;
		}
		if (self::temColunaCertificadoFrase()) {
			$dados['certificado_frase_conclusao'] = $this->certificado_frase_conclusao !== null && trim((string)$this->certificado_frase_conclusao) !== ''
				? mb_substr(trim((string)$this->certificado_frase_conclusao), 0, 255)
				: null;
		}
		if (self::temColunaCatalogoCti()) {
			$dados['catalogo_cti'] = !empty($this->catalogo_cti) ? 1 : 0;
		}
		$this->id = (int)$obDatabase->insert($dados);

		if ($this->id > 0 && (int)$this->id_admin !== $this->id) {
			$this->id_admin = $this->id;
			(new Database(self::TABELA))->update('id = '.$this->id, [
				'id_admin' => $this->id,
			]);
		}

		return true;
	}

	public function atualizar() {
		$dados = $this->dadosPersistencia();
		$dados['id_admin'] = (int)($this->id_admin ?: $this->id);
		if (self::temColunaPlanId()) {
			$dados['plan_id'] = $this->plan_id !== null && $this->plan_id !== ''
				? (int)$this->plan_id
				: null;
		}
		if (self::temColunaValorMensalCustom()) {
			$custom = $this->valor_mensal_custom;
			$dados['valor_mensal_custom'] = ($custom !== null && $custom !== '' && (float)$custom > 0)
				? round((float)$custom, 2)
				: null;
		}
		if (self::temColunasAssinatura()) {
			$dados['dia_vencimento_assinatura'] = max(1, min(28, (int)($this->dia_vencimento_assinatura ?: 10)));
			$dados['assinatura_status'] = in_array((string)($this->assinatura_status ?? ''), ['ativa', 'suspensa', 'trial'], true)
				? (string)$this->assinatura_status
				: 'ativa';
			$dados['assinatura_proximo_vencimento'] = $this->assinatura_proximo_vencimento ?: null;
		}
		if (self::temColunaTrialAte()) {
			$dados['trial_ate'] = !empty($this->trial_ate) ? (string)$this->trial_ate : null;
		}
		if (self::temColunaModeloCertificado()) {
			$dados['modelo_certificado'] = $this->modelo_certificado ?: null;
		}
		if (self::temColunaModeloContrato()) {
			$dados['modelo_contrato_html'] = $this->modelo_contrato_html !== null && trim((string)$this->modelo_contrato_html) !== ''
				? (string)$this->modelo_contrato_html
				: null;
		}
		if (self::temColunaCertificadoFrase()) {
			$dados['certificado_frase_conclusao'] = $this->certificado_frase_conclusao !== null && trim((string)$this->certificado_frase_conclusao) !== ''
				? mb_substr(trim((string)$this->certificado_frase_conclusao), 0, 255)
				: null;
		}
		if (self::temColunaCatalogoCti()) {
			$dados['catalogo_cti'] = !empty($this->catalogo_cti) ? 1 : 0;
		}
		return (new Database(self::TABELA))->update('id = '.(int)$this->id, $dados);
	}

	public function atualizarOperacional(): bool {
		$dados = [
			'email'     => (string)($this->email ?? ''),
			'telefone'  => (string)($this->telefone ?? ''),
			'site'      => (string)($this->site ?? ''),
			'logo'      => (string)($this->logo ?? ''),
			'youtube'   => $this->youtube !== null && $this->youtube !== '' ? (string)$this->youtube : null,
			'instagram' => $this->instagram !== null && $this->instagram !== '' ? (string)$this->instagram : null,
			'endereco'  => (string)($this->endereco ?? ''),
			'numero'    => (string)($this->numero ?? ''),
			'bairro'    => (string)($this->bairro ?? ''),
			'estado'    => (int)($this->estado ?: 0),
			'cidade'    => (int)($this->cidade ?: 0),
			'cep'       => (string)($this->cep ?? ''),
		];
		if (self::temColunaEnderecoTexto()) {
			$uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($this->uf ?? '')), 0, 2));
			$dados['uf'] = $uf !== '' ? $uf : null;
			$dados['cidade_nome'] = trim((string)($this->cidade_nome ?? '')) ?: null;
		}
		if (self::temColunaModeloCertificado()) {
			$dados['modelo_certificado'] = $this->modelo_certificado ?: null;
		}
		return (bool)(new Database(self::TABELA))->update('id = '.(int)$this->id, $dados);
	}

	public static function salvarModeloContrato(int $idCliente, ?string $html): bool {
		if (!self::temColunaModeloContrato()) {
			return false;
		}
		$valor = ($html !== null && trim($html) !== '') ? $html : null;
		return (bool)(new Database(self::TABELA))->update('id = '.(int)$idCliente, [
			'modelo_contrato_html' => $valor,
		]);
	}

	public static function salvarFraseCertificado(int $idCliente, ?string $frase): bool {
		if (!self::temColunaCertificadoFrase()) {
			return false;
		}
		$valor = ($frase !== null && trim($frase) !== '') ? mb_substr(trim($frase), 0, 255) : null;
		return (bool)(new Database(self::TABELA))->update('id = '.(int)$idCliente, [
			'certificado_frase_conclusao' => $valor,
		]);
	}

	public function excluir() {
		return (new Database(self::TABELA))->delete('id = '.(int)$this->id);
	}
}
