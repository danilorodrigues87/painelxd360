<?php

namespace App\Common\Helpers;

use App\Model\Entity\SaasEmpresaXd360;
use App\Model\Entity\User as EntityUser;

class SaasEmpresaXd360Helper {

	public static function formatCnpj(?string $cnpj): string {
		$d = preg_replace('/\D+/', '', (string)($cnpj ?? ''));
		if (strlen($d) !== 14) {
			return trim((string)($cnpj ?? '')) ?: '—';
		}
		return substr($d, 0, 2).'.'.substr($d, 2, 3).'.'.substr($d, 5, 3).'/'
			.substr($d, 8, 4).'-'.substr($d, 12, 2);
	}

	public static function formatCpf(?string $cpf): string {
		$d = preg_replace('/\D+/', '', (string)($cpf ?? ''));
		if (strlen($d) !== 11) {
			return trim((string)($cpf ?? '')) ?: '—';
		}
		return substr($d, 0, 3).'.'.substr($d, 3, 3).'.'.substr($d, 6, 3).'-'.substr($d, 9, 2);
	}

	public static function cidadeUfTexto(?SaasEmpresaXd360 $emp): string {
		if (!$emp instanceof SaasEmpresaXd360) {
			return '';
		}
		$cidadeNome = trim((string)($emp->cidade_nome ?? ''));
		$uf = strtoupper(trim((string)($emp->uf ?? '')));
		if ($cidadeNome !== '' || $uf !== '') {
			return trim($cidadeNome.($uf !== '' ? '/'.$uf : ''));
		}
		return ContratoVariaveisBuilder::resolverCidadeUf(
			(int)($emp->cidade ?? 0),
			(int)($emp->estado ?? 0)
		);
	}

	public static function resolverEndereco(?SaasEmpresaXd360 $emp): string {
		if (!$emp instanceof SaasEmpresaXd360) {
			return 'endereço não informado';
		}
		return ContratoVariaveisBuilder::montarEnderecoEscola([
			'endereco' => $emp->endereco ?? '',
			'numero'   => $emp->numero ?? '',
			'bairro'   => $emp->bairro ?? '',
		], self::cidadeUfTexto($emp));
	}

	public static function resolverForo(?SaasEmpresaXd360 $emp): string {
		if (!$emp instanceof SaasEmpresaXd360) {
			return 'comarca da sede da LICENCIANTE';
		}
		$foro = trim((string)($emp->foro_comarca ?? ''));
		if ($foro !== '') {
			return $foro;
		}
		$cidadeUf = self::cidadeUfTexto($emp);
		return $cidadeUf !== '' ? $cidadeUf : 'comarca da sede da LICENCIANTE';
	}

	/**
	 * @return array{nome:string,cpf:string,rg:string,cargo:string,usuario_id:int}|null
	 */
	public static function resolverRepresentanteLegal(?SaasEmpresaXd360 $emp): ?array {
		if (!$emp instanceof SaasEmpresaXd360) {
			return null;
		}

		$cargo = trim((string)($emp->rep_cargo ?? '')) ?: 'Administrador';

		if (SaasEmpresaXd360::temColunaRepLegalUsuarioId()) {
			$uid = (int)($emp->rep_legal_usuario_id ?? 0);
			if ($uid > 0) {
				$user = EntityUser::getUserById($uid);
				if ($user instanceof EntityUser) {
					return [
						'nome'        => trim((string)$user->nome),
						'cpf'         => preg_replace('/\D+/', '', (string)($user->cpf ?? '')),
						'rg'          => trim((string)($user->rg ?? '')),
						'cargo'       => $cargo,
						'usuario_id'  => $uid,
					];
				}
			}
		}

		$nome = trim((string)($emp->rep_nome ?? ''));
		if ($nome === '') {
			return null;
		}

		return [
			'nome'       => $nome,
			'cpf'        => preg_replace('/\D+/', '', (string)($emp->rep_cpf ?? '')),
			'rg'         => trim((string)($emp->rep_rg ?? '')),
			'cargo'      => $cargo,
			'usuario_id' => 0,
		];
	}

	/** @return array{ok:bool,faltando:string[]} */
	public static function checarCompleto(?SaasEmpresaXd360 $emp): array {
		$faltando = [];
		if (!$emp instanceof SaasEmpresaXd360) {
			return ['ok' => false, 'faltando' => ['Cadastro da empresa XD360']];
		}
		$cnpj = preg_replace('/\D+/', '', (string)($emp->cnpj ?? ''));
		if (strlen($cnpj) !== 14 || !ConectCnpjHelper::validar($cnpj)) {
			$faltando[] = 'CNPJ da XD360';
		}
		if (trim((string)($emp->endereco ?? '')) === '') {
			$faltando[] = 'Endereço da XD360';
		}

		$rep = self::resolverRepresentanteLegal($emp);
		if (!$rep || trim($rep['nome']) === '') {
			$faltando[] = 'Representante legal XD360 (usuário Master)';
		} elseif (strlen($rep['cpf']) !== 11) {
			$faltando[] = 'CPF do representante legal XD360 (cadastre no usuário Master)';
		}

		return ['ok' => empty($faltando), 'faltando' => $faltando];
	}

	public static function defaults(): SaasEmpresaXd360 {
		$e = new SaasEmpresaXd360();
		$e->razao_social = 'XD360 Tecnologia Ltda.';
		$e->nome_fantasia = 'XD360';
		$e->email = 'contato@xd360.com.br';
		$e->site = 'https://xd360.com.br';
		$e->rep_cargo = 'Administrador';
		return $e;
	}

	public static function getOuDefaults(): SaasEmpresaXd360 {
		$emp = SaasEmpresaXd360::get();
		return $emp instanceof SaasEmpresaXd360 ? $emp : self::defaults();
	}
}
