<?php

namespace App\Http\Middleware;

use App\Common\Helpers\EditorAuthHelper;
use App\Model\Entity\User;
use App\Model\Entity\ClientesAssinantes;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class EditorJwtAuth {

	public function handle($request, $next) {
		$headers = $request->getHeaders();
		$auth = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
		$jwt = is_string($auth) ? preg_replace('/^Bearer\s+/i', '', $auth) : '';
		if ($jwt === '') {
			throw new \Exception('Token ausente', 401);
		}
		try {
			$decode = (array)JWT::decode($jwt, new Key(getenv('JWT_KEY') ?: 'change-me', 'HS256'));
		} catch (\Throwable $e) {
			throw new \Exception('Token inválido', 401);
		}

		$id = (int)($decode['sub'] ?? 0);
		$email = (string)($decode['email'] ?? '');
		$obUser = $id > 0 ? User::getUserById($id) : ($email !== '' ? User::getUserByEmail($email) : null);
		if (!$obUser instanceof User) {
			throw new \Exception('Usuário inválido', 401);
		}

		$nivel = (string)($obUser->nivel ?? '');
		if ($nivel === 'Cliente') {
			throw new \Exception('Acesso apenas para editores', 403);
		}

		$roleClaim = (string)($decode['role'] ?? '');
		$staffOk = in_array($nivel, ['Funcionario', 'Diretor', 'Professor'], true);
		if ($roleClaim !== 'editor' && !$staffOk) {
			throw new \Exception('Acesso apenas para editores', 403);
		}

		$idAdmin = EditorAuthHelper::resolveIdAdmin($obUser, $decode);
		if ($idAdmin <= 0) {
			throw new \Exception('Tenant inválido', 403);
		}

		$escola = ClientesAssinantes::getEscolaById($idAdmin);
		if (!$escola || !$escola->isAtiva()) {
			throw new \Exception('Escola inativa', 403);
		}

		$request->user = $obUser;
		$request->editorIdAdmin = $idAdmin;
		$request->editorIdCurso = !empty($decode['id_curso']) ? (int)$decode['id_curso'] : null;
		$request->jwtClaims = $decode;
		return $next($request);
	}
}
