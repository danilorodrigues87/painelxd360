<?php

namespace App\Common\Helpers;

use App\Model\Entity\CjCandidato;
use App\Model\Entity\User;

/**
 * Vincula usuário (Candidato ou aluno LMS Cliente) ao perfil cj_candidatos.
 */
class ConectCandidatoAuthHelper {

	public static function podeAcessarConect(User $user): bool {
		$nivel = (string)($user->nivel ?? '');
		return in_array($nivel, ['Candidato', 'Cliente'], true);
	}

	public static function mensagemLoginIncorreto(User $user, string $contexto): string {
		$nivel = (string)($user->nivel ?? '');
		if ($contexto === 'empresa') {
			if ($nivel === 'Candidato' || $nivel === 'Cliente') {
				return 'Esta conta é de candidato/aluno. Selecione a aba Candidato acima.';
			}
			return 'Esta conta não é de empresa parceira. Use a aba correta ou cadastre sua empresa.';
		}
		if ($nivel === 'Empresa') {
			return 'Esta conta é de empresa parceira. Selecione a aba Empresa acima.';
		}
		return 'Tipo de conta não habilitado no Conecta Jovem. Cadastre-se como candidato ou entre em contato com sua escola.';
	}

	/**
	 * Resolve ou cria perfil cj_candidatos para login no portal.
	 */
	public static function resolverPerfil(User $user): ?CjCandidato {
		if (!CjCandidato::tabelaExiste() || !self::podeAcessarConect($user)) {
			return null;
		}

		$candidato = CjCandidato::getByUsuarioId((int)$user->id);
		if ($candidato instanceof CjCandidato) {
			return $candidato;
		}

		$email = strtolower(trim((string)($user->email ?? '')));
		if ($email !== '') {
			$porEmail = CjCandidato::getByEmail($email);
			if ($porEmail instanceof CjCandidato) {
				if (empty($porEmail->id_usuario)) {
					CjCandidato::atualizar((int)$porEmail->id, ['id_usuario' => (int)$user->id]);
					return CjCandidato::getById((int)$porEmail->id);
				}
				if ((int)$porEmail->id_usuario === (int)$user->id) {
					return $porEmail;
				}
			}
		}

		if (($user->nivel ?? '') !== 'Cliente') {
			return null;
		}

		$idAdmin = ConectJovemLeadRouter::resolverIdAdmin(['id_usuario' => (int)$user->id]);
		$insert = [
			'id_usuario' => (int)$user->id,
			'id_admin'   => $idAdmin,
			'tipo'       => 'aluno',
			'nome'       => trim((string)($user->nome ?? 'Aluno')),
			'email'      => $email,
			'whatsapp'   => preg_replace('/\D+/', '', (string)($user->whatsapp ?? '')),
			'status'     => 'ativo',
		];
		if (!empty($user->nascimento)) {
			$nascVal = ConectIdadeHelper::validarElegibilidade((string)$user->nascimento);
			if ($nascVal['ok']) {
				$nasc = ConectIdadeHelper::normalizarNascimento((string)$user->nascimento);
				if ($nasc !== null) {
					$insert['nascimento'] = $nasc;
				}
			}
		}
		$candidatoId = CjCandidato::inserir($insert);

		return $candidatoId ? CjCandidato::getById($candidatoId) : null;
	}
}
