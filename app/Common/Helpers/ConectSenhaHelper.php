<?php

namespace App\Common\Helpers;

use App\Model\Entity\User;

class ConectSenhaHelper {

	/**
	 * @param array<string,mixed> $post
	 * @return array{ok:bool,message:string,code?:int}
	 */
	public static function alterar(User $user, array $post, int $minLen = 6): array {
		$atual = (string)($post['currentPassword'] ?? $post['senha_atual'] ?? '');
		$nova = (string)($post['newPassword'] ?? $post['senha'] ?? $post['password'] ?? '');
		$confirma = (string)($post['confirmPassword'] ?? $post['senha_confirma'] ?? $nova);

		if ($atual === '' || $nova === '') {
			return ['ok' => false, 'message' => 'Informe a senha atual e a nova senha.', 'code' => 400];
		}
		if (!password_verify($atual, (string)$user->senha)) {
			return ['ok' => false, 'message' => 'Senha atual incorreta.', 'code' => 400];
		}
		if (strlen($nova) < $minLen) {
			return [
				'ok'      => false,
				'message' => 'A nova senha deve ter pelo menos '.$minLen.' caracteres.',
				'code'    => 400,
			];
		}
		if ($nova !== $confirma) {
			return ['ok' => false, 'message' => 'A confirmação da nova senha não confere.', 'code' => 400];
		}
		if (password_verify($nova, (string)$user->senha)) {
			return ['ok' => false, 'message' => 'A nova senha deve ser diferente da atual.', 'code' => 400];
		}

		$user->senha = password_hash($nova, PASSWORD_DEFAULT);
		if (!$user->resetSenha()) {
			return ['ok' => false, 'message' => 'Não foi possível alterar a senha.', 'code' => 500];
		}

		return ['ok' => true, 'message' => 'Senha alterada com sucesso.'];
	}
}
