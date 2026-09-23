<?php

namespace App\Model\Entity;

use App\Model\Db\Database;

class SaasEmpresaXd360 {

	public const TABELA = 'saas_empresaxd360';

	public $id = 1;
	public $razao_social;
	public $nome_fantasia;
	public $cnpj;
	public $endereco;
	public $numero;
	public $bairro;
	public $cep;
	public $estado;
	public $cidade;
	public $email;
	public $telefone;
	public $site;
	public $rep_legal_usuario_id;
	public $rep_nome;
	public $rep_cpf;
	public $rep_rg;
	public $rep_cargo = 'Administrador';
	public $foro_comarca;
	public $atualizado_em;

	public static function tabelaExiste(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		try {
			$t = self::TABELA;
			$row = (new Database($t))->execute(
				"SHOW TABLES LIKE '{$t}'"
			)->fetch(\PDO::FETCH_NUM);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function temColunaRepLegalUsuarioId(): bool {
		static $cache = null;
		if ($cache !== null) {
			return $cache;
		}
		if (!self::tabelaExiste()) {
			$cache = false;
			return false;
		}
		try {
			$t = self::TABELA;
			$row = (new Database($t))->execute(
				"SHOW COLUMNS FROM {$t} LIKE 'rep_legal_usuario_id'"
			)->fetch(\PDO::FETCH_ASSOC);
			$cache = !empty($row);
		} catch (\Throwable $e) {
			$cache = false;
		}
		return $cache;
	}

	public static function get(): ?self {
		if (!self::tabelaExiste()) {
			return null;
		}
		$row = (new Database(self::TABELA))->select('id = 1', null, 1)->fetchObject(self::class);
		return $row instanceof self ? $row : null;
	}

	public static function salvar(self $dados): bool {
		if (!self::tabelaExiste()) {
			return false;
		}
		$db = new Database(self::TABELA);
		$payload = [
			'razao_social'  => (string)($dados->razao_social ?? ''),
			'nome_fantasia' => (string)($dados->nome_fantasia ?? ''),
			'cnpj'          => self::normDoc($dados->cnpj ?? null),
			'endereco'      => (string)($dados->endereco ?? ''),
			'numero'        => (string)($dados->numero ?? ''),
			'bairro'        => (string)($dados->bairro ?? ''),
			'cep'           => preg_replace('/\D+/', '', (string)($dados->cep ?? '')),
			'estado'        => (int)($dados->estado ?? 0) ?: null,
			'cidade'        => (int)($dados->cidade ?? 0) ?: null,
			'email'         => (string)($dados->email ?? ''),
			'telefone'      => (string)($dados->telefone ?? ''),
			'site'          => (string)($dados->site ?? ''),
			'rep_cargo'     => trim((string)($dados->rep_cargo ?? '')) ?: 'Administrador',
			'foro_comarca'  => (string)($dados->foro_comarca ?? ''),
		];
		if (self::temColunaRepLegalUsuarioId()) {
			$uid = (int)($dados->rep_legal_usuario_id ?? 0);
			$payload['rep_legal_usuario_id'] = $uid > 0 ? $uid : null;
		} else {
			$payload['rep_nome'] = (string)($dados->rep_nome ?? '');
			$payload['rep_cpf'] = self::normDoc($dados->rep_cpf ?? null, 11);
			$payload['rep_rg'] = preg_replace('/\D+/', '', (string)($dados->rep_rg ?? ''));
		}
		$exist = $db->select('id = 1', null, 1)->fetch(\PDO::FETCH_ASSOC);
		if ($exist) {
			return (bool)$db->update('id = 1', $payload);
		}
		$payload['id'] = 1;
		return (bool)$db->insert($payload);
	}

	private static function normDoc($val, int $len = 14): ?string {
		$d = preg_replace('/\D+/', '', (string)($val ?? ''));
		return $d !== '' ? $d : null;
	}
}
