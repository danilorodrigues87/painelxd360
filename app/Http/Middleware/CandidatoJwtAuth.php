<?php

namespace App\Http\Middleware;

use App\Common\Helpers\ConectCandidatoAuthHelper;
use App\Model\Entity\User;
use App\Model\Entity\CjCandidato;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class CandidatoJwtAuth {

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
		$user = $id > 0 ? User::getUserById($id) : null;
		if (!$user instanceof User || !ConectCandidatoAuthHelper::podeAcessarConect($user)) {
			throw new \Exception('Acesso apenas para candidatos', 403);
		}

		$candidato = ConectCandidatoAuthHelper::resolverPerfil($user);
		if (!$candidato instanceof CjCandidato) {
			throw new \Exception('Perfil de candidato não encontrado', 403);
		}

		$request->user = $user;
		$request->candidato = $candidato;
		$request->jwtClaims = $decode;
		return $next($request);
	}
}
